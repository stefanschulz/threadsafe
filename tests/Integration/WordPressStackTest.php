<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Integration;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use ThreadSafe\Archive\ZipReader;
use ThreadSafe\Backup\Manifest;
use ThreadSafe\Build\RestoreBuilder;
use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Profile\SiteProfile;
use ThreadSafe\Tests\Support\CliProcess;
use ThreadSafe\Tests\Support\Stack;
use ThreadSafe\Tests\Support\TableCompare;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Tests\Support\UnzipOracle;
use ThreadSafe\Tests\Support\WebWizard;
use ThreadSafe\Tests\Support\WizardJourney;
use ThreadSafe\Tests\Support\WordPressClient;
use ThreadSafe\WordPress\Config\WordPressConfigFile;

/**
 * Phase 9 on the real thing: the two seeded WordPress sites of the stack (WordPress 7.1 on port 8197, WordPress 6.9 on port 8198;
 * tests/stack/seed-wordpress.sh) are backed up through their profiles, and the backups are restored into the empty target - by one command on
 * another domain with another table prefix, by one command into a sub-directory, and through the `threadsafe-restore.php` wizard under limits of
 * 5 seconds and 64 MB - and the restored site must work: its pages answer, its users log in and have their rights, its content points to the
 * new domain and to nothing of the old one (the real tool `wp search-replace --dry-run` is the oracle), what must not change did not change, and
 * its configuration is right for the host.
 *
 * Runs after the Joomla phase of tests/stack/run.sh: it wipes the target volume, which the earlier phases use. The target is the service
 * `target-wordpress`, a web server with mysqli on the files of the target volume (the PHP of the PrestaShop image has none, and WordPress needs it).
 */
#[Group('wordpress')]
final class WordPressStackTest extends IntegrationTestCase
{
    use TempDirectory;

    private const string TARGET = '/shops/target';
    private const string TARGET_URL = 'http://target-wordpress';
    private const string TARGET_HOST = 'localhost:8291';
    private const string PASSWORD = 'Seed-admin-0123456789';

    /** @var array<int, array{string, string, string, string}> key (the service is called wordpress<key>) => directory, host, database, the start of the version */
    private const array SITES = [
        7 => ['/wordpress7', 'localhost:8197', 'wp7', '7.'],
        6 => ['/wordpress6', 'localhost:8198', 'wp6', '6.9.'],
        60 => ['/wordpress60', 'localhost:8199', 'wp60', '6.0.'],
    ];

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    /** @return iterable<string, array{int}> */
    public static function versions(): iterable
    {
        yield 'WordPress 7.1' => [7];
        yield 'WordPress 6.9' => [6];
        yield 'WordPress 6.0, the oldest that is supported' => [60];
    }

    private static function password(): string
    {
        return getenv('DB_ROOT_PASSWORD') ?: 'prestashop';
    }

    private function requireSite(int $major): void
    {
        if (!is_file(self::SITES[$major][0] . '/wp-includes/version.php') || !is_dir(self::TARGET)) {
            self::markTestSkipped('The WordPress sites are not mounted (tests/stack/seed-wordpress.sh) or the target is missing.');
        }
    }

