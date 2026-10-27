<?php

declare(strict_types=1);

namespace ThreadSafe\Install;

use Closure;
use Throwable;
use ThreadSafe\Core\Environment;
use ThreadSafe\Core\OpenBasedir;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Engine\EngineException;
use ThreadSafe\Engine\Executor;
use ThreadSafe\Engine\Run;
use ThreadSafe\Engine\RunStatus;
use ThreadSafe\Engine\RunStore;
use ThreadSafe\Profile\Check;
use ThreadSafe\Profile\CheckLevel;
use ThreadSafe\Web\AccessKey;
use ThreadSafe\Web\Cleanup;
use ThreadSafe\Web\Html;
use ThreadSafe\Web\Request;
use ThreadSafe\Web\Response;
use ThreadSafe\Web\Sessions;

/**
 * The web installer (docs/PHASE7.md 3.3): server-rendered pages for a person who has uploaded `install.php` to the document root of a
 * host and wants ThreadSafe there. One address, the steps selected by `?step=`; every request that changes something is a POST with a
 * CSRF token.
 *
 * Nothing happens without the key file `install-key.php` ({@see AccessKey}). Steps: key → check and place → install (the
 * {@see InstallRun} in ticks, one per request, from a few lines of JavaScript or a button) → done, where the installer deletes itself
 * and hands the person on to the setup page of the installed interface.
 */
final class Wizard
{
    public const string COOKIE = 'threadsafe_install';

    public const string KEY_FILE = 'install-key.php';

    public const string STATE = '.threadsafe-install';

    private const array STEPS = ['check' => 'Check', 'run' => 'Install', 'done' => 'Done'];

    private readonly string $baseDirectory;

    private readonly string $stateDirectory;

    private readonly AccessKey $key;

    private readonly Sessions $sessions;

    private string $nonce = '';

    /**
     * @param string                 $script  absolute path of `install.php`
     * @param Closure(): int|null    $clock   for tests
     * @param Closure(): Budget|null $budgets the budget of a tick; default: a share of the request time limit
     */
    public function __construct(private readonly string $script, ?Closure $clock = null, private readonly ?Closure $budgets = null)
    {
        $this->baseDirectory = rtrim(str_replace('\\', '/', dirname($script)), '/');
        $this->stateDirectory = $this->baseDirectory . '/' . self::STATE;
        $this->key = new AccessKey($this->baseDirectory . '/' . self::KEY_FILE, $this->stateDirectory . '/attempts.json', $this->stateDirectory . '/access.log', $clock);
        $this->sessions = new Sessions($this->stateDirectory . '/sessions', $clock);
    }

    public function handle(Request $request): Response
    {
        $this->nonce = bin2hex(random_bytes(9));
        try {
            return $this->secure($this->route($request));
        } catch (Throwable $e) {
            return $this->secure($this->page('Something went wrong', '<p class="fail">' . Html::e($e->getMessage()) . '</p><p class="help">Reload the page. If this stays, check that PHP may write in <code>' . Html::e($this->baseDirectory) . '</code>.</p>', 500));
        }
    }

    // ------------------------------------------------------------------------------------------------- routing

    private function route(Request $request): Response
    {
        if (!$this->key->exists()) {
            return $this->page('No access key', '<p>This installer does nothing until you give it a key.</p><p>Create a file named <code>' . self::KEY_FILE . '</code> next to <code>' . Html::e(basename($this->script)) . '</code> with this content (choose your own secret of at least ' . AccessKey::MIN_LENGTH . ' characters):</p><pre><code>&lt;?php return \'a secret you choose\';</code></pre><p class="help">It is a PHP file on purpose: a plain text file would be served to anyone who asks for it. Then reload this page.</p>', 403);
        }
        if (($problem = $this->key->problem()) !== null) {
            return $this->page('The access key cannot be used', '<p class="fail">' . Html::e($problem) . '</p>', 403);
        }

        $token = $request->cookie(self::COOKIE);
        $session = $this->sessions->load($token);
        if ($session === null || $token === null) {
            return $request->isPost() && $request->post('action') === 'login' ? $this->login($request) : $this->loginPage($request);
        }
        if ($request->isPost() && !hash_equals((string) $session['csrf'], (string) $request->post('csrf'))) {
            return $this->page('The page has expired', '<p>The form was not accepted (wrong or missing token). <a href="?">Start again</a>.</p>', 403);
        }

        $action = $request->isPost() ? (string) $request->post('action') : '';

        return match (true) {
            $action === 'install' => $this->install($request, $token, $session),
            $action === 'tick' => $this->tick($request, $session),
            $action === 'retry' => $this->retry($session),
            $action === 'cleanup' => $this->cleanup($session),
            default => match ($request->query('step') ?? 'check') {
                'run' => $this->runPage($session),
                'done' => $this->donePage($session),
                default => $this->checkPage($session),
            },
        };
    }

