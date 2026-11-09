<?php

declare(strict_types=1);

namespace ThreadSafe\Web;

use Closure;
use Throwable;
use ThreadSafe\App\BackupService;
use ThreadSafe\App\CatalogService;
use ThreadSafe\App\Detection;
use ThreadSafe\App\ProfileService;
use ThreadSafe\Backup\Filters;
use ThreadSafe\Core\OpenBasedir;
use ThreadSafe\Crypto\AgeKeys;
use ThreadSafe\Crypto\CryptoException;
use ThreadSafe\Crypto\Passphrases;
use ThreadSafe\Core\Exception\GuardFailed;
use ThreadSafe\Database\DatabaseSettings;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Engine\EngineException;
use ThreadSafe\Engine\Run;
use ThreadSafe\Engine\RunStatus;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Profile\CheckLevel;
use ThreadSafe\Profile\EncryptionSettings;
use ThreadSafe\Profile\ProfileException;
use ThreadSafe\Profile\SiteProfile;
use ThreadSafe\Profile\TestReport;
use ThreadSafe\Storage\BackupEntry;
use ThreadSafe\Storage\LocalStorage;
use ThreadSafe\Storage\SpaceCheck;
use ThreadSafe\Storage\StorageException;

/**
 * The wizard of the backup tool (docs/PHASE12.md 3.2): server-rendered pages for a person who has put `threadsafe-backup.php` into a site and
 * wants a backup of it without installing ThreadSafe. One address, the steps selected by `?step=`; state-changing requests are POSTs with a CSRF token.
 *
 * Nothing happens without the key file ({@see AccessKey}). What the person chose is kept in the profile `site` of the tool's own data directory
 * (the engine runs on it unchanged) and in the session file (what must not be in a profile: the passphrase of this backup).
 *
 * Steps: key → site → options → summary (with the probe that the archive cannot be fetched through the web server) → run (one tick per request, from a few
 * lines of script) → done (the parts, a download per part, the clean-up). A backup that was started and not finished is continued when the person comes back.
 */
final class BackupWizard
{
    public const string COOKIE = 'threadsafe_backup';

    public const string KEY_FILE = 'threadsafe-backup-key.php';

    /** What to do when the web server refuses a step of the backup with HTTP 403. */
    private const string HINT_403 = 'HTTP 403 usually means that a rule of the web server refuses this file: a security extension of the site (such as Admin Tools) refuses direct requests for PHP files, or the host does. Allow this file for now, then press "Continue".';

    private const array STEPS = ['site' => 'Site', 'options' => 'Options', 'summary' => 'Summary', 'run' => 'Backup', 'done' => 'Done'];

    private readonly BackupWorkspace $workspace;

    private readonly BackupTarget $target;

    private readonly AccessKey $key;

    private readonly ReachProbe $probe;

    private readonly PluginRegistry $plugins;

    /** @var Closure(): int */
    private readonly Closure $clock;

    private ?Sessions $sessions = null;

    private string $token = '';

    private string $nonce = '';

    /** Whether the person has shown the key (a login, or a session): only then does an error page say what went wrong and where. */
    private bool $trusted = false;

    /**
     * @param string              $baseDirectory the directory the tool and the key file are in
     * @param string              $script        the file name of the tool
     * @param Closure(): int|null $clock         for tests
     * @param Closure(): Budget|null $budgets   the budget of a tick; default: a share of the request time limit
     * @param BackupWorkspace|null $workspace   where the state and the archives are kept; default: next to the tool ({@see BackupWorkspace::in()})
     */
    public function __construct(
        private readonly string $baseDirectory,
        ?PluginRegistry $plugins = null,
        private readonly string $script = 'threadsafe-backup.php',
        ?Closure $clock = null,
        private readonly ?Closure $budgets = null,
        ?BackupWorkspace $workspace = null,
    ) {
        $this->workspace = $workspace ?? BackupWorkspace::in($baseDirectory);
        $this->target = new BackupTarget($baseDirectory, $script, self::KEY_FILE, $this->workspace->isCustom() ? $this->workspace->directory() : null);
        $this->probe = new ReachProbe($baseDirectory);
        $this->clock = $clock ?? time(...);
        $this->key = new AccessKey(
            rtrim($baseDirectory, '/\\') . '/' . self::KEY_FILE,
            $this->workspace->directory() . '/attempts.json',
            $this->workspace->directory() . '/access.log',
            $clock,
        );
        $this->plugins = $plugins ?? PluginRegistry::load();
    }

    public function handle(Request $request): Response
    {
        $this->nonce = bin2hex(random_bytes(9));
        try {
            return $this->secure($this->route($request));
        } catch (Throwable $e) {
            return $this->secure($this->failure($e));
        }
    }

    /** The page for an error nobody expected: a stranger gets neither the message nor a path (they go to the error log of the host), the person with the key gets both. */
    private function failure(Throwable $e): Response
    {
        if (!$this->trusted) {
            @error_log('ThreadSafe backup tool: ' . $e->getMessage());

            return $this->page('Something went wrong', '<p class="fail">The tool could not do what was asked.</p><p class="help">Reload the page. If this stays, check that this program may write in its directory, or look in the error log of the host.</p>', 500);
        }

        return $this->page('Something went wrong', '<p class="fail">' . Html::e($e->getMessage()) . '</p><p class="help">Reload the page. If this stays, check that this program may write in <code>' . Html::e($this->workspace->directory()) . '</code>.</p>', 500);
    }

    // ------------------------------------------------------------------------------------------------- routing

    private function route(Request $request): Response
    {
        if (!$this->key->exists()) {
            return $this->page('No access key', '<p>This tool does nothing until you give it a key.</p><p>Create a file named <code>' . self::KEY_FILE . '</code> next to <code>' . Html::e($this->script) . '</code> with this content (choose your own secret of at least ' . AccessKey::MIN_LENGTH . ' characters):</p><pre><code>&lt;?php return \'a secret you choose\';</code></pre><p class="help">It is a PHP file on purpose: a plain text file would be served to anyone who asks for it. Then reload this page.</p>', 403);
        }
        if (($problem = $this->key->problem()) !== null) {
            return $this->page('The access key cannot be used', '<p class="fail">' . Html::e($problem) . '</p>', 403);
        }
        StateDirectory::prepare($this->workspace->directory());
        $this->sessions = new Sessions($this->workspace->data() . '/sessions', $this->clock);

        $token = $request->cookie(self::COOKIE);
        $session = $this->sessions->load($token);
        if ($session === null || $token === null) {
            return $request->isPost() && $request->post('action') === 'login' ? $this->login($request) : $this->loginPage($request);
        }
        $this->token = $token;
        $this->trusted = true;

        if ($request->isPost() && !hash_equals((string) $session['csrf'], (string) $request->post('csrf'))) {
            return $this->page('The page has expired', '<p>The form was not accepted (wrong or missing token). <a href="?">Start again</a>.</p>', 403);
        }

        $action = $request->isPost() ? (string) $request->post('action') : '';

        return match ($action) {
            'logout' => $this->logout(),
            'detect', 'save-site' => $this->saveSite($request, $session, $action === 'save-site'),
            'make-keys' => $this->makeKeys($request, $session),
            'use-keys' => $this->useKeys($request, $session),
            'suggest-passphrase', 'save-options' => $this->saveOptions($request, $session, $action === 'save-options', $action === 'suggest-passphrase'),
            'probe' => $this->recordProbe($request, $session),
            'start' => $this->start($request, $session),
            'tick' => $this->tick($request, $session),
            'retry' => $this->retry($session),
            'abandon' => $this->abandon($request, $session),
            'again' => $this->again($session),
            'delete-archive' => $this->deleteArchive($request, $session),
            'cleanup' => $this->cleanup($request, $session),
            default => $this->show($request, $request->query('step') ?? 'site', $session),
        };
    }

