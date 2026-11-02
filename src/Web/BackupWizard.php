<?php

declare(strict_types=1);

namespace ThreadSafe\Web;

use Closure;
use Throwable;
use ThreadSafe\App\Detection;
use ThreadSafe\App\ProfileService;
use ThreadSafe\Backup\Filters;
use ThreadSafe\Core\OpenBasedir;
use ThreadSafe\Crypto\AgeKeys;
use ThreadSafe\Crypto\CryptoException;
use ThreadSafe\Crypto\Passphrases;
use ThreadSafe\Database\DatabaseSettings;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Profile\CheckLevel;
use ThreadSafe\Profile\EncryptionSettings;
use ThreadSafe\Profile\ProfileException;
use ThreadSafe\Profile\SiteProfile;
use ThreadSafe\Profile\TestReport;
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
 * Steps: key → site → options → summary (with the probe that the archive cannot be fetched through the web server).
 */
final class BackupWizard
{
    public const string COOKIE = 'threadsafe_backup';

    public const string KEY_FILE = 'threadsafe-backup-key.php';

    private const array STEPS = ['site' => 'Site', 'options' => 'Options', 'summary' => 'Summary', 'run' => 'Backup', 'done' => 'Done'];

    private readonly BackupWorkspace $workspace;

    private readonly BackupTarget $target;

    private readonly AccessKey $key;

    private readonly PluginRegistry $plugins;

    /** @var Closure(): int */
    private readonly Closure $clock;

    private ?Sessions $sessions = null;

    private string $token = '';

    private string $nonce = '';

    /**
     * @param string              $baseDirectory the directory the tool and the key file are in
     * @param string              $script        the file name of the tool
     * @param Closure(): int|null $clock         for tests
     */
    public function __construct(
        private readonly string $baseDirectory,
        ?PluginRegistry $plugins = null,
        private readonly string $script = 'threadsafe-backup.php',
        ?Closure $clock = null,
    ) {
        $this->workspace = BackupWorkspace::in($baseDirectory);
        $this->target = new BackupTarget($baseDirectory, $script, self::KEY_FILE);
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
            return $this->secure($this->page('Something went wrong', '<p class="fail">' . Html::e($e->getMessage()) . '</p><p class="help">Reload the page. If this stays, check that this program may write in <code>' . Html::e($this->workspace->directory()) . '</code>.</p>', 500));
        }
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
            default => $this->show($request->query('step') ?? 'site', $session),
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
        [$token, $data] = $this->sessions()->create();
        $data['plain'] = $this->plainHttp($request);
        $this->sessions()->save($token, $data);