    // ------------------------------------------------------------------------------------------------- login

    private function loginPage(Request $request, string $error = ''): Response
    {
        $warning = $this->plainHttp($request)
            ? '<p class="box fail"><strong>This connection is not encrypted.</strong> The key crosses the network in clear text. Use https if the host offers it; otherwise confirm below that you accept this.</p><label><input type="checkbox" name="plain" value="1"> I understand and accept this.</label>'
            : '';

        return $this->page('Access key', ($error !== '' ? '<p class="fail">' . Html::e($error) . '</p>' : '')
            . '<form method="post"><input type="hidden" name="action" value="login"><label for="key">Key from <code>' . self::KEY_FILE . '</code></label><input id="key" type="password" name="key" autocomplete="off" autofocus>' . $warning . '<button>Continue</button></form>', $error !== '' ? 403 : 200);
    }

    private function login(Request $request): Response
    {
        if ($this->plainHttp($request) && $request->post('plain') !== '1') {
            return $this->loginPage($request, 'Confirm that you accept the unencrypted connection, or use https.');
        }
        \ThreadSafe\Web\StateDirectory::prepare($this->stateDirectory);
        $wrong = $this->key->check((string) $request->post('key'), $request->remoteAddress);
        if ($wrong !== null) {
            return $this->loginPage($request, $wrong);
        }
        [$token, $data] = $this->sessions->create();
        // an installation that was started and not finished goes on where it was
        $unfinished = $this->unfinishedRun();
        if ($unfinished !== null) {
            $data['runId'] = $unfinished->id;
        }
        $this->sessions->save($token, $data);

        $path = $request->path === '' ? '/' : substr($request->path, 0, (int) strrpos($request->path, '/') + 1);

        return Response::redirect($unfinished !== null ? '?step=run' : '?step=check')
            ->withHeader('Set-Cookie: ' . self::COOKIE . '=' . $token . '; Path=' . $path . '; HttpOnly; SameSite=Strict' . ($request->https ? '; Secure' : ''));
    }

    private function plainHttp(Request $request): bool
    {
        return !$request->https && !$request->isLocal();
    }

    // ------------------------------------------------------------------------------------------------- check and place

    /**
     * What this host allows and what the release needs, as one list.
     *
     * @return list<Check>
     */
    private function checks(?InstallPackage $package): array
    {
        $checks = Environment::runtime();
        if ($package !== null) {
            array_push($checks, ...Environment::requirements($package->manifest->version, $package->manifest->php, $package->manifest->extensions));
        }
        if (($problem = Places::documentRootProblem($this->baseDirectory)) !== null) {
            $checks[] = Check::failure('Document root', $problem);
        }
        $placed = array_filter(Places::candidates($this->baseDirectory), static fn (array $place): bool => $place['usable']);
        if ($placed === []) {
            $checks[] = Check::failure('Home', 'PHP cannot make a directory for ThreadSafe next to, below or in an allowed directory of this one.' . (OpenBasedir::restricted() ? ' This host restricts PHP to a few directories (open_basedir: ' . implode(', ', OpenBasedir::allowed()) . ').' : '') . ' Ask your host to make this directory writable for PHP' . (OpenBasedir::restricted() ? ' and to allow a directory outside the web root' : '') . ', or give a directory that PHP may use and write in (below).');
        }

        return $checks;
    }