    // ------------------------------------------------------------------------------------------------- login

    private function loginPage(Request $request, string $error = ''): Response
    {
        $warning = $this->plainHttp($request)
            ? '<p class="box fail"><strong>This connection is not encrypted.</strong> The key, the database password and a passphrase for the backup cross the network in clear text. Use https if the host offers it; otherwise confirm below that you accept this.</p><label><input type="checkbox" name="plain" value="1"> I understand and accept this.</label>'
            : '';

        return $this->page('Access key', ($error !== '' ? '<p class="fail">' . Html::e($error) . '</p>' : '')
            . '<form method="post"><input type="hidden" name="action" value="login"><label for="key">Key from <code>' . self::KEY_FILE . '</code></label><input id="key" type="password" name="key" autocomplete="off" autofocus>' . $warning . '<button>Continue</button></form>', $error !== '' ? 403 : 200);
    }

    private function login(Request $request): Response
    {
        if ($this->plainHttp($request) && $request->post('plain') !== '1') {
            return $this->loginPage($request, 'Confirm that you accept the unencrypted connection, or use https.');
        }
        $wrong = $this->key->check((string) $request->post('key'), $request->remoteAddress);
        if ($wrong !== null) {
            return $this->loginPage($request, $wrong);
        }
        $this->trusted = true;
        [$token, $data] = $this->sessions()->create();
        $data['plain'] = $this->plainHttp($request);
        // a backup that was started and not finished (the browser was closed, the connection lost) goes on where it was; a finished one is shown again
        $run = $this->latestRun();
        if ($run !== null) {
            $data['runId'] = $run->id;
        }
        $this->sessions()->save($token, $data);

        return Response::redirect($run === null ? '?step=site' : ($run->status === RunStatus::Finished ? '?step=done' : '?step=run'))->withHeader($this->cookie($token, $request));
    }

    private function logout(): Response
    {
        $this->sessions()->destroy($this->token);

        return Response::redirect('?');
    }

    private function plainHttp(Request $request): bool
    {
        return !$request->https && !$request->isLocal();
    }

    private function cookie(string $token, Request $request): string
    {
        $path = $request->path === '' ? '/' : (str_contains($request->path, '/') ? (substr($request->path, 0, (int) strrpos($request->path, '/') + 1)) : '/');

        return 'Set-Cookie: ' . self::COOKIE . '=' . $token . '; Path=' . $path . '; HttpOnly; SameSite=Strict' . ($request->https ? '; Secure' : '');
    }

    private function sessions(): Sessions
    {
        return $this->sessions ?? throw new \LogicException('The sessions are made after the key has been checked.');
    }

    /**
     * @param array<string, mixed> $session
     */
    private function save(array $session): void
    {
        $this->sessions()->save($this->token, $session);
    }

    private function service(): ProfileService
    {
        return new ProfileService($this->workspace->profiles(), $this->plugins, $this->workspace->data());
    }

    private function form(string $inner, string $csrf): string
    {
        return '<form method="post"><input type="hidden" name="csrf" value="' . Html::e($csrf) . '">' . $inner . '</form>';
    }

    // ------------------------------------------------------------------------------------------------- the steps

    /**
     * @param array<string, mixed> $session
     */
    private function show(Request $request, string $step, array $session): Response
    {
        return match (true) {
            $step === 'run' => $this->runPage($session),
            $step === 'status' => $this->statusAnswer($session),
            $step === 'done' => $this->donePage($session),
            $step === 'file' => $this->file($request, $session),
            $step === 'options' && ($session['siteDone'] ?? false) === true => $this->optionsPage($session),
            $step === 'summary' && ($session['optionsDone'] ?? false) === true => $this->summaryPage($session),
            $step === 'options', $step === 'summary' => Response::redirect('?step=' . (($session['siteDone'] ?? false) === true ? 'options' : 'site')),
            default => $this->sitePage($session),
        };
    }

    // ------------------------------------------------------------------------------------------------- site

    /**
     * @param array<string, mixed> $session
     * @param list<string>         $messages HTML that goes above the form
     */
    private function sitePage(array $session, array $messages = []): Response
    {
        /** @var array<string, string> $values */
        $values = (array) ($session['form'] ?? []);
        $value = static fn (string $name, string $default = ''): string => Html::e($values[$name] ?? $default);
        $text = static fn (string $name, string $label, string $current, string $help = '', string $type = 'text'): string => '<label for="' . $name . '">' . Html::e($label) . '</label><input id="' . $name . '" type="' . $type . '" name="' . $name . '" value="' . $current . '"' . ($type === 'password' ? ' autocomplete="off"' : '') . '>'
            . ($help !== '' ? '<span class="help">' . Html::e($help) . '</span>' : '');

        $type = $values['type'] ?? 'auto';
        $options = '<option value="auto"' . ($type === 'auto' ? ' selected' : '') . '>Detect it</option>';
        foreach ($this->service()->types() as $id => $title) {
            $options .= '<option value="' . Html::e($id) . '"' . ($type === $id ? ' selected' : '') . '>' . Html::e($title) . '</option>';
        }

        $html = implode('', $messages)
            . $text('root', 'Directory of the site', $value('root', $this->target->defaultRoot()), 'The directory this tool is in, or one above it.')
            . '<label for="type">Kind of website</label><select id="type" name="type">' . $options . '</select>'
            . '<p><button class="secondary" name="action" value="detect">Look at the site</button></p>';

        $detection = null;
        try {
            [, $detection] = $this->resolveSite($values);
            $html .= $this->detectionBox($detection);
        } catch (ProfileException $e) {
            $html .= '<p class="fail">' . Html::e($e->getMessage()) . '</p>';
        }
        if ($detection !== null && $detection->database === null) {
            $html .= '<h3>Database</h3><p class="help">This kind of website keeps its database access nowhere this tool can read it. Enter it here, or say that the site has none.</p>'
                . $text('db_host', 'Host', $value('db_host', 'localhost'))
                . $text('db_port', 'Port', $value('db_port', '3306'))
                . $text('db_name', 'Database name', $value('db_name'))
                . $text('db_user', 'User', $value('db_user'))
                . $text('db_password', 'Password', $value('db_password'), 'It is kept in a private file of this tool until you delete the tool.', 'password')
                . $text('db_prefix', 'Table prefix', $value('db_prefix'), 'Only the tables that begin with it belong to the site. Empty: every table of the database.')
                . '<label><input type="checkbox" name="no_db" value="1"' . (($values['no_db'] ?? '') === '1' ? ' checked' : '') . '>This site has no database</label>';
        }
        $html .= '<p><button name="action" value="save-site">Next: options</button></p>';

        return $this->page('The site', $this->form($html, (string) $session['csrf']), 200, 'site');
    }