        return Response::redirect('?step=site')->withHeader($this->cookie($token, $request));
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
    private function show(string $step, array $session): Response
    {
        return match (true) {
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
    private function summaryPage(array $session): Response
    {
        try {
            $profile = $this->service()->load(BackupWorkspace::PROFILE);
            $this->prepareStorage($profile->storage);
        } catch (ProfileException | StorageException $e) {
            return $this->optionsPage($session, $e->getMessage());
        }
        $probe = $this->probeFile($session, $profile->storage);
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

        $result = is_string(($session['probe']['result'] ?? null)) ? $session['probe']['result'] : '';
        $body = '<div class="box">' . $table . '</div>'
            . '<h3>Can the archive be fetched through the web server?</h3>'
            . '<p id="probe-result" class="' . ($result === 'reachable' ? 'fail' : '') . '">' . Html::e($this->probeText($result)) . '</p>'
            . '<p class="help">The page asks the web server for a small file in the directory of the archive. If it can be fetched, so could the archive: choose a directory outside the document root on the options page.</p>'
            . '<p><a class="button secondary" href="?step=options">Back</a></p>'
            . '<p class="help">Nothing has been written yet except the small test file. Starting the backup comes with the next step of the tool (docs/PHASE12.md, work package 5).</p>';

        return $this->page('Summary', $body, 200, 'summary', $this->probeScript((string) $session['csrf'], $probe));
    }

    private function probeText(string $result): string
    {
        return match ($result) {
            'closed' => 'No: the web server does not hand the test file out.',
            'reachable' => 'Yes: the test file can be fetched. Choose a directory for the archive outside the document root, or ask the host to close this directory.',
            'unknown' => 'The test could not be made (the page needs JavaScript for it). Make sure by hand that the directory of the archive cannot be opened in a browser.',
            default => 'The page is asking the web server ...',
        };
    }

    /**
     * Writes the small file the probe asks for, once per session.
     *
     * @param array<string, mixed> $session
     *
     * @return array{url: string, token: string}
     */
    private function probeFile(array $session, string $storage): array
    {
        $probe = (array) ($session['probe'] ?? []);
        $file = is_string($probe['file'] ?? null) ? $probe['file'] : '';
        $token = is_string($probe['token'] ?? null) ? $probe['token'] : '';
        if ($file === '' || $token === '' || !is_file($storage . '/' . $file)) {
            $file = 'probe-' . bin2hex(random_bytes(8)) . '.txt';
            $token = bin2hex(random_bytes(16));
            file_put_contents($storage . '/' . $file, 'ThreadSafe probe ' . $token);
            $session['probe'] = ['file' => $file, 'token' => $token, 'result' => ''];
            $this->save($session);
        }

        return ['url' => self::relativeUrl($this->baseDirectory, $storage . '/' . $file), 'token' => $token];
    }

    /**
     * The address of a file as the page of the tool would write it: relative to the directory of the tool, with `..` where the file is above it.
     */
    public static function relativeUrl(string $fromDirectory, string $file): string
    {
        $from = array_values(array_filter(explode('/', str_replace('\\', '/', realpath($fromDirectory) ?: $fromDirectory)), static fn (string $s): bool => $s !== ''));
        $to = array_values(array_filter(explode('/', str_replace('\\', '/', (realpath(dirname($file)) ?: dirname($file)) . '/' . basename($file))), static fn (string $s): bool => $s !== ''));
        $common = 0;
        while ($common < count($from) && $common < count($to) - 1 && $from[$common] === $to[$common]) {
            ++$common;
        }
        $parts = [...array_fill(0, count($from) - $common, '..'), ...array_map(rawurlencode(...), array_slice($to, $common))];

        return implode('/', $parts);
    }

    /**
     * @param array{url: string, token: string} $probe
     */
    private function probeScript(string $csrf, array $probe): string
    {
        $url = json_encode($probe['url'], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?: '""';
        $token = json_encode($probe['token']) ?: '""';
        $csrf = json_encode($csrf) ?: '""';
        $texts = json_encode([
            'closed' => $this->probeText('closed'),
            'reachable' => $this->probeText('reachable'),
            'unknown' => $this->probeText('unknown'),
        ], JSON_HEX_TAG | JSON_HEX_AMP) ?: '{}';

        return "(function () {\n"
            . "  var out = document.getElementById('probe-result'), texts = $texts, csrf = $csrf, token = $token;\n"
            . "  function report(result) {\n"
            . "    out.textContent = texts[result]; out.className = result === 'reachable' ? 'fail' : '';\n"
            . "    fetch('?', {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: 'csrf=' + encodeURIComponent(csrf) + '&action=probe&result=' + result}).catch(function () {});\n"
            . "  }\n"
            . "  fetch($url, {credentials: 'omit', cache: 'no-store'}).then(function (r) { return r.ok ? r.text() : ''; })\n"
            . "    .then(function (t) { report(t.indexOf(token) >= 0 ? 'reachable' : 'closed'); })\n"
            . "    .catch(function () { report('closed'); });\n"
            . '})();';
    }

    /**
     * @param array<string, mixed> $session
     */
    private function recordProbe(Request $request, array $session): Response
    {
        $result = (string) $request->post('result');
        if (is_array($session['probe'] ?? null) && in_array($result, ['closed', 'reachable', 'unknown'], true)) {
            $session['probe']['result'] = $result;
            $this->save($session);
        }

        return Response::json(['recorded' => in_array($result, ['closed', 'reachable', 'unknown'], true)]);
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
            'Cache-Control: no-store',
            'X-Content-Type-Options: nosniff',
            'X-Frame-Options: DENY',
            'Referrer-Policy: no-referrer',
            "Content-Security-Policy: default-src 'none'; img-src data:; style-src 'nonce-{$this->nonce}'; script-src 'nonce-{$this->nonce}'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'",
        ], $response->body);
    }
}