    /**
     * @param array<string, mixed> $session
     */
    private function checkPage(array $session, string $error = ''): Response
    {
        try {
            $package = InstallPackage::open($this->script);
        } catch (InstallException $e) {
            return $this->page('This installer is damaged', '<p class="fail">' . Html::e($e->getMessage()) . '</p><p>Upload <code>install.php</code> again, and compare its SHA-256 with the one on the release page.</p>', 500, 'check');
        }
        $checks = $this->checks($package);
        $blocked = array_filter($checks, static fn (Check $check): bool => $check->level === CheckLevel::Failure) !== [];

        $items = '';
        foreach ($checks as $check) {
            if ($check->level === CheckLevel::Ok && !$blocked) {
                continue;
            }
            $label = ['ok' => 'OK', 'info' => 'Note', 'warning' => 'Warning', 'failure' => 'Problem'][$check->level->value];
            $items .= '<li class="' . ($check->level === CheckLevel::Failure ? 'error' : ($check->level === CheckLevel::Warning ? 'warning' : '')) . '"><strong>' . $label . ':</strong> ' . Html::e($check->subject . ': ' . $check->message) . '</li>';
        }
        $body = ($error !== '' ? '<p class="fail">' . Html::e($error) . '</p>' : '')
            . '<p>This installs ThreadSafe <strong>' . Html::e($package->manifest->version) . '</strong> into <code>' . Html::e($this->baseDirectory) . '</code>: the small <code>index.php</code> goes here, the program goes into a home directory of its own.</p>'
            . '<h3>This host</h3><ul class="checks">' . $items . '</ul>'
            . ($blocked ? '<p class="fail">Fix the problems above, then <a href="?step=check">check again</a>.</p>' : $this->placeForm((string) $session['csrf']));

        return $this->page('Check and place', $body, 200, 'check');
    }

    /**
     * The choice of the place as tiles: one box for each, with its name, what it means and the path; what belongs to one choice (the confirmation for
     * the weaker one) stands inside its tile.
     */
    private function placeForm(string $csrf): string
    {
        $tiles = '';
        $first = true;
        foreach (Places::candidates($this->baseDirectory) as $place) {
            [$name, $badge, $text] = match ($place['kind']) {
                'inside' => ['Below the document root', ['weak', 'Weaker choice'], 'Closed by an <code>.htaccess</code>, which only Apache reads. On nginx and IIS the program would be reachable from outside unless you add a rule that denies this directory.'],
                'allowed' => ['In a directory this host allows', ['rec', 'Recommended'], 'This host lets PHP use only some directories (<code>open_basedir</code>). This one lies outside the web root: nothing of the program can be fetched.'],
                default => ['Next to the document root', ['rec', 'Recommended'], 'Outside the web root: nothing of the program can be fetched.'],
            };
            $extra = $place['kind'] === 'inside'
                ? '<label class="confirm"><input type="checkbox" name="inside_ok" value="1"> I run Apache, or I have added a rule that denies this directory.</label>'
                : '';
            $tiles .= $this->tile($place['id'], $name, $badge, $place['path'], $text, $place['usable'], $place['usable'] && $first, $place['reason'], $extra);
            $first = $first && !$place['usable'];
        }
        $tiles .= $this->tile('custom', 'Another directory', null, '', 'A full path, new or empty, that PHP may use and write in. If it lies below the document root, the confirmation in the tile above is needed as well.', true, $first, '', '<input type="text" name="custom" aria-label="Path of the directory" autocomplete="off" placeholder="/home/user/threadsafe">');

        return '<h3>Where the program goes</h3>' . $this->form('<div class="tiles">' . $tiles . '</div><button name="action" value="install">Install</button>', $csrf);
    }