    private function detectionBox(Detection $detection): string
    {
        $database = $detection->database === null
            ? 'The database access is not part of the site: see below.'
            : sprintf('The database access is read from the site: "%s" on %s:%d, prefix "%s" (the password is not shown).', $detection->database->name, $detection->database->host, $detection->database->port, $detection->database->prefix);

        $leaves = $detection->excludedPaths > 0 || $detection->structureOnlyTables > 0
            ? 'By default it leaves out ' . $detection->excludedPaths . ' paths (caches, logs) and keeps only the structure of ' . $detection->structureOnlyTables . ' tables. '
            : '';

        return '<div class="box"><p><strong>' . Html::e($detection->site->describe()) . '</strong> found in <code>' . Html::e($detection->root) . '</code></p><p>' . Html::e($database) . '</p>'
            . '<p class="help">' . $leaves . 'The tool itself, its key file and its directories are never part of the backup.</p></div>';
    }

    /**
     * @param array<string, string> $values the fields of the site form
     *
     * @return array{string, Detection} the id of the website type and what it found
     *
     * @throws ProfileException
     */
    private function resolveSite(array $values): array
    {
        $root = $this->target->resolve(trim($values['root'] ?? $this->target->defaultRoot()));
        $type = $values['type'] ?? 'auto';

        return $type === 'auto' ? $this->service()->autodetect($root) : [$type, $this->service()->detect($type, $root)];
    }

    /**
     * @param array<string, mixed> $session
     */
    private function saveSite(Request $request, array $session, bool $next): Response
    {
        $values = [];
        foreach (['root', 'type', 'db_host', 'db_port', 'db_name', 'db_user', 'db_password', 'db_prefix', 'no_db'] as $name) {
            $values[$name] = trim((string) $request->post($name));
        }
        $session['form'] = $values;
        unset($session['siteDone'], $session['optionsDone']);
        $this->save($session);
        if (!$next) {
            return $this->sitePage($session);
        }

        try {
            [$type, $detection] = $this->resolveSite($values);
            $database = $detection->database === null ? $this->explicitDatabase($values) : null;
            $profile = $this->target->profile($detection->root, $type, $this->storageFor($session), $database);
        } catch (ProfileException $e) {
            return $this->sitePage($session, ['<p class="fail">' . Html::e($e->getMessage()) . '</p>']);
        }
        $report = $this->service()->test($profile);
        if (!$report->passed()) {
            return $this->sitePage($session, ['<p class="fail">The site cannot be backed up like this.</p>' . $this->checksTable($report)]);
        }
        $result = $this->service()->store($profile, test: false);
        if (!$result->stored) {
            return $this->sitePage($session, ['<p class="fail">' . Html::e($result->error ?? 'The profile could not be saved.') . '</p>']);
        }
        $session['siteDone'] = true;
        $session['type'] = $type;
        $this->save($session);

        return Response::redirect('?step=options');
    }

    /**
     * The database block a person typed (a website type that cannot read one from the site), or null for a site without a database.
     *
     * @param array<string, string> $values
     *
     * @throws ProfileException something is missing or not a number
     */
    private function explicitDatabase(array $values): ?DatabaseSettings
    {
        if (($values['no_db'] ?? '') === '1') {
            return null;
        }
        foreach (['db_name' => 'the database name', 'db_user' => 'the database user'] as $field => $label) {
            if (($values[$field] ?? '') === '') {
                throw new ProfileException("Enter $label, or tick that the site has no database.");
            }
        }
        $port = $values['db_port'] ?? '';
        if (preg_match('/^\d{1,5}$/', $port) !== 1 || (int) $port < 1 || (int) $port > 65535) {
            throw new ProfileException('The database port must be a number from 1 to 65535.');
        }

        return new DatabaseSettings(($values['db_host'] ?? '') !== '' ? $values['db_host'] : 'localhost', (int) $port, $values['db_name'], $values['db_user'], $values['db_password'] ?? '', $values['db_prefix'] ?? '');
    }

    private function checksTable(TestReport $report): string
    {
        $rows = [];
        foreach ($report->checks as $check) {
            $rows[] = ['level' => $check->level === CheckLevel::Failure ? 'error' : $check->level->value, 'subject' => $check->subject, 'message' => $check->message, 'hint' => ''];
        }

        return Html::checkTable($rows);
    }

    // ------------------------------------------------------------------------------------------------- options

    /**
     * The values of the options form, with their defaults.
     *
     * @param array<string, mixed> $session
     *
     * @return array{exclude: string, partMb: string, note: string, storage: string, mode: string, recipient: string, passphrase: string, passphrase2: string, plain: string}
     */
    private function optionValues(array $session): array
    {
        $options = (array) ($session['opts'] ?? []);
        $get = static fn (string $name, string $default): string => is_string($options[$name] ?? null) ? $options[$name] : $default;

        return [
            'exclude' => $get('exclude', ''),
            'partMb' => $get('partMb', (string) intdiv(BackupTarget::PART_SIZE, 1048576)),
            'note' => $get('note', ''),
            'storage' => $get('storage', ''),
            'mode' => $get('mode', 'pair'),
            'recipient' => $get('recipient', ''),
            'passphrase' => $get('passphrase', ''),
            'passphrase2' => $get('passphrase2', ''),
            'plain' => $get('plain', ''),
        ];
    }

