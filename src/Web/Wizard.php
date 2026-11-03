<?php

declare(strict_types=1);

namespace ThreadSafe\Web;

use Closure;
use Throwable;
use ThreadSafe\Backup\BackupName;
use ThreadSafe\Backup\Manifest;
use ThreadSafe\Database\Connection;
use ThreadSafe\Database\ConnectionFailed;
use ThreadSafe\Database\DatabaseError;
use ThreadSafe\Database\DatabaseSettings;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Engine\EngineException;
use ThreadSafe\Engine\Executor;
use ThreadSafe\Engine\Run;
use ThreadSafe\Engine\RunStatus;
use ThreadSafe\Engine\RunStore;
use ThreadSafe\Plugin\PluginError;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Plugin\Restorable;
use ThreadSafe\Crypto\CryptoException;
use ThreadSafe\Crypto\Unlock;
use ThreadSafe\Restore\BackupSource;
use ThreadSafe\Restore\Check;
use ThreadSafe\Restore\Field;
use ThreadSafe\Restore\RestoreException;
use ThreadSafe\Restore\RestoreRun;
use ThreadSafe\Restore\ToolGuard;
use ThreadSafe\Storage\SpaceCheck;

/**
 * The restore wizard (docs/PHASE3.md 3.9): server-rendered pages for a person who has uploaded the tool and a backup to a web
 * server and wants the site back. One address, the steps selected by `?step=`; state-changing requests are POSTs with a CSRF token.
 *
 * Nothing happens without the key file ({@see AccessKey}). The wizard keeps what the person chose in a session file, starts a
 * {@see RestoreRun} and then ticks it, one tick per request, from a few lines of JavaScript (or from a button without).
 *
 * Steps: login → backup → requirements → target → summary → run → done (where the tool deletes itself).
 */
final class Wizard
{
    public const string COOKIE = 'threadsafe_restore';

    private const string KEY_FILE = 'threadsafe-restore-key.php';

    /** What to do when the web server refuses a step of the restore with HTTP 403. */
    private const string HINT_403 = 'HTTP 403 usually means that a rule of the web server refuses this file: the .htaccess of the restored site (a security extension such as Admin Tools refuses direct requests for PHP files) or the host. Rename the .htaccess in this directory (for example to .htaccess.off), then press "Continue"; put it back when the restore is done.';

    private readonly string $stateDirectory;

    private readonly AccessKey $key;

    private readonly Sessions $sessions;

    private readonly ReachProbe $probe;

    private readonly PluginRegistry $plugins;

    private string $token = '';

    private string $nonce = '';

    /** Whether the person has shown the key (a login, or a session): only then does an error page say what went wrong and where. */
    private bool $trusted = false;

    /** The host this page is served at (the Host header, with its port), for the proposal of the new domain. */
    private string $host = '';

    /**
     * @param string                $baseDirectory the directory the tool and the key file are in (and where the site is restored to by default)
     * @param string                $script        the file name of the tool, deleted at the end
     * @param Closure(): int|null   $clock         for tests
     * @param Closure(): Budget|null $budgets      the budget of a tick; default: a share of the request time limit
     */
    public function __construct(
        private readonly string $baseDirectory,
        ?PluginRegistry $plugins = null,
        private readonly string $script = 'threadsafe-restore.php',
        ?Closure $clock = null,
        private readonly ?Closure $budgets = null,
    ) {
        $this->stateDirectory = rtrim($baseDirectory, '/\\') . '/' . StateDirectory::NAME;
        $this->key = new AccessKey(rtrim($baseDirectory, '/\\') . '/' . self::KEY_FILE, $this->stateDirectory . '/attempts.json', $this->stateDirectory . '/access.log', $clock);
        $this->sessions = new Sessions($this->stateDirectory . '/sessions', $clock);
        $this->probe = new ReachProbe($baseDirectory);
        $this->plugins = $plugins ?? PluginRegistry::load();
    }

    public function handle(Request $request): Response
    {
        $this->nonce = bin2hex(random_bytes(9));
        $host = strtolower(trim((string) $request->header('host')));
        $this->host = preg_match('~^[a-z0-9.-]+(:\d{1,5})?$~', $host) === 1 ? $host : '';
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
            @error_log('ThreadSafe restore tool: ' . $e->getMessage());

            return $this->page('Something went wrong', '<p class="fail">The tool could not do what was asked.</p><p class="help">Reload the page. If this stays, check that this program may write in its directory, or look in the error log of the host.</p>', 500);
        }

        return $this->page('Something went wrong', '<p class="fail">' . Html::e($e->getMessage()) . '</p><p class="help">Reload the page. If this stays, check that this program may write in <code>' . Html::e($this->stateDirectory) . '</code>.</p>', 500);
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
        StateDirectory::prepare($this->stateDirectory);

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

