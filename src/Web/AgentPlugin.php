<?php

declare(strict_types=1);

namespace ThreadSafe\Web;

use InvalidArgumentException;
use RuntimeException;
use ThreadSafe\App\ProfileService;
use ThreadSafe\Backup\Filters;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Profile\EncryptionSettings;
use ThreadSafe\Profile\ProfileException;
use ThreadSafe\Profile\ProfileResolver;
use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Profile\SiteProfile;

/**
 * What the WordPress plugin `threadsafe-connector` asks of the tool (docs/PHASE13.md 9.12). The plugin is a thin wrapper: it loads the single-file tool as a
 * library and hands the work to this class, which holds everything the plugin must know and that PHPUnit can test without WordPress.
 *
 * - **The workspace** (the state and the archives) lives in `wp-content/uploads/threadsafe-<random>/`, never in the plugin directory, which an update of
 *   the plugin replaces (9.2, 2). A **pointer file** in `wp-content` (`threadsafe-agent.php`, mode 0600) names it; the entry file of the plugin reads it
 *   without loading WordPress and defines THREADSAFE_WORKSPACE (9.2, 4). The pointer is also **the switch of the plugin**: it says `on` or not, activating
 *   the plugin turns it on, deactivating turns it off (the path stays, so that a reactivation or an update finds the pairing and the archives again),
 *   and an entry file that finds it off answers like a file that is not there.
 * - **The site profile** is made from the site itself (its root, the database access in `wp-config.php`) and from three things the person gives on the
 *   page: the public keys, what to leave out besides the tool's own files, and the size of a part. The agent's own copy of the profile
 *   ({@see AgentSetup}) is brought up to date whenever they change.
 */
final class AgentPlugin
{
    public const string POINTER_FILE = 'threadsafe-agent.php';

    /** The file of the plugin that a request from the central side reaches: it reads the pointer and loads the tool. */
    public const string ENTRY_FILE = 'agent.php';

    private const string SETTINGS_FILE = 'plugin.json';

    /**
     * @param string $siteRoot         the directory of the WordPress site (ABSPATH)
     * @param string $contentDirectory `wp-content`: the pointer file lies there, where an update of the plugin does not reach
     * @param string $toolDirectory    the directory of the plugin, where the tool and its entry file are
     * @param string $script           the file name of the tool
     */
    public function __construct(
        private readonly string $siteRoot,
        private readonly string $contentDirectory,
        private readonly string $toolDirectory,
        private readonly ?PluginRegistry $plugins = null,
        private readonly string $script = 'threadsafe-backup.php',
    ) {
    }

    // ------------------------------------------------------------------------------------------------- the pointer and the workspace

    public function pointerPath(): string
    {
        return rtrim($this->contentDirectory, '/\\') . '/' . self::POINTER_FILE;
    }

    /** The first line of the pointer file ends the script, so that fetching the file through the web prints nothing (the convention of `Core\Home`). */
    public const string POINTER_PREFIX = "<?php http_response_code(404); exit; ?>\n";