    /**
     * @param array<string, mixed> $session
     */
    private function optionsPage(array $session, string $error = ''): Response
    {
        $v = $this->optionValues($session);
        $made = is_string($session['pair'] ?? null) ? $session['pair'] : null;
        $e = Html::e(...);
        $radio = static fn (string $mode, string $label, string $help): string => '<label class="choice"><input type="radio" name="mode" value="' . $mode . '"' . ($v['mode'] === $mode ? ' checked' : '') . '>' . $e($label) . '</label><span class="help">' . $e($help) . '</span>';

        $html = ($error !== '' ? '<p class="fail">' . $e($error) . '</p>' : '')
            . '<h3>Who may open this backup?</h3>'
            . '<p class="help">The backup holds the database access and the customer data of the site. It is encrypted, so that a copy that gets into the wrong hands is of no use. Without the key or the passphrase nobody can open it, this server included.</p>'
            . $radio('pair', 'A key pair made here (recommended)', 'You are shown the secret key once, to keep somewhere else than on this server. The backup is made for its public key.')
            . ($made !== null ? '<p class="ok">A key pair is made: the public key is <code>' . $e($made) . '</code>.</p>' : '')
            . '<p><button class="secondary" name="action" value="make-keys">' . ($made !== null ? 'Make another key pair' : 'Make a key pair') . '</button></p>'
            . $radio('recipient', 'A public key I have', 'The secret half never comes near this server. A public key begins with age1.')
            . '<input type="text" name="recipient" value="' . $e($v['recipient']) . '" aria-label="Public key" placeholder="age1...">'
            . $radio('passphrase', 'A passphrase for this backup', 'Whoever knows it can open the backup, and no key is needed. It is kept in a private file of this tool until you delete the tool.')
            . '<label for="passphrase">Passphrase</label><input id="passphrase" type="password" name="passphrase" value="' . $e($v['passphrase']) . '" autocomplete="off">'
            . '<label for="passphrase2">The same again</label><input id="passphrase2" type="password" name="passphrase2" value="' . $e($v['passphrase2']) . '" autocomplete="off">'
            . '<p><button class="secondary" name="action" value="suggest-passphrase">Suggest a passphrase</button></p>'
            . $radio('none', 'No encryption', 'Not recommended.')
            . '<label><input type="checkbox" name="plain" value="1"' . ($v['plain'] === '1' ? ' checked' : '') . '>I accept that this archive on the server can be read by whoever finds it</label>'
            . '<h3>The backup</h3>'
            . '<label for="exclude">More to leave out</label><textarea id="exclude" name="exclude" rows="4" placeholder="cache/&#10;*.log">' . $e($v['exclude']) . '</textarea><span class="help">One pattern per line, relative to the directory of the site. A slash at the end means directories only.</span>'
            . '<label for="partMb">Size of a part (MB)</label><input id="partMb" type="text" name="partMb" value="' . $e($v['partMb']) . '"><span class="help">A backup is split into parts of this size, so that a download can be repeated per part.</span>'
            . '<label for="note">A note</label><input id="note" type="text" name="note" value="' . $e($v['note']) . '">'
            . '<details><summary>Where the archive is kept</summary><label for="storage">Directory</label><input id="storage" type="text" name="storage" value="' . $e($v['storage']) . '" placeholder="' . $e($this->workspace->existingStorage() ?? $this->workspace->directory() . '/storage-...') . '"><span class="help">Empty: a directory with a random name next to this tool, closed to the web. Use a directory outside the document root of the site if the next page says the archive can be fetched.</span></details>'
            . '<p><button name="action" value="save-options">Next: summary</button> <a class="button secondary" href="?step=site">Back</a></p>';

        return $this->page('Options', $this->form($html, (string) $session['csrf']), 200, 'options');
    }

    /**
     * Keeps what the form says, and with `$next` checks it and goes on; with `$suggest` puts a passphrase into the fields.
     *
     * @param array<string, mixed> $session
     */
    private function saveOptions(Request $request, array $session, bool $next, bool $suggest): Response
    {
        $session['opts'] = $this->postedOptions($request);
        unset($session['optionsDone']);
        if ($suggest) {
            $passphrase = Passphrases::generate();
            $session['opts']['mode'] = 'passphrase';
            $session['opts']['passphrase'] = $session['opts']['passphrase2'] = $passphrase;
        }
        $this->save($session);
        if (!$next) {
            return $this->optionsPage($session);
        }

        try {
            $profile = $this->optionsProfile($session);
            $session['passphrase'] = $session['opts']['mode'] === 'passphrase' ? $session['opts']['passphrase'] : '';
            $this->prepareStorage($profile->storage);
        } catch (ProfileException | StorageException $e) {
            return $this->optionsPage($session, $e->getMessage());
        }
        $result = $this->service()->store($profile, test: false);
        if (!$result->stored) {
            return $this->optionsPage($session, $result->error ?? 'The options could not be saved.');
        }
        $session['optionsDone'] = true;
        $this->save($session);

        return Response::redirect('?step=summary');
    }

    /**
     * @return array<string, string>
     */
    private function postedOptions(Request $request): array
    {
        $posted = [];
        foreach (['exclude', 'partMb', 'note', 'storage', 'mode', 'recipient', 'passphrase', 'passphrase2', 'plain'] as $name) {
            $posted[$name] = in_array($name, ['exclude', 'passphrase', 'passphrase2'], true) ? (string) $request->post($name) : trim((string) $request->post($name));
        }
        if (!in_array($posted['mode'], ['pair', 'recipient', 'passphrase', 'none'], true)) {
            $posted['mode'] = 'pair';
        }

        return $posted;
    }

    /**
     * The stored profile with the options of the form put in.
     *
     * @param array<string, mixed> $session
     *
     * @throws ProfileException an option is not valid; the message says which
     */
    private function optionsProfile(array $session): SiteProfile
    {
        $v = $this->optionValues($session);
        $profile = $this->service()->load(BackupWorkspace::PROFILE);

        $patterns = [];
        foreach (preg_split('/\r\n|\r|\n/', $v['exclude']) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (($problem = Filters::problem($line)) !== null) {
                throw new ProfileException("The pattern \"$line\" cannot be used: $problem.");
            }
            $patterns[] = $line;
        }
        if (preg_match('/^\d{1,6}$/', $v['partMb']) !== 1 || (int) $v['partMb'] < 1) {
            throw new ProfileException('The size of a part must be a whole number of megabytes, 1 or more.');
        }

        $recipients = match ($v['mode']) {
            'pair' => $this->madePair($session),
            'recipient' => $this->typedRecipient($v['recipient']),
            'passphrase' => $this->checkedPassphrase($v),
            default => $this->acceptedPlain($v),
        };

        $storage = $this->storageFor($session);

        return $profile->with([
            'excludePaths' => [...$this->target->selfExcludes($profile->path), ...$patterns],
            'partSize' => (int) $v['partMb'] * 1048576,
            'note' => $v['note'],
            'storage' => $storage,
            'encryption' => $recipients === [] ? null : ['recipients' => $recipients],
        ]);
    }

    /**
     * @param array<string, mixed> $session
     *
     * @return list<string>
     *
     * @throws ProfileException
     */
    private function madePair(array $session): array
    {
        $this->requireSodium();
        if (!is_string($session['pair'] ?? null)) {
            throw new ProfileException('Make the key pair first (the button above), or choose another way.');
        }

        return [$session['pair']];
    }