    /**
     * One tile of the choice of the place. The radio button and its label cover the whole tile; what is inside the tile and must be reached by itself
     * (a checkbox, a text field) lies above that.
     *
     * @param array{string, string}|null $badge a CSS class and the text of the label next to the name
     */
    private function tile(string $id, string $name, ?array $badge, string $path, string $text, bool $usable, bool $checked, string $reason, string $extra): string
    {
        // a place that is not possible has no radio button and no badge: it is shown so that the person sees why it is not offered
        $head = $usable
            ? '<label class="pick"><input type="radio" name="place" value="' . Html::e($id) . '"' . ($checked ? ' checked' : '') . '> <span class="name">' . Html::e($name) . '</span>'
                . ($badge !== null ? ' <span class="badge ' . $badge[0] . '">' . Html::e($badge[1]) . '</span>' : '') . '</label>'
            : '<span class="pick"><span class="name">' . Html::e($name) . '</span> <span class="badge off">Not possible</span></span>';

        return '<div class="tile' . ($usable ? '' : ' off') . '">' . $head
            . ($path !== '' ? '<code class="path">' . Html::e($path) . '</code>' : '')
            . '<p class="hint">' . ($usable ? $text : 'Not possible here: ' . Html::e($reason)) . '</p>' . ($usable ? $extra : '') . '</div>';
    }
    /**
     * @param array<string, mixed> $session
     */
    private function install(Request $request, string $token, array $session): Response
    {
        $package = InstallPackage::open($this->script);
        $choice = (string) $request->post('place');
        $candidates = [];
        foreach (Places::candidates($this->baseDirectory) as $place) {
            $candidates[$place['id']] = $place;
        }
        $place = $candidates[$choice] ?? null;
        $home = $place !== null ? $place['path'] : ($choice === 'custom' ? rtrim(str_replace('\\', '/', trim((string) $request->post('custom'))), '/') : '');
        $problem = match (true) {
            $home === '' => 'Choose where the program goes.',
            $place !== null && !$place['usable'] => $place['reason'],
            default => Places::problem($home, $this->baseDirectory),
        };
        if ($problem === null && Places::isInside($home, $this->baseDirectory) && $request->post('inside_ok') !== '1') {
            $problem = 'A home below the document root is closed by an .htaccess only. Tick the confirmation in the tile "Below the document root" (you run Apache, or you have added a rule), or choose another place.';
        }
        if ($problem !== null) {
            return $this->checkPage($session, $problem);
        }
        $problem = Places::documentRootProblem($this->baseDirectory);
        if ($problem !== null || array_filter(Environment::requirements($package->manifest->version, $package->manifest->php, $package->manifest->extensions), static fn (Check $c): bool => $c->level === CheckLevel::Failure) !== []) {
            return $this->checkPage($session, $problem ?? 'This host does not meet the requirements.');
        }

        $task = new InstallRun();
        $run = $this->store()->create($task->id(), $task->initialState(['file' => $this->script, 'docroot' => $this->baseDirectory, 'home' => $home]));
        $session['runId'] = $run->id;
        $this->sessions->save($token, $session);

        return Response::redirect('?step=run');
    }

    // ------------------------------------------------------------------------------------------------- run

    private function store(): RunStore
    {
        return new RunStore($this->stateDirectory . '/runs');
    }

    private function executor(): Executor
    {
        return new Executor($this->store(), new InstallRun(), $this->budgets ?? Budget::forThisProcess(...));
    }