    private static function sourceDb(int $major): PDO
    {
        return new PDO('mysql:host=db;dbname=' . self::SITES[$major][2] . ';charset=utf8mb4', 'root', self::password(), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    private static function targetDb(string $database): PDO
    {
        return new PDO('mysql:host=target-db;dbname=' . $database . ';charset=utf8mb4', 'root', self::password(), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    /**
     * The backup of a seeded site through its profile: `profile test`, `backup`, `verify`.
     *
     * @return array{string, string, Manifest} the base directory, the archive, its manifest
     */
    private function backUp(int $major): array
    {
        $base = $this->tempDirectory();
        $data = $base . '/data';
        (new ProfileStore($data . '/profiles'))->save(SiteProfile::fromArray([
            'id' => 'site', 'name' => 'Seeded WordPress ' . $major, 'type' => 'wordpress', 'path' => self::SITES[$major][0], 'storage' => $base . '/storage',
        ]));
        putenv('THREADSAFE_DATA=' . $data);
        try {
            foreach ([['profile', 'test', 'site'], ['backup', 'site'], ['verify', 'site']] as $command) {
                [$code, $stdout, $stderr] = CliProcess::run($command, 900);
                self::assertSame(0, $code, implode(' ', $command) . ': ' . $stdout . $stderr);
            }
        } finally {
            putenv('THREADSAFE_DATA');
        }
        $archives = glob($base . '/storage/site_*.zip') ?: [];
        self::assertCount(1, $archives);

        return [$base, $archives[0], Manifest::fromArchive($archives[0])];
    }

    /** An empty target: an empty directory owned by the web server and an empty database. */
    private function emptyTarget(string $database, string $directory = self::TARGET): void
    {
        exec('find ' . escapeshellarg(self::TARGET) . ' -mindepth 1 -delete 2>&1', $ignored, $code);
        self::assertSame(0, $code, 'The target volume can be emptied.');
        if ($directory !== self::TARGET) {
            mkdir($directory);
        }
        exec('chown www-data:www-data ' . escapeshellarg($directory) . ' ' . escapeshellarg(self::TARGET) . ' 2>&1', $ignored, $code);
        self::assertSame(0, $code);
        $admin = new PDO('mysql:host=target-db;charset=utf8mb4', 'root', self::password(), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $admin->exec("DROP DATABASE IF EXISTS `$database`");
        $admin->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4");
    }

    /**
     * @param list<string> $more more options of `restore`
     *
     * @return string the output
     */
    private function restore(string $base, string $archive, string $root, string $database, array $more = [], string $serverPath = '/var/www/html'): string
    {
        file_put_contents($base . '/db-password', self::password() . "\n");
        putenv('THREADSAFE_DATA=' . $base . '/data');
        try {
            [$code, $stdout, $stderr] = CliProcess::run([
                'restore', $archive, '--root=' . $root, '--to=' . self::TARGET_HOST,
                '--db-host=target-db', '--db-name=' . $database, '--db-user=root', '--db-password-file=' . $base . '/db-password',
                '--server-path=' . $serverPath, '--self-test-url=' . self::TARGET_URL, '--report=' . $base . '/result.json', ...$more, '--yes',
            ], 1800);
        } finally {
            putenv('THREADSAFE_DATA');
        }
        self::assertSame(0, $code, $stderr . $stdout);
        self::assertSame([], glob($base . '/data/runs/*.json') ?: [], 'the run state that held the password is deleted');
        self::assertDoesNotMatchRegularExpression('/"password"\s*:/', (string) file_get_contents($base . '/result.json'));

        return $stdout;
    }

    // ------------------------------------------------------------------------------------------------- the backup

    #[DataProvider('versions')]
    public function testTheSeededSiteIsBackedUpWithWhatItIsAndWithoutWhatWordPressRebuilds(int $major): void
    {
        $this->requireSite($major);
        [$directory, $host] = self::SITES[$major];
        [, $archive, $manifest] = $this->backUp($major);

        self::assertSame('WordPress', $manifest->application['name']);
        self::assertStringStartsWith(self::SITES[$major][3], (string) $manifest->application['version']);
        self::assertSame('snapshot', $manifest->consistency);
        self::assertSame('wp_', $manifest->extras['dbPrefix']);
        self::assertFalse($manifest->extras['multisite']);
        self::assertFalse($manifest->extras['multisiteTables']);
        self::assertSame('Seeded WordPress ' . $major, $manifest->extras['siteName']);
        self::assertSame('http://' . $host, $manifest->extras['siteUrl']);
        self::assertSame('http://' . $host, $manifest->extras['homeUrl'], 'both addresses come from the database');
        self::assertSame('/%postname%/', $manifest->extras['permalinks']);
        self::assertContains('threadsafe-fixture/threadsafe-fixture.php', $manifest->extras['activePlugins']);
        self::assertEqualsCanonicalizing(['advanced-cache.php', 'object-cache.php'], $manifest->extras['dropIns']);
        // (pathConstants lists the constants whose path lies under the site directory as this machine sees it: here the site is mounted at another path than the one
        // the web server has, so the list is empty; the restore finds the old directory in the constant itself)
        self::assertIsArray($manifest->extras['pathConstants']);
        self::assertStringNotContainsString(self::password(), (string) json_encode($manifest->toArray()), 'no password in the manifest');
        self::assertStringNotContainsString('Seed-admin', (string) json_encode($manifest->toArray()));
        self::assertDoesNotMatchRegularExpression('/"password"\s*:/', $manifest->toJson());
        self::assertContains('woocommerce_sessions', $manifest->filters['structureOnlyTables']);

        self::assertTrue(ZipReader::open($archive)->verify()->ok());
        [$ok, $output] = UnzipOracle::test($archive);
        self::assertTrue($ok, $output);

        $names = [];
        foreach (ZipReader::open($archive)->entries() as $entry) {
            $names[$entry->name] = true;
        }
        foreach (['files/wp-config.php', 'files/.htaccess', 'files/wp-content/uploads/seed/photo.bin', 'files/wp-includes/version.php', 'files/wp-content/object-cache.php', 'files/wp-content/plugins/threadsafe-fixture/threadsafe-fixture.php'] as $name) {
            self::assertArrayHasKey($name, $names, "$name is in the backup");
        }
        foreach (['files/wp-content/cache/seed.cache', 'files/wp-content/upgrade/seed.tmp', 'files/wp-content/updraft/seed.zip', 'files/wp-content/debug.log'] as $name) {
            self::assertArrayNotHasKey($name, $names, "$name is left out");
        }
        foreach (array_keys($names) as $name) {
            self::assertStringStartsNotWith('files/wp-content/cache/', $name, 'the cache of the site is left out as a whole');
        }
        // WordPress ships code in files and directories called "cache"; the filter left out the cache of the site, not these
        self::assertArrayHasKey('files/wp-includes/cache.php', $names);
        self::assertArrayHasKey('files/wp-includes/class-wp-object-cache.php', $names);
        self::assertGreaterThan(2500, $manifest->counts['files']);
        self::assertFileExists($directory . '/wp-config.php');
    }

    // ------------------------------------------------------------------------------------------------- the restore by one command

    #[DataProvider('versions')]
    public function testTheBackupIsRestoredWithOneCommandOnAnotherDomainWithAnotherPrefix(int $major): void
    {
        $this->requireSite($major);
        [$base, $archive, $manifest] = $this->backUp($major);
        $this->emptyTarget('wp_target');

        $stdout = $this->restore($base, $archive, self::TARGET, 'wp_target', ['--prefix=wp2_']);
        self::assertStringContainsString('Restored ' . $manifest->backupId, $stdout);
        self::assertStringContainsString('Run finished.', $stdout);

        $this->assertRestored($major, $manifest, $archive, self::TARGET, 'wp_target', 'wp2_', '', '/var/www/html');
    }

    public function testRestoreWithoutYesOnlyInspects(): void
    {
        $major = 7;
        $this->requireSite($major);
        [$base, $archive] = $this->backUp($major);
        $this->emptyTarget('wp_target');
        file_put_contents($base . '/db-password', self::password() . "\n");

        putenv('THREADSAFE_DATA=' . $base . '/data');
        try {
            [$code, $stdout, $stderr] = CliProcess::run([
                'restore', $archive, '--root=' . self::TARGET, '--to=' . self::TARGET_HOST,
                '--db-host=target-db', '--db-name=wp_target', '--db-user=root', '--db-password-file=' . $base . '/db-password',
            ], 900);
        } finally {
            putenv('THREADSAFE_DATA');
        }
        self::assertSame(0, $code, $stderr . $stdout);
        self::assertStringContainsString('Nothing was changed.', $stdout);
        self::assertStringContainsString('object-cache.php', $stdout, 'the drop-ins are named before anything is written');
        self::assertSame([], TableCompare::tables(self::targetDb('wp_target')), 'nothing was written');
        self::assertSame([], glob(self::TARGET . '/*') ?: [], 'no file was written');
    }

    // ------------------------------------------------------------------------------------------------- a sub-directory

    public function testTheBackupIsRestoredIntoASubDirectoryAndItsRewriteRulesFollow(): void
    {
        $major = 7;
        $this->requireSite($major);
        [$base, $archive, $manifest] = $this->backUp($major);
        $root = self::TARGET . '/sub';
        $this->emptyTarget('wp_target_sub', $root);

        $this->restore($base, $archive, $root, 'wp_target_sub', ['--path=/sub/'], '/var/www/html/sub');

        $htaccess = (string) file_get_contents($root . '/.htaccess');
        self::assertStringContainsString("\nRewriteBase /sub/\n", $htaccess);
        self::assertStringContainsString("\nRewriteRule . /sub/index.php [L]\n", $htaccess);
        self::assertStringContainsString('# BEGIN WordPress', $htaccess);

        $this->assertRestored($major, $manifest, $archive, $root, 'wp_target_sub', 'wp_', '/sub/', '/var/www/html/sub');
    }

    // ------------------------------------------------------------------------------------------------- dev mode

    public function testADevCopyIsRestoredThatSendsNoMailAndKeepsSearchEnginesOut(): void
    {
        $major = 7;
        $this->requireSite($major);
        [$base, $archive, $manifest] = $this->backUp($major);
        $this->emptyTarget('wp_target');

        $this->restore($base, $archive, self::TARGET, 'wp_target', ['--mode=dev']);

        $mail = 'wp --path=' . escapeshellarg(self::TARGET) . ' --allow-root eval ' . escapeshellarg('echo wp_mail("someone@example.org", "Test", "Test") ? "SENT" : "FAILED";') . ' 2>&1';
        $plugin = self::TARGET . '/wp-content/mu-plugins/threadsafe-dev.php';
        self::assertFileExists($plugin);
        self::assertSame('0', TableCompare::cell(self::targetDb('wp_target'), "SELECT option_value FROM wp_options WHERE option_name = 'blog_public'"));
        self::assertStringStartsWith('# BEGIN ThreadSafe dev mode', (string) file_get_contents(self::TARGET . '/.htaccess'));
        self::assertStringContainsString('# BEGIN WordPress', (string) file_get_contents(self::TARGET . '/.htaccess'), 'the rules of WordPress are still there');

        // everything else of a restore is as in production, and the source was not touched
        $this->assertRestored($major, $manifest, $archive, self::TARGET, 'wp_target', 'wp_', '', '/var/www/html', dev: true);
        // (what follows opens the administration, which writes a draft: the tables were compared before)
        // the web server sends the header, WordPress asks search engines to stay away, the pretty addresses still work behind the block
        $client = new WordPressClient(self::TARGET_URL, self::TARGET_HOST, '');
        $home = $client->get('/');
        self::assertSame(200, $home['status']);
        self::assertSame('noindex, nofollow', $home['headers']['x-robots-tag'] ?? '');
        self::assertMatchesRegularExpression('/<meta name=.robots. content=.noindex, nofollow/', $home['body']);
        $about = $client->get('/about-us/');
        self::assertSame(200, $about['status']);
        self::assertSame('noindex, nofollow', $about['headers']['x-robots-tag'] ?? '');
        self::assertStringContainsString('Disallow: /', $client->get('/robots.txt')['body']);

        // the administration says that this is a copy; the mail is stopped, and stops being stopped when the file goes
        $admin = new WordPressClient(self::TARGET_URL, self::TARGET_HOST, '');
        self::assertTrue($admin->login('seedadmin', self::PASSWORD));
        self::assertStringContainsString('This is a development copy.', $admin->get('/wp-admin/')['body']);
        $output = [];
        exec($mail, $output, $code);
        self::assertSame('SENT', trim(implode("\n", $output)), 'wp_mail() reports success and sends nothing: ' . implode("\n", $output));
        rename($plugin, $plugin . '.off');
        $output = [];
        exec($mail, $output, $code);
        self::assertSame('FAILED', trim(implode("\n", $output)), 'without the file the mail goes its way, and this host has no mail server');
        rename($plugin . '.off', $plugin);

        self::assertFileDoesNotExist(self::SITES[$major][0] . '/wp-content/mu-plugins/threadsafe-dev.php');
        self::assertSame('1', TableCompare::cell(self::sourceDb($major), "SELECT option_value FROM wp_options WHERE option_name = 'blog_public'"));
        self::assertArrayNotHasKey('x-robots-tag', Stack::get('http://wordpress' . $major, self::SITES[$major][1], '/')->headers);
    }

    // ------------------------------------------------------------------------------------------------- maintenance mode

    public function testASiteInMaintenanceModeIsWaitedForBeforeTheRestoreIsDone(): void
    {
        $major = 7;
        $this->requireSite($major);
        [$base, $archive] = $this->backUp($major);
        $this->emptyTarget('wp_target');
        $this->restore($base, $archive, self::TARGET, 'wp_target');

        // what WordPress does while its own cron installs an update: the file .maintenance, and every request answers 503
        $file = self::TARGET . '/.maintenance';
        file_put_contents($file, '<?php $upgrading = ' . time() . '; ?>');
        chmod($file, 0644);
        $client = new WordPressClient(self::TARGET_URL, self::TARGET_HOST, '');
        foreach (['/', '/wp-admin/', '/wp-login.php'] as $path) {
            $answer = $client->get($path);
            self::assertSame(503, $answer['status'], "$path answers 503 in maintenance mode");
            self::assertArrayHasKey('retry-after', $answer['headers']);
            self::assertStringContainsString('Briefly unavailable for scheduled maintenance', $answer['body']);
        }

        // the file goes after twelve seconds, as it does when the update is done; the site is moved to another address meanwhile (an address that
        // is the same would need no self-test) and the self-test of that move has to wait
        exec('(sleep 12; rm -f ' . escapeshellarg($file) . ') >/dev/null 2>&1 &');
        $started = microtime(true);
        putenv('THREADSAFE_DATA=' . $base . '/data');
        try {
            [$code, $stdout, $stderr] = CliProcess::run([
                'migrate', 'wordpress', '--root=' . self::TARGET, '--from=' . self::TARGET_HOST, '--to=127.0.0.1:8291', '--server-path=/var/www/html',
                '--self-test-url=' . self::TARGET_URL, '--yes',
            ], 300);
        } finally {
            putenv('THREADSAFE_DATA');
        }
        $took = microtime(true) - $started;

        self::assertSame(0, $code, $stderr . $stdout);
        self::assertGreaterThan(10.0, $took, 'the self-test waited until the file was gone');
        self::assertStringContainsString('maintenance mode', $stdout);
        self::assertFileDoesNotExist($file);
        $moved = new WordPressClient(self::TARGET_URL, '127.0.0.1:8291', '');
        self::assertSame(200, $moved->get('/')['status'], 'and the site is up when the migration says so');
    }

    // ------------------------------------------------------------------------------------------------- the wizard

    public function testTheBackupIsRestoredThroughTheWizardUnderTightLimits(): void
    {
        $major = 6;
        $this->requireSite($major);
        [$base, $archive, $manifest] = $this->backUp($major);
        $this->emptyTarget('wp_target');

        // what the person puts into the empty directory: the tool, the key, the backup
        (new RestoreBuilder(dirname(__DIR__, 2)))->build(self::TARGET . '/threadsafe-restore.php');
        file_put_contents(self::TARGET . '/threadsafe-restore-key.php', "<?php return 'a long secret from the wordpress test';");
        copy($archive, self::TARGET . '/' . basename($archive));
        foreach (glob(self::TARGET . '/*') ?: [] as $file) {
            chmod($file, 0644);
        }

        $wizard = new WebWizard(baseUrl: self::TARGET_URL, scriptPath: '/threadsafe-restore.php');
        $journey = WizardJourney::restore($wizard, 'a long secret from the wordpress test', [
            'root' => '/var/www/html', 'db_host' => 'target-db', 'db_port' => '3306', 'db_name' => 'wp_target', 'db_user' => 'root', 'db_password' => self::password(), 'db_prefix' => 'wp_',
            'f_from' => '', 'f_to' => self::TARGET_HOST, 'f_path' => '', 'f_keepDropIns' => 'off',
        ]);
        self::assertGreaterThan(3, $journey['ticks'], 'a restore of 3,000 files and more in requests of 5 seconds takes several of them');
        $gone = $wizard->request('POST', '/', ['action' => 'cleanup', 'csrf' => $wizard->csrf()]);
        self::assertStringContainsString('The tool is gone', $gone['body']);
        foreach (['threadsafe-restore.php', 'threadsafe-restore-key.php', '.threadsafe-restore'] as $name) {
            self::assertFileDoesNotExist(self::TARGET . '/' . $name, "$name is deleted");
        }
        @unlink(self::TARGET . '/' . basename($archive));
        @unlink(self::TARGET . '/' . basename($archive, '.zip') . '.json');

        $this->assertRestored($major, $manifest, $archive, self::TARGET, 'wp_target', 'wp_', '', '/var/www/html');
    }

    // ------------------------------------------------------------------------------------------------- what a restored site must be

    /**
     * What a restored WordPress must be, wherever and however it was restored.
     *
     * @param string $path the base path ('' or '/sub/') the site is served under on the target host
     */
    private function assertRestored(int $major, Manifest $manifest, string $archive, string $root, string $database, string $prefix, string $path, string $serverPath, bool $dev = false): void
    {
        [$directory, $host] = self::SITES[$major];
        $source = self::sourceDb($major);
        $target = self::targetDb($database);
        $url = rtrim($path, '/');
        $new = self::TARGET_HOST;
        $origin = 'http://' . $new . $url;

        // --- the configuration: right for this host, and nothing else of it changed
        $config = WordPressConfigFile::fromPath($root . '/wp-config.php');
        $sourceConfig = WordPressConfigFile::fromPath($directory . '/wp-config.php');
        self::assertSame('target-db', $config->database()->host);
        self::assertSame($database, $config->database()->name);
        self::assertSame($prefix, $config->tablePrefix());
        foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT', 'WP_CACHE_KEY_SALT'] as $salt) {
            self::assertSame($sourceConfig->get($salt), $config->get($salt), "$salt is kept: the logins of the site stay valid");
        }
        self::assertSame($serverPath . '/wp-content/plugins/threadsafe-fixture/', $config->get('WPCACHEHOME'), 'the absolute path that a plugin wrote follows the directory');
        self::assertSame('db', $sourceConfig->database()->host, 'the source was not touched');
        self::assertSame('wp_', $sourceConfig->tablePrefix());
        self::assertSame('/var/www/html/wp-content/plugins/threadsafe-fixture/', $sourceConfig->get('WPCACHEHOME'));

        // --- the drop-ins that the target cannot serve are off, and the site works without them
        foreach (['object-cache.php', 'advanced-cache.php'] as $dropIn) {
            self::assertFileDoesNotExist($root . '/wp-content/' . $dropIn, "$dropIn is switched off");
            self::assertFileExists($root . '/wp-content/' . $dropIn . '.threadsafe-off', "$dropIn is kept under another name");
        }
        self::assertFileExists($directory . '/wp-content/object-cache.php', 'the source keeps its drop-in');
        // the cache directory is empty, the rest of what a backup leaves out is not there
        foreach (['wp-content/cache/seed.cache', 'wp-content/upgrade/seed.tmp', 'wp-content/updraft/seed.zip', 'wp-content/debug.log'] as $left) {
            self::assertFileDoesNotExist($root . '/' . $left, "$left was left out");
        }

        // --- the files: every file of the backup is what the source has (except what the restore had to change)
        $files = 0;
        foreach (ZipReader::open($archive)->entries() as $entry) {
            if (!str_starts_with($entry->name, 'files/') || $entry->isDirectory) {
                continue;
            }
            $relative = substr($entry->name, 6);
            ++$files;
            if (in_array($relative, ['wp-config.php', '.htaccess', 'wp-content/object-cache.php', 'wp-content/advanced-cache.php'], true)) {
                continue;
            }
            self::assertSame(md5_file($directory . '/' . $relative), md5_file($root . '/' . $relative), "The file /$relative differs from the source.");
        }
        self::assertSame($manifest->counts['files'], $files);
        self::assertSame((int) fileowner($root), (int) fileowner($root . '/index.php'), 'files belong to the owner of the target directory');

        // --- the database: what is kept whole is whole, what keeps its structure is empty, and the old domain is gone from the content
        $sameKind = TableCompare::sameKind($source, $target);
        $structureOnly = array_map(static fn (string $t): string => 'wp_' . $t, $manifest->filters['structureOnlyTables']);
        $different = [];
        $tables = TableCompare::tables($source);
        self::assertCount(count($tables), TableCompare::tables($target), 'the same tables');
        foreach ($tables as $table) {
            $copy = $prefix . substr($table, 3);
            self::assertContains($copy, TableCompare::tables($target), "$copy is there");
            if (in_array($table, $structureOnly, true)) {
                self::assertSame('0', TableCompare::cell($target, "SELECT COUNT(*) FROM `$copy`"), "$table keeps only its structure");
                continue;
            }
            if (TableCompare::mentions($source, $table, $host) || ($prefix !== 'wp_' && $table === 'wp_usermeta')) {
                if ($table !== 'wp_options') {   // the transients are gone
                    self::assertSame(TableCompare::cell($source, "SELECT COUNT(*) FROM `$table`"), TableCompare::cell($target, "SELECT COUNT(*) FROM `$copy`"), "$table has all rows");
                }
                continue;
            }
            if (TableCompare::fingerprint($source, $table, $sameKind) !== TableCompare::fingerprint($target, $copy, $sameKind)) {
                $different[] = $table;
            }
        }
        self::assertSame([], $different, 'Every table in which the old domain does not occur has the checksum of the source.');

        // --- the rows that are keyed by the table prefix follow it
        self::assertSame('1', TableCompare::cell($target, "SELECT COUNT(*) FROM {$prefix}options WHERE option_name = '{$prefix}user_roles'"));
        $roles = unserialize(TableCompare::cell($target, "SELECT option_value FROM {$prefix}options WHERE option_name = '{$prefix}user_roles'"), ['allowed_classes' => false]);
        self::assertIsArray($roles);
        self::assertArrayHasKey('editor', $roles);
        self::assertSame(
            (int) TableCompare::cell($source, 'SELECT COUNT(*) FROM wp_usermeta'),
            (int) TableCompare::cell($target, "SELECT COUNT(*) FROM {$prefix}usermeta"),
        );
        self::assertSame('0', TableCompare::cell($target, "SELECT COUNT(*) FROM {$prefix}usermeta WHERE meta_key LIKE 'wp\\_%' AND '$prefix' <> 'wp_'"), 'no key keeps the old prefix');
        self::assertSame('3', TableCompare::cell($target, "SELECT COUNT(*) FROM {$prefix}usermeta WHERE meta_key = '{$prefix}capabilities'"), 'three users with their capabilities');

        // --- the transients are gone, the options say the new address
        self::assertSame('0', TableCompare::cell($target, "SELECT COUNT(*) FROM {$prefix}options WHERE option_name IN ('_transient_threadsafe_seed', '_site_transient_threadsafe_seed_site')"));
        self::assertSame('1', TableCompare::cell($source, "SELECT COUNT(*) FROM wp_options WHERE option_name = '_transient_threadsafe_seed'"), 'the source keeps its transients');
        self::assertSame($origin, TableCompare::cell($target, "SELECT option_value FROM {$prefix}options WHERE option_name = 'siteurl'"));
        self::assertSame($origin, TableCompare::cell($target, "SELECT option_value FROM {$prefix}options WHERE option_name = 'home'"));
        self::assertSame('http://' . $host, TableCompare::cell($source, "SELECT option_value FROM wp_options WHERE option_name = 'home'"), 'the source was not touched');
        self::assertSame("$origin/seed/", TableCompare::cell($target, "SELECT option_value FROM {$prefix}options WHERE option_name = 'threadsafe_seed_plain'"));
        $escaped = 'http:\/\/' . $new . str_replace('/', '\/', $url);
        $json = TableCompare::cell($target, "SELECT option_value FROM {$prefix}options WHERE option_name = 'threadsafe_seed_json'");
        self::assertStringContainsString($escaped . '\/wp-content\/uploads\/seed\/photo.png', $json, 'JSON keeps its escaped slashes');
        self::assertStringContainsString('https:\/\/foreign.example\/page', $json);
        self::assertIsArray(json_decode($json, true));

        // --- serialized values are still serialized values, with the right lengths
        $option = unserialize(TableCompare::cell($target, "SELECT option_value FROM {$prefix}options WHERE option_name = 'threadsafe_seed_serialized'"), ['allowed_classes' => false]);
        self::assertIsArray($option);
        self::assertSame("$origin/about-us/", $option['nested']['link']);
        self::assertSame(["$origin/wp-content/uploads/seed/photo.png", 'https://foreign.example/x.png'], $option['nested']['images']);
        self::assertSame(3, $option['nested']['count']);
        self::assertSame(0.5, $option['nested']['ratio']);
        self::assertTrue($option['nested']['on']);
        self::assertSame('https://foreign.example/page', $option['foreign']);
        $widget = unserialize(TableCompare::cell($target, "SELECT option_value FROM {$prefix}options WHERE option_name = 'widget_custom_html'"), ['allowed_classes' => false]);
        self::assertIsArray($widget);
        self::assertStringContainsString("href=\"$origin/about-us/\"", $widget[2]['content']);

        // --- the content
        $post = TableCompare::cell($target, "SELECT post_content FROM {$prefix}posts WHERE post_name = 'about-us'");
        self::assertStringContainsString("href=\"$origin/about-us/\"", $post, 'plain, http');
        self::assertStringContainsString('href="https://' . $new . $url . '/about-us/?utm=1"', $post, 'plain, https');
        self::assertStringContainsString('u=http%3A%2F%2F' . str_replace(':', '%3A', $new) . str_replace('/', '%2F', $url) . '%2Fabout-us%2F', $post, 'URL-encoded');
        self::assertStringContainsString('href="//' . $new . $url . '/about-us/"', $post, 'protocol-relative');
        self::assertStringContainsString('"url":"' . $escaped . '\/wp-content\/uploads\/seed\/photo.png"', $post, 'the attributes of a block keep their escaped slashes');
        self::assertStringContainsString("src=\"$origin/wp-content/uploads/seed/photo.png\"", $post);
        self::assertStringNotContainsString("http://$host", $post);
        self::assertStringNotContainsString('https://' . $host, $post);
        // what must stay
        self::assertStringContainsString('https://foreign.example/page', $post, 'a foreign domain');
        self::assertStringContainsString("http://shop.$host/x", $post, 'a sub-domain is another host');
        self::assertStringContainsString('mailto:editor@localhost', $post, 'an e-mail address');
        self::assertStringContainsString("The bare host $host stays in this sentence.", $post, 'a bare host name in text');
        self::assertStringContainsString('src="/wp-content/uploads/seed/photo.png"', $post, 'a relative link');
        self::assertStringContainsString('Grüße 🌍', $post, 'four-byte characters survive');
        // postmeta: JSON of a page builder, serialized data, menu entries
        $builder = TableCompare::cell($target, "SELECT meta_value FROM {$prefix}postmeta WHERE meta_key = '_builder_data'");
        self::assertStringContainsString($escaped . '\/wp-content\/uploads\/seed\/photo.png', $builder);
        self::assertStringContainsString('https:\/\/foreign.example\/page', $builder);
        self::assertIsArray(json_decode($builder, true));
        $meta = unserialize(TableCompare::cell($target, "SELECT meta_value FROM {$prefix}postmeta WHERE meta_key = '_seed_serialized'"), ['allowed_classes' => false]);
        self::assertIsArray($meta);
        self::assertSame("$origin/seed/", $meta['plain']);
        self::assertSame("$origin/", TableCompare::cell($target, "SELECT meta_value FROM {$prefix}postmeta WHERE meta_key = '_menu_item_url' AND meta_value LIKE '%/' AND meta_value NOT LIKE '%foreign%'"));
        self::assertSame('https://foreign.example/', TableCompare::cell($target, "SELECT meta_value FROM {$prefix}postmeta WHERE meta_key = '_menu_item_url' AND meta_value LIKE '%foreign%'"));
        // the comment and the user
        self::assertSame("See $origin/about-us/ and https://foreign.example/page", TableCompare::cell($target, "SELECT comment_content FROM {$prefix}comments WHERE comment_author = 'Visitor'"));
        self::assertSame("$origin/visitor/", TableCompare::cell($target, "SELECT comment_author_url FROM {$prefix}comments WHERE comment_author = 'Visitor'"));
        self::assertSame("$origin/author/editor/", TableCompare::cell($target, "SELECT user_url FROM {$prefix}users WHERE user_login = 'editor'"));
        // guid is an identity, not a link: it is left alone (the WordPress rule)
        self::assertSame("http://$host/wp-content/uploads/seed/photo.png", TableCompare::cell($target, "SELECT guid FROM {$prefix}posts WHERE post_name = 'seeded-image'"));
        self::assertSame(
            TableCompare::cell($source, "SELECT GROUP_CONCAT(guid ORDER BY ID) FROM wp_posts"),
            TableCompare::cell($target, "SELECT GROUP_CONCAT(guid ORDER BY ID) FROM {$prefix}posts"),
            'no guid was changed',
        );
        self::assertSame('0', TableCompare::cell($target, "SELECT COUNT(*) FROM {$prefix}posts WHERE post_content LIKE '%http://$host/%'"));
        self::assertSame('1', TableCompare::cell($source, "SELECT COUNT(*) FROM wp_posts WHERE post_name = 'about-us' AND post_content LIKE '%href=\"http://$host/about-us/\"%'"), 'the source was not touched');

        // --- the real tool as the oracle: nothing is left, in any notation
        $this->assertNothingLeft($root, $host, $new . $url);

        // --- the site works
        $client = new WordPressClient(self::TARGET_URL, $new, $url);
        $home = $client->get('/');
        self::assertSame(200, $home['status'], 'the front page answers (no redirect to the old address)');
        self::assertStringContainsString($origin . '/', $home['body']);
        self::assertStringNotContainsString("http://$host", $home['body']);
        if (!$dev) {
            // a production copy is a production copy: search engines are welcome, mail is not stopped
            self::assertArrayNotHasKey('x-robots-tag', $home['headers']);
            self::assertFileDoesNotExist($root . '/wp-content/mu-plugins/threadsafe-dev.php');
            self::assertSame('1', TableCompare::cell($target, "SELECT option_value FROM {$prefix}options WHERE option_name = 'blog_public'"));
            self::assertStringNotContainsString('ThreadSafe dev mode', (string) file_get_contents($root . '/.htaccess'));
        }
        $page = $client->get('/about-us/');
        self::assertSame(200, $page['status'], 'the pretty address works (the .htaccess)');
        self::assertStringContainsString('About the seeded site', $page['body']);
        self::assertStringContainsString("$origin/wp-content/uploads/seed/photo.png", $page['body'], 'the image points to the new address');
        self::assertStringContainsString('Grüße 🌍', $page['body']);
        $image = $client->get('/wp-content/uploads/seed/photo.png');
        self::assertSame(200, $image['status']);
        self::assertSame('image/png', $image['headers']['content-type'] ?? '');
        $postPage = $client->get('/hello-seed/');
        self::assertSame(200, $postPage['status']);
        self::assertStringContainsString("href=\"$origin/about-us/\"", $postPage['body']);
        $login = $client->get('/wp-login.php');
        self::assertSame(200, $login['status']);
        self::assertStringContainsString('name="log"', $login['body']);
        self::assertStringNotContainsString("http://$host", $login['body']);
        $rest = json_decode($client->get('/?rest_route=/')['body'], true);
        self::assertIsArray($rest);
        self::assertSame($origin, rtrim((string) $rest['home'], '/'), 'the REST index says the new address');

        // --- the users log in and have their rights (the roles are keyed by the table prefix)
        $admin = new WordPressClient(self::TARGET_URL, $new, $url);
        self::assertTrue($admin->login('seedadmin', self::PASSWORD), 'the administrator logs in');
        $settings = $admin->get('/wp-admin/options-general.php');
        self::assertSame(200, $settings['status'], 'the administrator may change the settings');
        self::assertStringContainsString('General Settings', $settings['body']);
        self::assertStringNotContainsString("http://$host", $settings['body']);
        $editor = new WordPressClient(self::TARGET_URL, $new, $url);
        self::assertTrue($editor->login('editor', self::PASSWORD), 'the editor logs in');
        self::assertSame(200, $editor->get('/wp-admin/post-new.php')['status'], 'the editor may write posts');
        self::assertSame(403, $editor->get('/wp-admin/options-general.php')['status'], 'but not change the settings');
        $reader = new WordPressClient(self::TARGET_URL, $new, $url);
        self::assertTrue($reader->login('reader', self::PASSWORD), 'the subscriber logs in');
        self::assertSame(200, $reader->get('/wp-admin/profile.php')['status']);
        self::assertSame(403, $reader->get('/wp-admin/post-new.php')['status'], 'and may not write posts');

        // the source still answers, with its own address
        $sourceHome = Stack::get('http://wordpress' . $major, $host, '/');
        self::assertSame(200, $sourceHome->status);
        self::assertStringContainsString("http://$host/", $sourceHome->body);
    }

    /**
     * `wp search-replace --dry-run` finds what is left of the old address in the restored site: the plain, the JSON-escaped and the URL-encoded notation,
     * in every table, `guid` excluded (the plan's rule). The searches start at the slashes, so that the bare host name in a sentence and a sub-domain,
     * which stay on purpose, are not found.
     */
    private function assertNothingLeft(string $root, string $oldHost, string $newHost): void
    {
        $encoded = static fn (string $host): string => '%2F%2F' . str_replace(':', '%3A', $host);
        foreach ([
            ['//' . $oldHost, '//' . $newHost],
            ['\/\/' . $oldHost, '\/\/' . $newHost],
            [$encoded($oldHost), $encoded($newHost)],
        ] as [$search, $replace]) {
            $output = [];
            exec(sprintf(
                'wp --path=%s --allow-root search-replace %s %s --dry-run --all-tables-with-prefix --skip-columns=guid 2>&1',
                escapeshellarg($root),
                escapeshellarg($search),
                escapeshellarg($replace),
            ), $output, $code);
            $text = implode("\n", $output);
            self::assertSame(0, $code, $text);
            self::assertStringContainsString('Success: 0 replacements to be made.', $text, "wp search-replace still finds $search:\n" . $text);
        }
    }
}