    /**
     * @return list<string>
     *
     * @throws ProfileException
     */
    private function typedRecipient(string $text): array
    {
        $this->requireSodium();
        if (!EncryptionSettings::isRecipient($text)) {
            throw new ProfileException('That is not a public key of the age format (age1 and 58 more characters). Check that it was copied whole.');
        }

        return [$text];
    }

    /**
     * @param array<string, string> $v
     *
     * @return list<string>
     *
     * @throws ProfileException
     */
    private function checkedPassphrase(array $v): array
    {
        $this->requireSodium();
        if ($v['passphrase'] !== $v['passphrase2']) {
            throw new ProfileException('The two passphrases are not the same.');
        }
        $weak = Passphrases::weakness($v['passphrase']);
        if ($weak !== null) {
            throw new ProfileException($weak);
        }

        return [];
    }

    /**
     * @param array<string, string> $v
     *
     * @return list<string>
     *
     * @throws ProfileException
     */
    private function acceptedPlain(array $v): array
    {
        if ($v['plain'] !== '1') {
            throw new ProfileException('Tick the box to confirm that you want an archive that is not encrypted, or choose another way.');
        }

        return [];
    }

    /** @throws ProfileException */
    private function requireSodium(): void
    {
        try {
            CryptoException::requireSodium();
        } catch (CryptoException) {
            throw new ProfileException('Encryption needs the PHP extension "sodium", which this server does not have. Ask the host to enable it, or choose "No encryption".');
        }
    }

    /**
     * Makes a key pair, shows the secret half once, and asks for the go-ahead before the public half is used.
     *
     * @param array<string, mixed> $session
     */
    private function makeKeys(Request $request, array $session): Response
    {
        $session['opts'] = $this->postedOptions($request);
        $this->save($session);
        try {
            $this->requireSodium();
            $pair = AgeKeys::generate();
        } catch (ProfileException | CryptoException $e) {
            return $this->optionsPage($session, $e->getMessage());
        }
        $file = AgeKeys::keyFile($pair['secret'], $pair['public'], new \DateTimeImmutable('@' . ($this->clock)()));

        $body = '<div class="box"><p><strong>This is the only time the secret key is shown.</strong> This tool does not keep it. Store it now, somewhere else than on this server: without it the backup cannot be opened.</p>'
            . '<pre id="secret-key"><code>' . Html::e($file) . '</code></pre>'
            . '<p><button type="button" id="copy-key">Copy</button> <button type="button" id="save-key" class="secondary">Save as a file</button> <button type="button" id="print-key" class="secondary">Print</button></p>'
            . '<p>Public key: <code>' . Html::e($pair['public']) . '</code></p></div>'
            . $this->form(
                '<input type="hidden" name="public" value="' . Html::e($pair['public']) . '">'
                . '<label><input type="checkbox" name="stored" value="1">I have stored the secret key somewhere else than on this server.</label>'
                . '<p class="help">This page cannot be shown again. A key that is not stored makes the backup useless.</p>'
                . '<p><button name="action" value="use-keys">Use this key pair</button> <a class="button secondary" href="?step=options">Do not use it</a></p>',
                (string) $session['csrf'],
            );

        $script = "(function () {\n"
            . "  var text = document.getElementById('secret-key').textContent;\n"
            . "  document.getElementById('copy-key').addEventListener('click', function () { if (navigator.clipboard) { navigator.clipboard.writeText(text); } });\n"
            . "  document.getElementById('save-key').addEventListener('click', function () {\n"
            . "    var a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([text], {type: 'text/plain'})); a.download = 'threadsafe-backup-key.txt'; document.body.appendChild(a); a.click(); a.remove();\n"
            . "  });\n"
            . "  document.getElementById('print-key').addEventListener('click', function () { window.print(); });\n"
            . '})();';

        return $this->page('Your secret key', $body, 200, 'options', $script);
    }

    /**
     * @param array<string, mixed> $session
     */
    private function useKeys(Request $request, array $session): Response
    {
        $public = trim((string) $request->post('public'));
        if ($request->post('stored') !== '1' || !EncryptionSettings::isRecipient($public)) {
            return $this->optionsPage($session, 'The key pair was not used: the secret key must be stored first (tick the box). It is gone; make a new pair.');
        }
        $session['pair'] = $public;
        $session['opts'] = [...$this->optionValues($session), 'mode' => 'pair'];
        $this->save($session);

        return $this->optionsPage($session);
    }

    // ------------------------------------------------------------------------------------------------- storage and summary

    /**
     * Where the archive goes: the directory the person named, else the storage of the tool's workspace.
     *
     * @param array<string, mixed> $session
     *
     * @throws ProfileException the directory named is not usable
     */
    private function storageFor(array $session): string
    {
        $named = $this->optionValues($session)['storage'];
        if ($named === '') {
            return $this->workspace->storage();
        }
        $normal = str_replace('\\', '/', $named);
        if (!str_starts_with($normal, '/') && preg_match('#^[A-Za-z]:/#', $normal) !== 1) {
            throw new ProfileException('The directory for the archive must be an absolute path.');
        }
        if (!OpenBasedir::permits($normal)) {
            throw new ProfileException('This host does not let PHP use that directory (open_basedir).');
        }

        return rtrim($named, '/\\');
    }

    /**
     * @throws StorageException the directory cannot be made or written
     */
    private function prepareStorage(string $directory): void
    {
        (new LocalStorage($directory))->prepare();
    }