    private function unfinishedRun(): ?Run
    {
        foreach ($this->store()->all('install') as $run) {
            if ($run->status !== RunStatus::Finished) {
                return $run;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $session
     */
    private function currentRun(array $session): ?Run
    {
        if (!is_string($session['runId'] ?? null)) {
            return null;
        }
        try {
            return $this->store()->load($session['runId']);
        } catch (EngineException) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $session
     */
    private function runPage(array $session): Response
    {
        $run = $this->currentRun($session);
        if ($run === null) {
            return Response::redirect('?step=check');
        }
        if ($run->status === RunStatus::Finished) {
            return Response::redirect('?step=done');
        }
        $failed = $run->status === RunStatus::Failed;
        $body = '<p>The installation works in steps; this page keeps it going. <strong>Keep this page open.</strong> If it is closed or the connection is lost, open the installer again and continue here.</p>'
            . '<div class="bar"><div id="bar" data-percent="' . $this->percent($run) . '"></div></div><p id="status">' . Html::e((string) $run->progress) . '</p>'
            . '<p id="error" class="fail">' . ($failed ? Html::e((string) $run->error) : '') . '</p>'
            . $this->form('<button name="action" value="tick" id="go">Continue</button><button name="action" value="retry" class="secondary" id="retry"' . ($failed ? '' : ' hidden') . '>Try the failed step again</button>', (string) $session['csrf'])
            . '<noscript><p class="help">Without JavaScript press "Continue" again and again until the page says it is done.</p></noscript>';

        return $this->page('Installing', $body, 200, 'run', $this->runScript((string) $session['csrf'], $failed));
    }

    private function runScript(string $csrf, bool $failed): string
    {
        $token = json_encode($csrf, JSON_THROW_ON_ERROR);
        $failedJs = $failed ? 'true' : 'false';

        return <<<JS
(function () {
  var csrf = $token, bar = document.getElementById('bar'), status = document.getElementById('status'), error = document.getElementById('error');
  var go = document.getElementById('go'), retry = document.getElementById('retry'), stopped = $failedJs;
  go.hidden = true;
  bar.style.width = bar.getAttribute('data-percent') + '%';
  function post(action) {
    return fetch('?', {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: 'csrf=' + encodeURIComponent(csrf) + '&action=' + action + '&format=json'}).then(function (r) { return r.json(); });
  }
  function show(d) {
    if (d.percent !== undefined) { bar.style.width = d.percent + '%'; }
    if (d.progress) { status.textContent = d.progress; }
    if (d.status === 'finished') { window.location = '?step=done'; return; }
    if (d.status === 'failed') { error.textContent = d.error; stopped = true; retry.hidden = false; return; }
    setTimeout(tick, d.status === 'busy' ? 2000 : 50);
  }
  function tick() { if (!stopped) { post('tick').then(show).catch(function () { status.textContent = 'No answer from the server; trying again ...'; setTimeout(tick, 3000); }); } }
  retry.addEventListener('click', function (e) { e.preventDefault(); retry.hidden = true; error.textContent = ''; stopped = false; post('retry').then(function () { tick(); }); });
  if (!stopped) { tick(); }
})();
JS;
    }

    /**
     * One tick of the installation. With `format=json` (the page's script) the answer is data, otherwise a redirect back to the page.
     *
     * @param array<string, mixed> $session
     */
    private function tick(Request $request, array $session): Response
    {
        $run = $this->currentRun($session);
        $json = $request->post('format') === 'json';
        if ($run === null) {
            return $json ? Response::json(['status' => 'failed', 'error' => 'There is no installation to continue.']) : Response::redirect('?step=check');
        }
        $answer = ['status' => 'running'];
        try {
            $run = $this->executor()->execute($run->id, 1);
        } catch (EngineException $e) {
            $answer = str_contains($e->getMessage(), 'another process') ? ['status' => 'busy'] : ['status' => 'failed', 'error' => $e->getMessage()];
            $run = $this->currentRun($session) ?? $run;
        } catch (Throwable $e) {
            $answer = ['status' => 'failed', 'error' => $e->getMessage()];
            $run = $this->currentRun($session) ?? $run;
        }
        if ($run->status === RunStatus::Finished) {
            $answer = ['status' => 'finished'];
        }
        $answer += ['progress' => (string) $run->progress, 'percent' => $this->percent($run)];

        return $json ? Response::json($answer) : Response::redirect('?step=run');
    }

    /**
     * @param array<string, mixed> $session
     */
    private function retry(array $session): Response
    {
        $run = $this->currentRun($session);
        if ($run !== null && $run->status === RunStatus::Failed) {
            $this->executor()->retry($run->id);
        }

        return Response::json(['status' => 'running']);
    }

    private function percent(Run $run): int
    {
        if ($run->status === RunStatus::Finished) {
            return 100;
        }
        $state = $run->state;
        $ratio = (int) ($state['expected'] ?? 0) > 0 ? min(1.0, (int) ($state['files'] ?? 0) / (int) $state['expected']) : 0.0;

        return match ($state['phase'] ?? 'prepare') {
            'extract' => (int) round(3 + 94 * $ratio),
            'finish' => 98,
            default => 1,
        };
    }

    // ------------------------------------------------------------------------------------------------- done

    /**
     * @param array<string, mixed> $session
     */
    private function donePage(array $session): Response
    {
        $run = $this->currentRun($session);
        if ($run === null || $run->status !== RunStatus::Finished) {
            return Response::redirect('?step=run');
        }
        $body = '<p class="ok"><strong>ThreadSafe ' . Html::e((string) $run->state['version']) . ' is installed.</strong></p><div class="box">'
            . (int) $run->state['files'] . ' files in <code>' . Html::e((string) $run->state['home']) . '/releases/' . Html::e((string) $run->state['version']) . '</code>, the data in <code>' . Html::e((string) $run->state['home']) . '/data</code>.</div>'
            . '<h3>Remove the installer</h3><p><strong>Do this now.</strong> This installer can put a program on this host for anyone who has the key. It deletes <code>' . Html::e(basename($this->script)) . '</code>, the key file <code>' . self::KEY_FILE . '</code> and its own working files. The next page lets you choose the password of the administrator.</p>'
            . $this->form('<button name="action" value="cleanup">Remove the installer and go on</button>', (string) $session['csrf']);

        return $this->page('Done', $body, 200, 'done');
    }

    /**
     * @param array<string, mixed> $session
     */
    private function cleanup(array $session): Response
    {
        $run = $this->currentRun($session);
        if ($run === null || $run->status !== RunStatus::Finished) {
            return Response::redirect('?step=run');
        }
        $setupKey = (string) $run->state['setupKey'];
        $problems = Cleanup::run([$this->baseDirectory . '/' . basename($this->script), $this->baseDirectory . '/' . self::KEY_FILE], $this->stateDirectory);

        $body = $problems === []
            ? '<p class="ok"><strong>The installer is gone.</strong></p>'
            : '<p class="fail"><strong>Not everything could be deleted.</strong> Delete these by hand, now:</p><ul><li>' . implode('</li><li>', array_map(Html::e(...), $problems)) . '</li></ul>';
        // the setup page of the installed interface takes the key from a form, never from an address
        $body .= '<h3>Choose the password</h3><p>Open the setup page of ThreadSafe to choose the password of the administrator. The key is filled in for you.</p>'
            . '<form method="post" action="index.php?route=/setup"><input type="hidden" name="key" value="' . Html::e($setupKey) . '"><button>Set up the administrator</button></form>'
            . '<p class="help">If you close this page first: the key is in <code>' . Html::e((string) $run->state['home']) . '/data/setup-key.php</code>, and <code>php ' . Html::e((string) $run->state['home']) . '/bin/threadsafe admin password</code> sets the password from a shell. Without a rewrite rule on this host, set <code>web.prettyUrls</code> to <code>false</code> (see WEB.md).</p>';

        return $this->page('Installed', $body);
    }

    // ------------------------------------------------------------------------------------------------- output

    private function form(string $inner, string $csrf): string
    {
        return '<form method="post"><input type="hidden" name="csrf" value="' . Html::e($csrf) . '">' . $inner . '</form>';
    }

    private function page(string $title, string $body, int $status = 200, ?string $step = null, string $script = ''): Response
    {
        return Response::html(Html::page($title, $body, $this->nonce, $step, $script, 'installer', self::STEPS), $status);
    }

    /** Headers every answer carries: nothing is cached, framed, sniffed or loaded from elsewhere. */
    private function secure(Response $response): Response
    {
        return new Response($response->status, [
            ...$response->headers,
            'Cache-Control: no-store',
            'X-Content-Type-Options: nosniff',
            'X-Frame-Options: DENY',
            'Referrer-Policy: no-referrer',
            "Content-Security-Policy: default-src 'none'; img-src data:; style-src 'nonce-{$this->nonce}'; script-src 'nonce-{$this->nonce}'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'",
        ], $response->body);
    }
}