    /** The content of the pointer file: the line that ends the script, then the path and the switch as JSON. The entry file of the plugin reads it as text. */
    public static function pointerSource(string $workspace, bool $on = true): string
    {
        return self::POINTER_PREFIX . json_encode(['workspace' => $workspace, 'on' => $on], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    /** Whether the plugin is switched on: the pointer is good and says so. */
    public function isOn(): bool
    {
        return $this->pointer()['on'] ?? false;
    }

    /**
     * The directory the pointer names, or null if there is no pointer, it is damaged, readable by others, or names a path that cannot be a workspace.
     */
    public function workspacePath(): ?string
    {
        return $this->pointer()['workspace'] ?? null;
    }

    /**
     * @return array{workspace: string, on: bool}|null
     */
    private function pointer(): ?array
    {
        $file = $this->pointerPath();
        if (!is_file($file) || is_link($file)) {
            return null;
        }
        if (DIRECTORY_SEPARATOR === '/' && ((int) @fileperms($file) & 0007) !== 0) {
            return null;
        }
        $source = @file_get_contents($file);
        if ($source === false || !str_starts_with($source, self::POINTER_PREFIX)) {
            return null;   // the pointer is read as text, never executed here: a damaged or foreign file is refused
        }
        $data = json_decode(substr($source, strlen(self::POINTER_PREFIX)), true, 4);
        $path = is_array($data) ? ($data['workspace'] ?? null) : null;

        return is_string($path) && BackupWorkspace::customProblem($path, $this->toolDirectory) === null ? ['workspace' => $path, 'on' => ($data['on'] ?? false) === true] : null;
    }

    public function workspace(): ?BackupWorkspace
    {
        $path = $this->workspacePath();

        return $path === null ? null : BackupWorkspace::at($path);
    }

    /**
     * Switches the plugin on: makes the workspace (a new one in `$uploadsDirectory`, or the one the pointer names already, so that an activation after
     * a deactivation or an update finds the pairing and the archives) and writes the pointer.
     *
     * @throws RuntimeException the workspace or the pointer cannot be written, or the path is not good
     */
    public function activate(string $uploadsDirectory): BackupWorkspace
    {
        $path = $this->workspacePath() ?? rtrim($uploadsDirectory, '/\\') . '/threadsafe-' . bin2hex(random_bytes(8));
        if (($problem = BackupWorkspace::customProblem($path, $this->toolDirectory)) !== null) {
            throw new RuntimeException('The workspace "' . $path . '" cannot be used: ' . $problem . '.');
        }
        $workspace = BackupWorkspace::at($path);
        $workspace->data();
        $workspace->storage();   // made, closed to the web (a `.htaccess` and an empty `index.html`)
        $this->writePointer($path);

        return $workspace;
    }

    /** Switches the plugin off: the entry file answers nobody until it is activated again. The path stays, nothing else is touched. */
    public function deactivate(): void
    {
        $path = $this->workspacePath();
        if ($path !== null) {
            $this->writePointer($path, false);
        }
    }

    /**
     * Removes the pointer and the workspace, or only the state (the archives stay if `$archives` is false). For `uninstall.php`.
     */
    public function uninstall(bool $archives): void
    {
        $path = $this->workspacePath();
        @unlink($this->pointerPath());
        if ($path === null) {
            return;
        }
        $workspace = BackupWorkspace::at($path);
        $data = $workspace->existingData();
        if ($data !== null) {
            self::removeTree($data);
        }
        $storage = $workspace->existingStorage();
        if ($archives && $storage !== null) {
            self::removeTree($storage);
        }
        foreach ([$path . '/.htaccess', $path . '/index.html'] as $file) {
            if ($archives || $storage === null) {
                @unlink($file);
            }
        }
        if ($archives || $storage === null) {
            @rmdir($path);
        }
    }

    /**
     * @throws RuntimeException
     */
    private function writePointer(string $workspace, bool $on = true): void
    {
        $file = $this->pointerPath();
        $temporary = $file . '.' . bin2hex(random_bytes(3)) . '.tmp';
        if (@file_put_contents($temporary, self::pointerSource($workspace, $on), LOCK_EX) === false) {
            throw new RuntimeException('Cannot write the file "' . $file . '". Make the directory wp-content writable for the web server.');
        }
        @chmod($temporary, 0600);
        if (!@rename($temporary, $file)) {
            @unlink($temporary);

            throw new RuntimeException('Cannot write the file "' . $file . '".');
        }
    }

    // ------------------------------------------------------------------------------------------------- what the person gives

    /**
     * What the page shows in its fields.
     *
     * @return array{recipients: list<string>, exclude: list<string>, partSize: int|null}
     */
    public function settings(BackupWorkspace $workspace): array
    {
        $data = $this->read($workspace);
        $recipients = array_values(array_filter(is_array($data['recipients'] ?? null) ? $data['recipients'] : [], is_string(...)));
        $exclude = array_values(array_filter(is_array($data['exclude'] ?? null) ? $data['exclude'] : [], is_string(...)));

        return ['recipients' => $recipients, 'exclude' => $exclude, 'partSize' => is_int($data['partSize'] ?? null) ? $data['partSize'] : null];
    }

    /**
     * Checks what the person gave, stores it, makes the profile `site` of the workspace from it and, if the agent is paired, gives the agent its copy
     * again. Nothing is stored if something is wrong.
     *
     * @param string $recipients public keys of the age format, one per line or separated by spaces
     * @param string $exclude    patterns to leave out besides the files of the tool, one per line
     * @param string $partSize   a size such as 500M or 2G (at least 1M); empty: the default
     *
     * @throws InvalidArgumentException a value is not valid (the message says which and why)
     * @throws ProfileException         the site cannot be backed up, or the profile cannot be the profile of the agent
     * @throws RuntimeException         a file cannot be written
     */
    public function save(BackupWorkspace $workspace, string $recipients, string $exclude, string $partSize): void
    {
        $keys = array_values(array_unique(preg_split('/\s+/', trim($recipients), -1, PREG_SPLIT_NO_EMPTY) ?: []));
        if ($keys === []) {
            throw new InvalidArgumentException('Paste at least one public key (age1 and 58 more characters): the backups of the agent are encrypted for it. The secret key stays with you; this site never holds it.');
        }
        foreach ($keys as $key) {
            if (!EncryptionSettings::isRecipient($key)) {
                throw new InvalidArgumentException('"' . $key . '" is not a public key of the age format (age1 and 58 more characters).');
            }
        }
        $patterns = array_values(array_unique(array_filter(array_map(trim(...), preg_split('/\r\n|\r|\n/', $exclude) ?: []), static fn (string $line): bool => $line !== '')));
        foreach ($patterns as $pattern) {
            if (($problem = Filters::problem($pattern)) !== null) {
                throw new InvalidArgumentException('The pattern "' . $pattern . '" is not valid: ' . $problem . '.');
            }
        }
        $size = null;
        if (trim($partSize) !== '') {
            $size = Budget::parseBytes(trim($partSize));
            if ($size === null || $size < 1048576) {
                throw new InvalidArgumentException('The size of a part is a size such as 500M or 2G, at least 1M.');
            }
        }

        $profile = $this->profile($workspace, $keys, $patterns, $size);   // may refuse: nothing is stored before it did not
        $this->write($workspace, ['recipients' => $keys, 'exclude' => $patterns, 'partSize' => $size]);
        $workspace->profiles()->save($profile);
        $setup = new AgentSetup($workspace);
        if ($setup->config()->isPaired()) {
            $setup->sync($profile);
        }
    }

    /**
     * The profile `site`: the site as it is, its storage in the workspace, and left out of it what must never be in a backup of this site.
     *
     * @param list<string> $recipients
     * @param list<string> $exclude
     *
     * @throws ProfileException
     */
    private function profile(BackupWorkspace $workspace, array $recipients, array $exclude, ?int $partSize): SiteProfile
    {
        $target = new BackupTarget($this->toolDirectory, $this->script, BackupWizard::KEY_FILE, $workspace->directory());
        $root = $target->resolve($this->siteRoot);
        $service = new ProfileService(new ProfileStore($workspace->data() . '/profiles'), $this->plugins ?? PluginRegistry::load(), $workspace->data());
        [$type, $detection] = $service->autodetect($root);
        if ($type !== 'wordpress') {
            throw new ProfileException('The directory "' . $root . '" is not recognised as a WordPress site (found: ' . $type . ').');
        }
        if ($detection->database === null) {
            throw new ProfileException('The file wp-config.php holds no database access that this plugin can read.');
        }
        // the plugin's own entry file and the pointer must never travel into a copy of the site: the copy would carry a door to the workspace of this one
        $own = [];
        foreach ([$this->toolDirectory . '/' . self::ENTRY_FILE, $this->pointerPath()] as $file) {
            $relative = ProfileResolver::relativeInside($root, $file);
            if ($relative !== null) {
                $own[] = '/' . strtr($relative, ['*' => '?', '?' => '?', '\\' => '?']);
            }
        }

        return $target->profile(
            $root,
            $type,
            $workspace->storage(),
            null,
            [...$own, ...$exclude],
            $partSize,
            EncryptionSettings::fromArray(['recipients' => $recipients]) ?? throw new ProfileException('No public key was given.'),
        );
    }

    // ------------------------------------------------------------------------------------------------- what the page shows

    /**
     * Where the usual backup plugins keep their archives, below `wp-content` (a name may have a `*`). They are large and are a backup already: a backup of
     * them each night would carry ten gigabytes of archives (docs/PHASE13.md 9.10, finding 1).
     */
    private const array OTHER_BACKUPS = ['backups', 'updraft', 'ai1wm-backups', 'backup-db', 'backupwordpress-*', 'uploads/backwpup-*', 'uploads/wpvividbackups', 'uploads/backupbuddy_backups'];

    /**
     * The directories of other backup plugins that this site has and that are not left out yet, as patterns for the list of what to leave out. Nothing is
     * left out unasked: the page shows them and the person decides.
     *
     * @return list<string>
     */
    public function otherBackups(BackupWorkspace $workspace): array
    {
        $root = realpath($this->siteRoot);
        if ($root === false) {
            return [];
        }
        $left = $this->settings($workspace)['exclude'];
        $found = [];
        foreach (self::OTHER_BACKUPS as $candidate) {
            foreach (glob(rtrim($this->contentDirectory, '/\\') . '/' . $candidate, GLOB_ONLYDIR) ?: [] as $directory) {
                $relative = ProfileResolver::relativeInside($root, $directory);
                $pattern = $relative === null ? null : '/' . strtr($relative, ['*' => '?', '?' => '?', '\\' => '?']) . '/';
                if ($pattern !== null && !in_array($pattern, $left, true) && Filters::problem($pattern) === null) {
                    $found[] = $pattern;
                }
            }
        }
        sort($found, SORT_STRING);

        return array_values(array_unique($found));
    }

    /**
     * Adds the directories that {@see otherBackups()} found to what is left out, and keeps everything else as it was saved.
     *
     * @return int how many were added
     *
     * @throws InvalidArgumentException nothing is saved yet (the key comes first)
     * @throws ProfileException
     * @throws RuntimeException
     */
    public function leaveOutOtherBackups(BackupWorkspace $workspace): int
    {
        $settings = $this->settings($workspace);
        if ($settings['recipients'] === []) {
            throw new InvalidArgumentException('Save the settings with a public key first.');
        }
        $found = $this->otherBackups($workspace);
        $size = $settings['partSize'] === null ? '' : (int) ($settings['partSize'] / 1048576) . 'M';
        $this->save($workspace, implode("\n", $settings['recipients']), implode("\n", [...$settings['exclude'], ...$found]), $size);

        return count($found);
    }

    /**
     * The state of the workspace, for the page.
     *
     * @return array{directory: string, free: float|null, backups: int, newest: int|null}
     */
    public function state(BackupWorkspace $workspace): array
    {
        $storage = $workspace->existingStorage();
        $free = $storage === null ? false : @disk_free_space($storage);
        $count = 0;
        $newest = null;
        foreach ($storage === null ? [] : (glob($storage . '/*.json') ?: []) as $sidecar) {
            ++$count;
            $time = (int) @filemtime($sidecar);
            $newest = $newest === null ? $time : max($newest, $time);
        }

        // the figure for the free space is that of the volume, not of the quota of the account (docs/PHASE13.md 9.6)
        return ['directory' => $workspace->directory(), 'free' => $free === false ? null : $free, 'backups' => $count, 'newest' => $newest];
    }

    /**
     * The files and the token of the test whether the web server hands out what lies in the workspace, and the addresses to ask for. A workspace
     * outside the uploads directory has no address the plugin knows: it is outside the web root as far as the plugin can tell.
     *
     * @return array{token: string, urls: list<string>}|null null if the workspace is not inside `$uploadsDirectory`
     */
    public function probe(BackupWorkspace $workspace, string $uploadsDirectory, string $uploadsUrl): ?array
    {
        $inside = ProfileResolver::relativeInside($uploadsDirectory, $workspace->directory());
        if ($inside === null) {
            return null;
        }
        $storage = $workspace->existingStorage();
        $directories = array_values(array_filter([$workspace->directory(), $workspace->existingData(), $storage], is_string(...)));
        $probe = new ReachProbe($this->toolDirectory);
        $data = $this->read($workspace);
        $record = $probe->prepare(is_array($data['probe'] ?? null) ? $data['probe'] : [], $directories);
        $data['probe'] = $record;
        $this->write($workspace, $data);
        $urls = [];
        foreach ($directories as $directory) {
            $relative = ProfileResolver::relativeInside($uploadsDirectory, $directory);
            $urls[] = rtrim($uploadsUrl, '/') . '/' . implode('/', array_map(rawurlencode(...), explode('/', (string) $relative))) . '/' . $record['file'];
        }

        return ['token' => $record['token'], 'urls' => $urls];
    }

    /** Removes the files of the probe (the page does it once it has the answer, and on uninstall the workspace goes anyway). */
    public function endProbe(BackupWorkspace $workspace): void
    {
        $data = $this->read($workspace);
        if (is_array($data['probe'] ?? null)) {
            $directories = array_values(array_filter([$workspace->directory(), $workspace->existingData(), $workspace->existingStorage()], is_string(...)));
            (new ReachProbe($this->toolDirectory))->remove($data['probe'], $directories);
            unset($data['probe']);
            $this->write($workspace, $data);
        }
    }

    // ------------------------------------------------------------------------------------------------- the file of the plugin's own settings

    /**
     * @return array<string, mixed>
     */
    private function read(BackupWorkspace $workspace): array
    {
        $file = ($workspace->existingData() ?? '') . '/' . self::SETTINGS_FILE;
        $json = $workspace->existingData() === null || !is_file($file) ? false : @file_get_contents($file);
        $data = $json === false ? null : json_decode($json, true);

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws RuntimeException
     */
    private function write(BackupWorkspace $workspace, array $data): void
    {
        $file = $workspace->data() . '/' . self::SETTINGS_FILE;
        $temporary = $file . '.' . bin2hex(random_bytes(3)) . '.tmp';
        if (@file_put_contents($temporary, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n", LOCK_EX) === false) {
            throw new RuntimeException('Cannot write the settings to "' . $file . '".');
        }
        @chmod($temporary, 0600);
        if (!@rename($temporary, $file)) {
            @unlink($temporary);

            throw new RuntimeException('Cannot write the settings to "' . $file . '".');
        }
    }

    private static function removeTree(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::removeTree($path . '/' . $entry);
                }
            }
            @rmdir($path);

            return;
        }
        @unlink($path);
    }
}