    /**
     * @param array<string, mixed> $session
     */
    private function summaryPage(array $session, string $error = ''): Response
    {
        try {
            $profile = $this->service()->load(BackupWorkspace::PROFILE);
            $this->prepareStorage($profile->storage);
        } catch (ProfileException | StorageException $e) {
            return $this->optionsPage($session, $e->getMessage());
        }
        $directories = $this->probeDirectories($profile->storage);
        $probe = $this->probe->prepare((array) ($session['probe'] ?? []), $directories);
        if ($probe !== ($session['probe'] ?? null)) {
            $session['probe'] = $probe;
            $this->save($session);
        }
        $free = (new SpaceCheck())->check($profile->storage, 0)->free;

        $encryption = $profile->encryption !== null
            ? 'Encrypted for the public key ' . $profile->encryption->recipients[0]
            : (($session['passphrase'] ?? '') !== '' ? 'Encrypted with a passphrase' : 'Not encrypted');
        $rows = [
            ['Site', $profile->path],
            ['Kind', $profile->type],
            ['Encryption', $encryption],
            ['Parts', 'of ' . SpaceCheck::format($profile->partSize ?? BackupTarget::PART_SIZE)],
            ['Archive in', $profile->storage],
            ['Free space there', $free === null ? 'unknown' : SpaceCheck::format($free)],
            ['Left out', count($profile->excludePaths) . ' patterns, the tool itself among them'],
        ];
        $table = '<table>';
        foreach ($rows as [$label, $content]) {
            $table .= '<tr><th>' . Html::e($label) . '</th><td>' . Html::e($content) . '</td></tr>';
        }
        $table .= '</table>';

        $result = $probe['result'];
        $body = ($error !== '' ? '<p class="fail">' . Html::e($error) . '</p>' : '')
            . '<div class="box">' . $table . '</div>'
            . '<h3>Can the archive be fetched through the web server?</h3>'
            . '<p id="probe-result" class="' . ($result === 'reachable' ? 'fail' : '') . '">' . Html::e($this->probeText($result)) . '</p>'
            . '<p class="help">The page asks the web server for a small file in the directory of the archive and for one in the directory of this tool (which holds the settings, among them a database password you typed). If one can be fetched, so could what else is in that directory: choose a directory for the archive outside the document root on the options page, and have the web server close <code>' . Html::e(BackupWorkspace::NAME) . '</code>.</p>'
            . $this->form(
                '<div id="probe-tick"' . ($result === 'closed' ? ' hidden' : '') . '><label><input type="checkbox" name="probe_ok" value="1">I have made sure that the directory of the archive cannot be opened in a browser</label>'
                . '<p class="help">Only needed if the test above does not say "No".</p></div>'
                . '<label><input type="checkbox" name="force" value="1">Go on even if the free space looks too small</label>'
                . '<p class="help">Nothing has been written yet except the small test file.</p>'
                . '<p><button name="action" value="start" id="start"' . ($result === 'reachable' ? ' disabled' : '') . '>Back up now</button> <a class="button secondary" href="?step=options">Back</a></p>',
                (string) $session['csrf'],
            );

        $texts = ['closed' => $this->probeText('closed'), 'reachable' => $this->probeText('reachable'), 'unknown' => $this->probeText('unknown')];

        return $this->page('Summary', $body, 200, 'summary', $this->probe->script((string) $session['csrf'], $this->probe->urls($probe['file'], $directories), $probe['token'], $texts));
    }

    private function probeText(string $result): string
    {
        return match ($result) {
            'closed' => 'No: the web server does not hand the test files out.',
            'reachable' => 'Yes: a test file can be fetched. Choose a directory for the archive outside the document root, and ask the host to close the directory of this tool.',
            'unknown' => 'The test could not be made (the page needs JavaScript for it). Make sure by hand that the directory of the archive and the directory of this tool cannot be opened in a browser.',
            default => 'The page is asking the web server ...',
        };
    }

    /**
     * The directories the probe puts a file into: the one of the tool (its settings, sessions and run records are in it, and the archive too, unless
     * it is kept elsewhere) and the one of the archive.
     *
     * @return list<string>
     */
    private function probeDirectories(string $storage): array
    {
        return array_values(array_unique([$this->workspace->directory(), $storage]));
    }

    /**
     * @param array<string, mixed> $session
     */
    private function recordProbe(Request $request, array $session): Response
    {
        $result = (string) $request->post('result');
        $known = in_array($result, ReachProbe::RESULTS, true);
        if ($known && is_array($session['probe'] ?? null)) {
            $session['probe']['result'] = $result;
            $this->save($session);
        }

        return Response::json(['recorded' => $known]);
    }

    // ------------------------------------------------------------------------------------------------- the run

    private function backups(): BackupService
    {
        return new BackupService($this->workspace->profiles(), $this->plugins, $this->workspace->runs(), $this->workspace->data(), $this->budgets, $this->clock);
    }

    private function catalog(): CatalogService
    {
        return new CatalogService($this->workspace->profiles(), $this->workspace->runs(), $this->budgets, $this->clock);
    }

    /**
     * The newest backup run on record that is worth looking at: one that is going on or interrupted, or a finished one (its page shows the archive
     * again). A failed one has left nothing behind and is dismissed.
     */
    private function latestRun(): ?Run
    {
        $service = $this->backups();
        foreach ($service->runs() as $run) {
            if ($run->status === RunStatus::Failed) {
                $service->dismiss($run->id);

                continue;
            }

            return $run;
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
            return $this->backups()->find($session['runId']);
        } catch (EngineException) {
            return null;
        }
    }

    /**
     * Starts the backup, once the probe says the archive is out of the web's reach (or the person says they made sure) and never when it says it can
     * be fetched.
     *
     * @param array<string, mixed> $session
     */
    private function start(Request $request, array $session): Response
    {
        if (($session['optionsDone'] ?? false) !== true) {
            return Response::redirect('?step=site');
        }
        $probe = is_array($session['probe'] ?? null) && is_string($session['probe']['result'] ?? null) ? $session['probe']['result'] : '';
        if ($probe === 'reachable') {
            return $this->summaryPage($session, 'The test file can be fetched through the web server, and so could the archive. Choose a directory for the archive outside the document root (on the options page), or ask the host to close this directory.');
        }
        if ($probe !== 'closed' && $request->post('probe_ok') !== '1') {
            return $this->summaryPage($session, 'Let the test above finish, or tick that you have made sure the directory of the archive cannot be opened in a browser.');
        }
        try {
            $run = $this->backups()->startWeb(BackupWorkspace::PROFILE, $request->post('force') === '1', is_string($session['passphrase'] ?? null) ? $session['passphrase'] : '');
        } catch (GuardFailed $e) {
            return $this->summaryPage($session, $e->getMessage());
        }
        $this->removeProbe($session);
        unset($session['probe']);
        $session['runId'] = $run->id;
        $this->save($session);

        return Response::redirect('?step=run');
    }

    /**
     * @param array<string, mixed> $session
     */
    private function removeProbe(array $session): void
    {
        $record = (array) ($session['probe'] ?? []);
        try {
            $this->probe->remove($record, $this->probeDirectories($this->service()->load(BackupWorkspace::PROFILE)->storage));
        } catch (ProfileException) {
            $this->probe->remove($record, [$this->workspace->directory()]);   // no profile, no storage: only the directory of the tool is left
        }
    }

    /**
     * @param array<string, mixed> $session
     */
    private function runPage(array $session): Response
    {
        $run = $this->currentRun($session);
        if ($run === null) {
            return Response::redirect(($session['optionsDone'] ?? false) === true ? '?step=summary' : '?step=site');
        }
        if ($run->status === RunStatus::Finished) {
            return Response::redirect('?step=done');
        }
        $csrf = (string) $session['csrf'];
        $failed = $run->status === RunStatus::Failed;
        $body = '<p>The backup works in steps; this page keeps it going. <strong>Keep this page open.</strong> If it is closed or the connection is lost, open the tool again: the backup goes on where it was.</p>'
            . RunView::panel($run, $this->percent($run), $failed)
            . $this->form(RunView::buttons($failed) . ' <button class="secondary" name="action" value="abandon" id="giveup">Give up this backup</button>', $csrf)
            . '<noscript><p class="help">Without JavaScript press "Continue" again and again until the page says it is done.</p></noscript>';

        return $this->page('Backing up', $body, 200, 'run', RunView::script($csrf, $failed, 'backup', self::HINT_403));
    }