        $step = $request->query('step') ?? 'backup';
        $action = $request->isPost() ? (string) $request->post('action') : '';
        $response = match (true) {
            $action === 'logout' => $this->logout($token),
            $action === 'choose-backup' => $this->chooseBackup($request, $token, $session),
            $action === 'save-target', $action === 'test-db' => $this->saveTarget($request, $token, $session, $action === 'test-db'),
            $action === 'probe' => $this->recordProbe($request, $session),
            $action === 'start' => $this->start($request, $token, $session),
            $action === 'tick' => $this->tick($request, $session),
            $action === 'retry' => $this->retry($session),
            $action === 'cleanup' => $this->cleanup($request, $token, $session),
            default => $this->show($step, $request, $session),
        };

        return $response;
    }

    // ------------------------------------------------------------------------------------------------- login

    private function loginPage(Request $request, string $error = ''): Response
    {
        $warning = $this->plainHttp($request)
            ? '<p class="box fail"><strong>This connection is not encrypted.</strong> The key, and later the database password and the secret key or passphrase of an encrypted backup, cross the network in clear text. Use https if the host offers it; otherwise confirm below that you accept this.</p><label><input type="checkbox" name="plain" value="1"> I understand and accept this.</label>'
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
        [$token, $data] = $this->sessions->create();
        $data['plain'] = $this->plainHttp($request);
        // a restore that was started and not finished (the browser was closed, the connection lost, a step failed) goes on where it was:
        // starting again would meet its own half-restored files
        $unfinished = $this->unfinishedRun();
        if ($unfinished !== null) {
            $data['runId'] = $unfinished->id;
        }
        $this->sessions->save($token, $data);

        return Response::redirect($unfinished !== null ? '?step=run' : '?step=backup')->withHeader($this->cookie($token, $request));
    }

    private function logout(string $token): Response
    {
        $this->sessions->destroy($token);

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

    // ------------------------------------------------------------------------------------------------- pages of the steps

    /**
     * @param array<string, mixed> $session
     */
    private function show(string $step, Request $request, array $session): Response
    {
        if ($step === 'run') {
            return $this->runPage($session);
        }
        if ($step === 'status') {
            return $this->statusAnswer($session);
        }
        if ($step === 'done') {
            return $this->donePage($session);
        }
        $source = $this->source($session);
        if ($source === null) {
            return $step === 'backup' ? $this->backupPage($session) : Response::redirect('?step=backup');
        }

        return match ($step) {
            'requirements' => $this->requirementsPage($session, $source),
            'target' => $this->targetPage($session, $source),
            'summary' => $this->summaryPage($session, $source),
            default => $this->backupPage($session),
        };
    }

    /**
     * @param array<string, mixed> $session
     */
    private function source(array $session): ?BackupSource
    {
        if (!is_string($session['archive'] ?? null)) {
            return null;
        }
        try {
            return BackupSource::open($session['archive'], Unlock::fromArray($session['unlock'] ?? null));
        } catch (RestoreException | CryptoException) {
            return null;
        }
    }

    private function form(string $inner, string $csrf, string $extra = ''): string
    {
        return '<form method="post"' . $extra . '><input type="hidden" name="csrf" value="' . Html::e($csrf) . '">' . $inner . '</form>';
    }

    /**
     * @param array<string, mixed> $session
     */
    private function backupPage(array $session, string $error = ''): Response
    {
        $found = [];
        foreach (scandir($this->baseDirectory) ?: [] as $file) {
            $info = BackupName::fromFile($file);
            if ($info !== null && in_array($info['extension'], ['zip', 'zip.age'], true)) {
                $found[$info['name']->base()][] = $file;
            }
        }
        $list = '';
        foreach ($found as $base => $files) {
            sort($files);
            $list .= '<label><input type="radio" name="choice" value="' . Html::e($files[0]) . '"> ' . Html::e($base) . ' <span class="help">(' . count($files) . ' file' . (count($files) === 1 ? '' : 's') . (str_ends_with($files[0], '.age') ? ', encrypted' : '') . ')</span></label>';
        }
        $body = ($error !== '' ? '<p class="fail">' . Html::e($error) . '</p>' : '')
            . '<p>Which backup do you want to restore? Upload all its parts (<code>.zip</code> files, or <code>.zip.age</code> if it is encrypted) next to <code>' . Html::e($this->script) . '</code>, or name where they are.</p>'
            . ($list !== '' ? '<div class="box"><strong>Found next to the tool:</strong>' . $list . '</div>' : '<p class="help">No backup found next to the tool.</p>')
            . '<label for="path">Or the path of any part of the backup</label><input id="path" type="text" name="path" value="' . Html::e((string) ($session['archive'] ?? '')) . '">'
            . '<label for="unlock">Secret key or passphrase (only for an encrypted backup)</label><input id="unlock" type="password" name="unlock" autocomplete="off">'
            . '<p class="help">The line <code>AGE-SECRET-KEY-1...</code> of the key file, or the passphrase. It is kept in a private file of this tool until the files are unpacked, then blanked. Without it an encrypted backup cannot be opened by anyone.</p>'
            . '<button>Look at this backup</button>';

        return $this->page('Choose the backup', $this->form('<input type="hidden" name="action" value="choose-backup">' . $body, (string) $session['csrf']), 200, 'backup');
    }

    /**
     * @param array<string, mixed> $session
     */
    private function chooseBackup(Request $request, string $token, array $session): Response
    {
        $given = trim((string) ($request->post('path') ?: $request->post('choice')));
        if ($given === '') {
            return $this->backupPage($session, 'Choose a backup or enter a path.');
        }
        $path = preg_match('~^(/|[A-Za-z]:[\\\\/])~', $given) === 1 ? $given : rtrim($this->baseDirectory, '/\\') . '/' . $given;
        try {
            $typed = trim((string) $request->post('unlock'));
            $unlock = $typed !== '' ? Unlock::fromText((string) $request->post('unlock')) : Unlock::fromArray($session['unlock'] ?? null);
            $source = BackupSource::open($path, $unlock);
            $this->plugins->restorable($source->manifest->type);
        } catch (RestoreException | PluginError | CryptoException $e) {
            return $this->backupPage($session, $e->getMessage());
        }
        $session['archive'] = $source->parts[0];
        $session['unlock'] = $source->unlock?->toArray();
        unset($session['values'], $session['runId']);
        $this->sessions->save($token, $session);

        return Response::redirect('?step=requirements');
    }

    /**
     * @param array<string, mixed> $session
     */
    private function requirementsPage(array $session, BackupSource $source): Response
    {
        $manifest = $source->manifest;
        $checks = $this->hostChecks($manifest);
        $errors = array_filter($checks, static fn (Check $c): bool => $c->isError());
        $version = $manifest->application['version'];
        $body = '<div class="box"><strong>' . Html::e($manifest->backupId) . '</strong><br>' . Html::e($manifest->application['name'] . ($version === null ? '' : ' ' . $version))
            . ', made ' . Html::e($manifest->finished->format('Y-m-d H:i')) . ' UTC, ' . count($source->parts) . ' part(s)<br>'
            . $manifest->counts['files'] . ' files (' . Html::e(SpaceCheck::format($manifest->counts['fileBytes'])) . ')'
            . ($manifest->database === [] ? ', no database' : ', ' . $manifest->counts['tables'] . ' tables (' . Html::e(SpaceCheck::format($manifest->counts['databaseBytes'])) . ' of SQL)') . '</div>'
            . '<p>What this server offers:</p>' . Html::checks($checks)
            . $this->form(($errors === [] ? '<a class="button" href="?step=target">Next: where to restore</a>' : '<p class="fail">Fix the problems above, then reload this page.</p>') . '<a class="button secondary" href="?step=backup">Another backup</a>', (string) $session['csrf']);

        return $this->page('Requirements', $body, 200, 'requirements');
    }

    /**
     * What the host offers, independent of where the person wants to restore to (that is checked in the summary).
     *
     * @return list<Check>
     */
    private function hostChecks(Manifest $manifest): array
    {
        $checks = [Check::info('PHP ' . PHP_VERSION . ' (' . PHP_SAPI . ')')];
        foreach (['zlib', 'mbstring', 'json'] as $extension) {
            if (!extension_loaded($extension)) {
                $checks[] = Check::error("The PHP extension $extension is missing.", 'Ask the host to enable it.');
            }
        }
        if ($manifest->database !== [] && !extension_loaded('pdo_mysql') && !extension_loaded('mysqli')) {
            $checks[] = Check::error('The backup holds a database, but PHP has neither pdo_mysql nor mysqli.', 'Ask the host to enable one of them.');
        }
        if (!is_writable($this->baseDirectory)) {
            $checks[] = Check::error('The directory of this tool is not writable for PHP: ' . $this->baseDirectory, 'Change its permissions.');
        }
        $limit = (int) ini_get('max_execution_time');
        $checks[] = Check::info($limit > 0 ? "A request may run $limit seconds; the restore works in steps of a part of that and goes on by itself." : 'A request may run without a time limit.');
        $memory = Budget::parseBytes((string) ini_get('memory_limit'));
        if ($memory !== null && $memory < 128 * 1048576) {
            $checks[] = Check::warning('memory_limit is ' . SpaceCheck::format($memory) . '; the restore needs little, but a very large database cell may not fit.');
        }
        if ((string) ini_get('open_basedir') !== '') {
            $checks[] = Check::warning('open_basedir is set (' . ini_get('open_basedir') . '): the restore can only use these directories.');
        }
        $free = @disk_free_space($this->baseDirectory);
        $needed = (int) ceil(($manifest->counts['fileBytes'] + $manifest->counts['databaseBytes']) * 1.1);
        if ($free !== false && $free < $needed) {
            $checks[] = Check::error('Not enough free space here: ' . SpaceCheck::format((int) $free) . ' free, about ' . SpaceCheck::format($needed) . ' needed.');
        }

        return $checks;
    }

    /**
     * @param array<string, mixed> $session
     */
    private function targetPage(array $session, BackupSource $source, string $message = ''): Response
    {
        $manifest = $source->manifest;
        /** @var array<string, string> $values */
        $values = (array) ($session['values'] ?? []);
        $value = static fn (string $name, string $default = ''): string => Html::e($values[$name] ?? $default);
        $text = static function (string $name, string $label, string $current, string $help = '', string $type = 'text'): string {
            return '<label for="' . $name . '">' . Html::e($label) . '</label><input id="' . $name . '" type="' . $type . '" name="' . $name . '" value="' . $current . '"' . ($type === 'password' ? ' autocomplete="off"' : '') . '>'
                . ($help !== '' ? '<span class="help">' . Html::e($help) . '</span>' : '');
        };

        $html = $message . $text('root', 'Directory to restore the site into', $value('root', $this->baseDirectory), 'It must be empty, except for this tool and the backup. It is created if it does not exist.');
        if ($manifest->database !== []) {
            $html .= '<h3>Database (it must exist and be empty)</h3>'
                . $text('db_host', 'Host', $value('db_host', 'localhost'))
                . $text('db_port', 'Port', $value('db_port', '3306'))
                . $text('db_name', 'Database name', $value('db_name'))
                . $text('db_user', 'User', $value('db_user'))
                . $text('db_password', 'Password', $value('db_password'), 'It is kept in a private file of this tool until the restore is done, then blanked.', 'password')
                . $text('db_prefix', 'Table prefix', $value('db_prefix', (string) ($manifest->database['prefix'] ?? '')), 'The prefix of the backup is ' . (string) ($manifest->database['prefix'] ?? '') . '; another one can be used if the backup has no views or triggers.')
                . '<button class="secondary" name="action" value="test-db">Test the connection</button>';
        }
        $type = $this->plugins->restorable($manifest->type);
        $fields = $type->fields($manifest);
        if ($fields !== []) {
            $html .= '<h3>The site</h3>';
            foreach ($fields as $field) {
                $html .= $this->fieldHtml($field, $values['f_' . $field->name] ?? $this->defaultOf($field));
            }
        }
        $html .= '<h3>Options</h3><label><input type="checkbox" name="allow_leftovers" value="1"' . (($values['allow_leftovers'] ?? '') === '1' ? ' checked' : '') . '>Let placeholder files of the web server (.htaccess, index.html) stay in the directory</label>'
            . '<label class="danger"><input type="checkbox" name="drop_tables" value="1"' . (($values['drop_tables'] ?? '') === '1' ? ' checked' : '') . '>Delete the existing tables of the database first (only if it is not empty)</label>'
            . '<p class="help"><strong>Warning:</strong> with this box ticked the restore <strong>deletes every table and view</strong> of the database whose name begins with the table prefix above, with all their data, before it imports the backup. It cannot be undone. Leave it off for an empty database; use it only for a database whose tables you want to replace, and only if you have a copy of what you still need.</p>'
            . '<label><input type="checkbox" name="self_test" value="1"' . (($values['self_test'] ?? '') === '1' ? ' checked' : '') . '>Check the restored site over HTTP at the end (the new domain must already reach this server)</label>'
            . '<p><button name="action" value="save-target">Next: summary</button> <a class="button secondary" href="?step=requirements">Back</a></p>';

        return $this->page('Where to restore', $this->form($html, (string) $session['csrf']), 200, 'target');
    }

    /**
     * What a field of the site starts with. The new domain starts with the address this page is served at: a restore puts the site where the tool
     * is, and an empty field would silently keep the old domain in every link and in the settings (the field test: the administration of the
     * restored site sent the visitor back to the original site). A person who restores elsewhere clears or changes it.
     */
    private function defaultOf(Field $field): string
    {
        return $field->proposeHost && $field->default === '' && $field->kind === Field::TEXT ? $this->host : $field->default;
    }

    private function fieldHtml(Field $field, string $current): string
    {
        $name = 'f_' . $field->name;
        $label = '<label for="' . $name . '">' . Html::e($field->label) . ($field->required ? ' *' : '') . '</label>';
        $help = $field->help !== '' ? '<span class="help">' . Html::e($field->help) . '</span>' : '';
        if ($field->kind === Field::CHOICE) {
            $options = '';
            foreach ($field->choices as $choice) {
                $options .= '<option value="' . Html::e($choice) . '"' . ($choice === $current ? ' selected' : '') . '>' . Html::e($choice === '' ? '(unchanged)' : $choice) . '</option>';
            }

            return $label . '<select id="' . $name . '" name="' . $name . '">' . $options . '</select>' . $help;
        }

        return $label . '<input id="' . $name . '" type="text" name="' . $name . '" value="' . Html::e($current) . '">' . $help;
    }

    /**
     * @param array<string, mixed> $session
     */
    private function saveTarget(Request $request, string $token, array $session, bool $testOnly): Response
    {
        $source = $this->source($session);
        if ($source === null) {
            return Response::redirect('?step=backup');
        }
        $values = [];
        foreach ($request->post as $name => $value) {
            if (preg_match('/^(root|db_[a-z]+|f_[A-Za-z]+|allow_leftovers|drop_tables|self_test)$/', $name) === 1) {
                $values[$name] = trim($value);
            }
        }
        $session['values'] = $values;
        $this->sessions->save($token, $session);

        if ($testOnly) {
            return $this->targetPage($session, $source, $this->testConnection($values));
        }
        $problems = [];
        if (($values['root'] ?? '') === '') {
            $problems[] = 'Enter the directory to restore into.';
        }
        if ($source->manifest->database !== []) {
            foreach (['db_host' => 'host', 'db_name' => 'database name', 'db_user' => 'user'] as $key => $label) {
                if (($values[$key] ?? '') === '') {
                    $problems[] = "Enter the database $label.";
                }
            }
            if (($values['db_port'] ?? '3306') !== '' && !ctype_digit($values['db_port'] ?? '3306')) {
                $problems[] = 'The database port is a number.';
            }
        }
        $type = $this->plugins->restorable($source->manifest->type);
        foreach ($type->fields($source->manifest) as $field) {
            if ($field->required && ($values['f_' . $field->name] ?? '') === '') {
                $problems[] = 'Enter: ' . $field->label . '.';
            }
        }
        if ($problems !== []) {
            return $this->targetPage($session, $source, '<ul class="fail"><li>' . implode('</li><li>', array_map(Html::e(...), $problems)) . '</li></ul>');
        }

        return Response::redirect('?step=summary');
    }

    /**
     * @param array<string, string> $values
     */
    private function testConnection(array $values): string
    {
        try {
            $db = Connection::open($this->databaseSettings($values));
            $tables = (int) $db->scalar('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');

            return '<p class="ok">Connected to ' . Html::e($db->server->describe()) . ' (' . Html::e($db->driverName()) . '); the database holds ' . $tables . ' table(s).' . ($tables > 0 ? ' A restore needs it empty (or a prefix no table uses).' : '') . '</p>';
        } catch (ConnectionFailed | DatabaseError $e) {
            return '<p class="fail">' . Html::e($e->getMessage()) . '</p>';
        }
    }

    /**
     * @param array<string, string> $values
     */
    private function databaseSettings(array $values): DatabaseSettings
    {
        return new DatabaseSettings(
            $values['db_host'] ?? '',
            (int) ($values['db_port'] ?? 3306) ?: 3306,
            $values['db_name'] ?? '',
            $values['db_user'] ?? '',
            $values['db_password'] ?? '',
            $values['db_prefix'] ?? '',
            DatabaseSettings::AUTO,
        );
    }

    /**
     * The parameters of the restore run from what the person chose.
     *
     * @param array<string, mixed> $session
     *
     * @return array<string, mixed>
     */
    private function runParameters(array $session, BackupSource $source): array
    {
        /** @var array<string, string> $values */
        $values = (array) ($session['values'] ?? []);
        $base = rtrim($this->baseDirectory, '/\\');
        $root = $values['root'] ?? $base;
        $root = preg_match('~^(/|[A-Za-z]:[\\\\/])~', $root) === 1 ? $root : $base . '/' . $root;
        $manifest = $source->manifest;
        $type = $this->plugins->restorable($manifest->type);
        $fieldValues = [];
        foreach ($type->fields($manifest) as $field) {
            $fieldValues[$field->name] = $values['f_' . $field->name] ?? '';
        }
        $settings = [...$type->restoreSettings($fieldValues), 'selfTest' => ($values['self_test'] ?? '') === '1'];
        if ($manifest->database !== []) {
            $database = $this->databaseSettings($values);
            $settings['database'] = $database->toArray();
        }

        return [
            'archive' => $source->parts[0],
            'unlock' => $source->unlock?->toArray(),
            'root' => rtrim($root, '/\\'),
            'work' => $this->stateDirectory,
            'journalDirectory' => $this->stateDirectory . '/journal',
            'allowLeftovers' => ($values['allow_leftovers'] ?? '') === '1',
            'dropTables' => ($values['drop_tables'] ?? '') === '1',
            'protected' => [$this->script, self::KEY_FILE],
            // the .htaccess of the restored site must not lock the tool out (a security extension refuses every PHP file but index.php)
            'guard' => rtrim($root, '/\\') === $base ? [$this->script] : [],
            'settings' => $settings,
        ];
    }

    /**
     * @param array<string, mixed> $session
     */
    private function summaryPage(array $session, BackupSource $source, string $error = ''): Response
    {
        $task = new RestoreRun($this->plugins);
        try {
            $state = $task->initialState($this->runParameters($session, $source));
            $inspection = $task->inspect($state);
        } catch (RestoreException $e) {
            return $this->targetPage($session, $source, '<p class="fail">' . Html::e($e->getMessage()) . '</p>');
        }
        $manifest = $source->manifest;
        $rows = [
            ['Backup', $manifest->backupId],
            ['Into the directory', (string) $state['root']],
        ];
        if ($inspection->database !== null) {
            $rows[] = ['Database', $inspection->database->name . ' on ' . $inspection->database->host . ', prefix "' . $inspection->database->prefix . '"'];
        }
        if (isset($inspection->settings['from'], $inspection->settings['to'])) {
            $rows[] = ['Domain', $inspection->settings['from'] . ' -> ' . $inspection->settings['to']];
        }
        $table = '<table>';
        foreach ($rows as [$label, $content]) {
            $table .= '<tr><th>' . Html::e($label) . '</th><td>' . Html::e((string) $content) . '</td></tr>';
        }
        $table .= '</table>';

        $plain = ($session['plain'] ?? false) === true;
        $probe = $this->probe->prepare((array) ($session['probe'] ?? []), [$this->stateDirectory]);
        if ($probe !== ($session['probe'] ?? null)) {
            $session['probe'] = $probe;
            $this->sessions->save($this->token, $session);
        }
        $start = $inspection->ok()
            ? '<button name="action" value="start" id="start"' . ($probe['result'] === 'reachable' ? ' disabled' : '') . '>Restore now</button>'
            : '<p class="fail">Fix the problems and look at this page again.</p>';
        $body = ($error !== '' ? '<p class="fail">' . Html::e($error) . '</p>' : '')
            . '<div class="box">' . $table . '</div>' . Html::checks($inspection->checks)
            . '<h3>Can the files of the restore be fetched through the web server?</h3>'
            . '<p id="probe-result" class="' . ($probe['result'] === 'reachable' ? 'fail' : '') . '">' . Html::e($this->probeText($probe['result'])) . '</p>'
            . '<p class="help">The restore unpacks the database as plain SQL files into <code>' . Html::e(StateDirectory::NAME) . '</code> and keeps its journal there. The page asks the web server for a small file in this directory. Apache reads the <code>.htaccess</code> that closes it (if the host lets <code>.htaccess</code> files count); nginx and IIS do not: add a rule, for nginx <code>location ~ /\.threadsafe-restore { deny all; }</code>, and look at this page again.</p>'
            . '<p class="help">Nothing has been written yet. ' . (((bool) ($state['dropTables'] ?? false)) ? 'The restore never overwrites or deletes files that are already there; the tables named above are the one thing it deletes, because you asked.' : 'The restore never overwrites or deletes anything that is already there.') . ($plain ? ' This connection is not encrypted.' : '') . '</p>'
            . $this->form($start . ' <a class="button secondary" href="?step=target">Back</a>', (string) $session['csrf']);

        $texts = ['closed' => $this->probeText('closed'), 'reachable' => $this->probeText('reachable'), 'unknown' => $this->probeText('unknown')];

        return $this->page('Summary', $body, 200, 'summary', $inspection->ok() ? $this->probe->script((string) $session['csrf'], $this->probe->urls($probe['file'], [$this->stateDirectory]), $probe['token'], $texts) : '');
    }

    private function probeText(string $result): string
    {
        return match ($result) {
            'closed' => 'No: the web server does not hand the test file out.',
            'reachable' => 'Yes: the test file can be fetched, and so could the database and the journal of the restore. Close the directory (see below), then look at this page again.',
            'unknown' => 'The test could not be made (the page needs JavaScript for it). Make sure by hand that the directory ' . StateDirectory::NAME . ' cannot be opened in a browser.',
            default => 'The page is asking the web server ...',
        };
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
            $this->sessions->save($this->token, $session);
        }

        return Response::json(['recorded' => $known]);
    }

    /**
     * @param array<string, mixed> $session
     */
    private function start(Request $request, string $token, array $session): Response
    {
        $source = $this->source($session);
        if ($source === null) {
            return Response::redirect('?step=backup');
        }
        $task = new RestoreRun($this->plugins);
        try {
            $state = $task->initialState($this->runParameters($session, $source));
            $inspection = $task->inspect($state);
        } catch (RestoreException $e) {
            return $this->summaryPage($session, $source, $e->getMessage());
        }
        if (!$inspection->ok()) {
            return Response::redirect('?step=summary');
        }
        if (is_array($session['probe'] ?? null) && ($session['probe']['result'] ?? null) === 'reachable') {
            return $this->summaryPage($session, $source, 'The test file can be fetched through the web server, and so could the database and the journal of the restore. Close the directory ' . StateDirectory::NAME . ' first (the rule for nginx is below), then look at this page again.');
        }
        $run = $this->store()->create($task->id(), $state);
        $session['runId'] = $run->id;
        unset($session['unlock']);   // the run has it now, until the files are unpacked
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
        return new Executor($this->store(), new RestoreRun($this->plugins), $this->budgets ?? Budget::forThisProcess(...));
    }

    /** The newest restore run that is neither finished nor gone: running (or interrupted) or failed. */
    private function unfinishedRun(): ?Run
    {
        foreach ($this->store()->all('restore') as $run) {
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
            return Response::redirect('?step=summary');
        }
        if ($run->status === RunStatus::Finished) {
            return Response::redirect('?step=done');
        }
        $csrf = (string) $session['csrf'];
        $failed = $run->status === RunStatus::Failed;
        $body = '<p>The restore works in steps; this page keeps it going. <strong>Keep this page open.</strong> If it is closed or the connection is lost, open the tool again and continue here.</p>'
            . RunView::panel($run, $this->percent($run), $failed)
            . $this->form(RunView::buttons($failed), $csrf)
            . '<noscript><p class="help">Without JavaScript press "Continue" again and again until the page says it is done.</p></noscript>';

        return $this->page('Restoring', $body, 200, 'run', RunView::script($csrf, $failed, 'restore', self::HINT_403));
    }

    /**
     * What the step that is working does right now (the page asks while the step runs), as JSON.
     *
     * @param array<string, mixed> $session
     */
    private function statusAnswer(array $session): Response
    {
        $run = $this->currentRun($session);

        return Response::json($run === null ? ['working' => false, 'note' => '', 'seconds' => 0, 'elapsed' => 0] : RunView::live($this->store(), $run));
    }

    /**
     * One tick of the restore. With `format=json` (the page's script) the answer is data, otherwise a redirect back to the page.
     *
     * @param array<string, mixed> $session
     */
    private function tick(Request $request, array $session): Response
    {
        $run = $this->currentRun($session);
        $json = $request->post('format') === 'json';
        if ($run === null) {
            return $json ? Response::json(['status' => 'failed', 'error' => 'There is no restore run to continue.']) : Response::redirect('?step=summary');
        }
        $answer = ['status' => 'running'];
        try {
            $run = $this->executor()->execute($run->id, 1);
        } catch (EngineException $e) {
            $answer = str_contains($e->getMessage(), 'another process') ? ['status' => 'busy'] : ['status' => 'failed', 'error' => $e->getMessage()];
            $run = $this->currentRun($session) ?? $run;
        } catch (RestoreException $e) {
            $answer = ['status' => 'failed', 'error' => $e->getMessage()];
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

    /** How far the restore is, from the state of the run: the phases weigh 2, 40, 40, 16 and 2 percent. */
    private function percent(Run $run): int
    {
        if ($run->status === RunStatus::Finished) {
            return 100;
        }
        $state = $run->state;
        $ratio = static fn (mixed $part, string $done, string $total): float => is_array($part) && (int) ($part[$total] ?? 0) > 0 ? min(1.0, (int) ($part[$done] ?? 0) / (int) $part[$total]) : 0.0;

        return match ($state['phase'] ?? 'preflight') {
            'extract' => (int) round(2 + 40 * $ratio($state['extract'] ?? null, 'bytes', 'total')),
            'import' => (int) round(42 + 40 * $ratio($state['import'] ?? null, 'bytes', 'total')),
            'adapt' => 82 + (int) round(16 * min(1.0, (int) ($state['adapt']['next'] ?? 0) / 12)),
            'finalize' => 98,
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
        $result = (array) ($run->state['result'] ?? []);
        $warnings = '';
        foreach ((array) ($result['warnings'] ?? []) as $warning) {
            $warnings .= '<li>' . Html::e((string) $warning) . '</li>';
        }
        $steps = '';
        foreach ((array) ($result['report']['steps'] ?? []) as $step) {
            $steps .= '<tr><td>' . Html::e((string) ($step['title'] ?? '')) . '</td><td>' . Html::e((string) ($step['status'] ?? '')) . '</td></tr>';
        }
        $body = '<p class="ok"><strong>The backup is restored.</strong></p><div class="box">'
            . (int) ($result['files'] ?? 0) . ' files and ' . (int) ($result['directories'] ?? 0) . ' directories (' . Html::e(SpaceCheck::format((int) ($result['bytes'] ?? 0))) . ')'
            . ((int) ($result['statements'] ?? 0) > 0 ? ', ' . (int) $result['statements'] . ' SQL statements' : '') . ' into <code>' . Html::e((string) ($result['root'] ?? '')) . '</code></div>'
            . ($warnings !== '' ? '<p>Please read:</p><ul>' . $warnings . '</ul>' : '')
            . ($steps !== '' ? '<details><summary>What the adaptation did</summary><table>' . $steps . '</table></details>' : '')
            . '<h3>Remove the tool</h3><p><strong>Do this now.</strong> This tool can unpack a backup into this directory for anyone who has the key. It deletes <code>' . Html::e($this->script) . '</code>, the key file and its own state (the journal, the run).</p>'
            . $this->form('<label><input type="checkbox" name="archives" value="1">Also delete the backup files (<code>.zip</code> or <code>.zip.age</code>) next to the tool</label><button name="action" value="cleanup">Delete the tool now</button>', (string) $session['csrf']);

        return $this->page('Done', $body, 200, 'done');
    }

    /**
     * @param array<string, mixed> $session
     */
    private function cleanup(Request $request, string $token, array $session): Response
    {
        $run = $this->currentRun($session);
        if ($run === null || $run->status !== RunStatus::Finished) {
            return Response::redirect('?step=run');
        }
        $base = rtrim($this->baseDirectory, '/\\');
        $files = [$base . '/' . $this->script, $base . '/' . self::KEY_FILE];
        if ($request->post('archives') === '1') {
            foreach ((array) ($run->state['parts'] ?? []) as $part) {
                if (is_string($part) && dirname($part) === $base) {
                    $files[] = $part;
                    $sidecar = preg_replace('/(-p\d+)?\.zip(\.age)?$/', '.json', $part);
                    if (is_string($sidecar)) {
                        $files[] = $sidecar;
                    }
                }
            }
        }
        // what the buttons need is read before the files go: the archive and the restored root
        $buttons = $this->siteButtons($request, $session, (string) ($run->state['root'] ?? $base));
        // the tool is leaving: the block of its .htaccess goes with it
        ToolGuard::release((string) ($run->state['root'] ?? $base));
        $problems = Cleanup::run($files, $this->stateDirectory);

        $body = $problems === []
            ? '<p class="ok"><strong>The tool is gone.</strong> The restored site is in place.</p>' . $buttons
            : '<p class="fail"><strong>Not everything could be deleted.</strong> Delete these by hand, now:</p><ul><li>' . implode('</li><li>', array_map(Html::e(...), $problems)) . '</li></ul>';

        return $this->page('Tool removed', $body);
    }

    /**
     * The buttons that open the restored site and the login of its administration (what the website type knows), for the page that says
     * the tool is gone. The address is the domain of the field "new domain" (the host of this page if it was left empty) under the scheme of this page.
     *
     * @param array<string, mixed> $session
     */
    private function siteButtons(Request $request, array $session, string $root): string
    {
        $source = $this->source($session);
        if ($source === null) {
            return '';
        }
        /** @var array<string, string> $values */
        $values = (array) ($session['values'] ?? []);
        $type = $this->plugins->restorable($source->manifest->type);
        $fieldValues = [];
        foreach ($type->fields($source->manifest) as $field) {
            $fieldValues[$field->name] = $values['f_' . $field->name] ?? '';
        }
        $served = $request->path === '' || !str_contains($request->path, '/') ? '/' : substr($request->path, 0, (int) strrpos($request->path, '/') + 1);
        $domain = strtolower(trim($fieldValues['to'] ?? ''));
        $domain = preg_match('~^[a-z0-9.-]+(:\d{1,5})?$~', $domain) === 1 ? $domain : $this->host;
        if ($domain === '') {
            return '';
        }
        $origin = ($request->https ? 'https://' : 'http://') . $domain;
        $html = '';
        foreach ($type->links($source->manifest, $root, $fieldValues, $served) as $i => $link) {
            $html .= '<a class="button' . ($i > 0 ? ' secondary' : '') . '" href="' . Html::e($origin . $link['path']) . '">' . Html::e($link['label']) . '</a> ';
        }

        return $html === '' ? '' : '<p>' . $html . '</p>';
    }

    // ------------------------------------------------------------------------------------------------- output

    private function page(string $title, string $body, int $status = 200, ?string $step = null, string $script = ''): Response
    {
        return Response::html(Html::page($title, $body, $this->nonce, $step, $script), $status);
    }

    /** Headers every answer carries: nothing is cached, framed, sniffed or loaded from elsewhere. */
    private function secure(Response $response): Response
    {
        return (new Response($response->status, [
            ...$response->headers,
            'Cache-Control: no-store',
            'X-Content-Type-Options: nosniff',
            'X-Frame-Options: DENY',
            'Referrer-Policy: no-referrer',
            "Content-Security-Policy: default-src 'none'; img-src data:; style-src 'nonce-{$this->nonce}'; script-src 'nonce-{$this->nonce}'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'",
        ], $response->body));
    }
}