    private function percent(Run $run): int
    {
        return (int) ($this->backups()->progress($run)['percent'] ?? 0);
    }

    /**
     * What the step that is working does right now (the page asks while the step runs), as JSON.
     *
     * @param array<string, mixed> $session
     */
    private function statusAnswer(array $session): Response
    {
        $run = $this->currentRun($session);

        return Response::json($run === null ? ['working' => false, 'note' => '', 'seconds' => 0, 'elapsed' => 0] : $this->backups()->live($run));
    }

    /**
     * One tick of the backup. With `format=json` (the page's script) the answer is data, otherwise a redirect back to the page.
     *
     * @param array<string, mixed> $session
     */
    private function tick(Request $request, array $session): Response
    {
        $run = $this->currentRun($session);
        $json = $request->post('format') === 'json';
        if ($run === null) {
            return $json ? Response::json(['status' => 'failed', 'error' => 'There is no backup to continue.']) : Response::redirect('?step=summary');
        }
        $answer = ['status' => 'running'];
        try {
            $run = $this->backups()->tick($run->id);
        } catch (EngineException $e) {
            $answer = $e->isBusy() ? ['status' => 'busy'] : ['status' => 'failed', 'error' => $e->getMessage()];
            $run = $this->currentRun($session) ?? $run;
        } catch (Throwable $e) {
            $answer = ['status' => 'failed', 'error' => 'The step failed: ' . $e->getMessage()];
            $run = $this->currentRun($session) ?? $run;
        }
        if ($run->status === RunStatus::Finished) {
            $answer = ['status' => 'finished'];
        }
        $answer += ['progress' => (string) $run->progress, 'percent' => $this->percent($run)];

        return $json ? Response::json($answer) : Response::redirect('?step=run');
    }

    /**
     * A failed backup has left nothing behind ({@see BackupService::tick()} cleans up), so trying again is starting again from the beginning.
     *
     * @param array<string, mixed> $session
     */
    private function retry(array $session): Response
    {
        $run = $this->currentRun($session);
        if ($run === null || $run->status !== RunStatus::Failed) {
            return Response::json(['status' => 'running']);
        }
        $service = $this->backups();
        try {
            $service->dismiss($run->id);
            $new = $service->startWeb(BackupWorkspace::PROFILE, (bool) ($run->state['force'] ?? false), is_string($session['passphrase'] ?? null) ? $session['passphrase'] : '');
        } catch (GuardFailed | EngineException $e) {
            return Response::json(['status' => 'failed', 'error' => $e->getMessage()]);
        }
        $session['runId'] = $new->id;
        $this->save($session);

        return Response::json(['status' => 'running']);
    }

    /**
     * @param array<string, mixed> $session
     */
    private function abandon(Request $request, array $session): Response
    {
        $json = $request->post('format') === 'json';
        $next = ($session['optionsDone'] ?? false) === true ? '?step=summary' : '?step=site';
        $run = $this->currentRun($session);
        if ($run !== null && $run->status === RunStatus::Finished && $json) {
            // the step that was working when the person pressed the button was the last one: giving up would remove the backup
            return Response::json(['status' => 'finished', 'next' => '?step=done']);
        }
        if ($run !== null && $run->status !== RunStatus::Finished) {
            try {
                $this->backups()->abandon($run->id);
            } catch (GuardFailed | EngineException) {
                // a step is working right now: the page of the person asks again until it has ended (the step ends within its time budget)
                return $json ? Response::json(['status' => 'busy']) : Response::redirect('?step=run');
            }
        }
        unset($session['runId']);
        $this->save($session);

        return $json ? Response::json(['status' => 'abandoned', 'next' => $next]) : Response::redirect($next);
    }

    /**
     * Back to the summary for another backup; the archive of this one stays.
     *
     * @param array<string, mixed> $session
     */
    private function again(array $session): Response
    {
        $run = $this->currentRun($session);
        if ($run !== null && $run->status === RunStatus::Finished) {
            $this->backups()->dismiss($run->id);
        }
        unset($session['runId']);
        $this->save($session);

        return Response::redirect(($session['optionsDone'] ?? false) === true ? '?step=summary' : '?step=site');
    }

    // ------------------------------------------------------------------------------------------------- done

    /**
     * @param array<string, mixed> $session
     */
    private function donePage(array $session, string $error = ''): Response
    {
        $run = $this->currentRun($session);
        if ($run === null || $run->status !== RunStatus::Finished) {
            return Response::redirect('?step=run');
        }
        $result = $this->backups()->result($run) ?? [];
        $csrf = (string) $session['csrf'];
        try {
            $entry = $this->catalog()->find(BackupWorkspace::PROFILE, (string) ($result['backup'] ?? ''));
            $directory = $this->catalog()->storageDirectory(BackupWorkspace::PROFILE);
        } catch (ProfileException | StorageException $e) {
            return $this->page('Done', '<p class="fail">The archive of this backup is not there any more: ' . Html::e($e->getMessage()) . '</p>' . $this->form('<button name="action" value="again">Back up again</button>', $csrf), 200, 'done');
        }
        $sidecar = $entry->sidecar;
        if ($sidecar === null) {
            return $this->page('Done', '<p class="fail">The archive of this backup cannot be read.</p>' . $this->form('<button name="action" value="again">Back up again</button>', $csrf), 200, 'done');
        }

        $parts = '';
        foreach ($sidecar->parts as $part) {
            $parts .= '<tr><td><code>' . Html::e($part->file) . '</code></td><td>' . Html::e(SpaceCheck::format($part->size)) . '</td><td><code>' . Html::e($part->sha256) . '</code></td>'
                . '<td><a class="button secondary" href="?step=file&amp;part=' . rawurlencode($part->file) . '">Download</a></td></tr>';
        }
        $warnings = '';
        foreach ($sidecar->manifest->warnings as $warning) {
            $warnings .= '<li>' . Html::e($warning) . '</li>';
        }
        $encrypted = $sidecar->encryption !== null;
        $first = $sidecar->parts[0]->file ?? 'BACKUP.zip';

        $body = ($error !== '' ? '<p class="fail">' . Html::e($error) . '</p>' : '')
            . ((int) ($result['files'] ?? 0) === 0
                ? '<p class="fail"><strong>No file was saved.</strong> The directory holds nothing but this tool (and the tool is never part of a backup). Go back and choose the directory of the site on the first page.</p>'
                : '<p class="ok"><strong>The backup is made.</strong></p>')
            . '<div class="box">' . Html::e((string) ($result['backup'] ?? '')) . ': ' . (int) ($result['files'] ?? 0) . ' files, ' . (int) ($result['tables'] ?? 0) . ' tables, '
            . Html::e(SpaceCheck::format((int) ($result['size'] ?? 0))) . ($encrypted ? ', encrypted' : ', <strong>not encrypted</strong>') . ', status ' . Html::e((string) ($result['status'] ?? '')) . '</div>'
            . ($warnings !== '' ? '<p>Please read:</p><ul>' . $warnings . '</ul>' : '')
            . '<h3>Fetch the backup</h3><table><thead><tr><th scope="col">Part</th><th scope="col">Size</th><th scope="col">SHA-256</th><th></th></tr></thead><tbody>' . $parts . '</tbody></table>'
            . '<p class="help">Compare the SHA-256 of what you fetched with the one above. A large backup is better fetched by SFTP than through the web server: <code>scp USER@HOST:' . Html::e($directory . '/' . $first) . ' .</code> or <code>rsync -av --partial USER@HOST:' . Html::e($directory) . '/ ./</code>.</p>'
            . ($encrypted ? '<p class="help">The backup is encrypted. Only the secret key or the passphrase you chose opens it, and this server does not have it.</p>' : '')
            . '<p class="help">To restore it on another server, put <code>threadsafe-restore.php</code> and its key file there, together with all the parts, and open it in a browser (see docs/RESTORE.md of ThreadSafe).</p>'
            . '<h3>When you have it</h3>'
            . $this->form('<label><input type="checkbox" name="sure" value="1">I have the backup somewhere else</label><button class="secondary" name="action" value="delete-archive">Delete the archive from this server</button> <button class="secondary" name="action" value="again">Back up again</button>', $csrf)
            . '<h3>Remove the tool</h3><p><strong>Do this when you are done.</strong> This tool can read the whole site for anyone who has its key. It deletes <code>' . Html::e($this->script) . '</code>, the key file and its own state (the data, the sessions).</p>'
            . $this->form('<label><input type="checkbox" name="archive" value="1">Also delete the archive</label><p class="help">Unticked, the archive stays in <code>' . Html::e($directory) . '</code> for you to fetch and delete.</p><button name="action" value="cleanup">Delete the tool now</button>', $csrf);

        return $this->page('Done', $body, 200, 'done');
    }

    /**
     * One part of the finished backup, sent with `Range` so that a broken download can be resumed. The address works for the session that made the
     * backup (the cookie is `SameSite=Strict`), for the parts the record of the backup names and for nothing else.
     *
     * @param array<string, mixed> $session
     */
    private function file(Request $request, array $session): Response
    {
        $run = $this->currentRun($session);
        $missing = new Response(404, ['Content-Type: text/plain; charset=utf-8'], "Not found.\n");
        if ($run === null || $run->status !== RunStatus::Finished) {
            return $missing;
        }
        $file = (string) $request->query('part');
        try {
            $part = $this->catalog()->part(BackupWorkspace::PROFILE, (string) ($this->backups()->result($run)['backup'] ?? ''), $file);
        } catch (ProfileException | StorageException) {
            return $missing;
        }

        return Download::response($request, $part['path'], $file, $part['sha256'], contentType: str_ends_with($file, '.age') ? 'application/octet-stream' : 'application/zip');
    }

    /**
     * @param array<string, mixed> $session
     */
    private function deleteArchive(Request $request, array $session): Response
    {
        $run = $this->currentRun($session);
        if ($run === null || $run->status !== RunStatus::Finished) {
            return Response::redirect('?step=run');
        }
        if ($request->post('sure') !== '1') {
            return $this->donePage($session, 'Tick that you have the backup somewhere else: deleted, it is gone.');
        }
        try {
            $this->catalog()->delete(BackupWorkspace::PROFILE, (string) ($this->backups()->result($run)['backup'] ?? ''));
        } catch (GuardFailed | ProfileException | StorageException $e) {
            return $this->donePage($session, $e->getMessage());
        }
        $this->backups()->dismiss($run->id);
        unset($session['runId']);
        $this->save($session);

        return $this->page('Archive deleted', '<p class="ok"><strong>The archive is deleted from this server.</strong></p>' . $this->form('<button name="action" value="again">Back up again</button>', (string) $session['csrf']), 200, 'done');
    }

    /**
     * Takes the tool off the server: itself, the key file and its state. The archive stays unless the person says it should go: it is what they came for.
     *
     * @param array<string, mixed> $session
     */
    private function cleanup(Request $request, array $session): Response
    {
        $run = $this->currentRun($session);
        if ($run === null || $run->status !== RunStatus::Finished) {
            return Response::redirect('?step=run');
        }
        $name = (string) ($this->backups()->result($run)['backup'] ?? '');
        try {
            $directory = $this->catalog()->storageDirectory(BackupWorkspace::PROFILE);
        } catch (ProfileException) {
            $directory = '';
        }
        $deleteArchive = $request->post('archive') === '1';
        $problems = [];
        if ($deleteArchive) {
            try {
                $this->catalog()->delete(BackupWorkspace::PROFILE, $name);
            } catch (GuardFailed | ProfileException | StorageException $e) {
                $problems[] = 'The archive could not be deleted: ' . $e->getMessage();
            }
        }
        $base = rtrim($this->baseDirectory, '/\\');
        $problems = [...$problems, ...Cleanup::run([$base . '/' . $this->script, $base . '/' . self::KEY_FILE], $this->workspace->directory(), $deleteArchive || $directory === '' ? [] : [$directory])];

        $body = $problems === []
            ? '<p class="ok"><strong>The tool is gone.</strong></p>' . ($deleteArchive ? '' : '<p>The archive is in <code>' . Html::e($directory) . '</code>. Fetch it, then delete that directory.</p>')
            : '<p class="fail"><strong>Not everything could be deleted.</strong> Delete these by hand, now:</p><ul><li>' . implode('</li><li>', array_map(Html::e(...), $problems)) . '</li></ul>';

        return $this->page('Tool removed', $body);
    }

    // ------------------------------------------------------------------------------------------------- output

    private function page(string $title, string $body, int $status = 200, ?string $step = null, string $script = ''): Response
    {
        return Response::html(Html::page($title, $body, $this->nonce, $step, $script, 'backup tool', self::STEPS), $status);
    }

    /** Headers every answer carries: nothing is cached, framed, sniffed or loaded from elsewhere. */
    private function secure(Response $response): Response
    {
        return new Response($response->status, [
            ...$response->headers,
            ...($response->header('Cache-Control') === null ? ['Cache-Control: no-store'] : []),
            'X-Content-Type-Options: nosniff',
            'X-Frame-Options: DENY',
            'Referrer-Policy: no-referrer',
            'Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()',
            'Cross-Origin-Opener-Policy: same-origin',
            'Cross-Origin-Resource-Policy: same-origin',
            "Content-Security-Policy: default-src 'none'; img-src data:; style-src 'nonce-{$this->nonce}'; script-src 'nonce-{$this->nonce}'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'",
        ], $response->body, $response->stream);
    }
}
