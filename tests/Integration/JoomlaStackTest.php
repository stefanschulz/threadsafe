<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Integration;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use ThreadSafe\Archive\ZipReader;
use ThreadSafe\Backup\Manifest;
use ThreadSafe\Build\RestoreBuilder;
use ThreadSafe\Joomla\Config\JoomlaConfigFile;
use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Profile\SiteProfile;
use ThreadSafe\Tests\Support\CliProcess;
use ThreadSafe\Tests\Support\Stack;
use ThreadSafe\Tests\Support\TableCompare;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Tests\Support\UnzipOracle;
use ThreadSafe\Tests\Support\WebWizard;
use ThreadSafe\Tests\Support\WizardJourney;

/**
 * Phase 6 on the real thing: the two seeded Joomla sites of the stack (Joomla 5.4 on port 8195, Joomla 6 on port 8196; tests/stack/seed-joomla.sh)
 * are backed up through their profiles, and the backups are restored into the empty target stack - by one command on another domain, by
 * one command into a sub-directory, and through the `threadsafe-restore.php` wizard under limits of 5 seconds and 64 MB - and the restored site must
 * serve: its pages answer, its content points to the new domain and to nothing of the old one, what must not change did not change, and
 * its configuration is right for the host (database, interface, paths).
 *
 * Runs in the last phase of tests/stack/run.sh: it wipes the target stack, which the earlier phases use. The target's PHP has no
 * `mysqli`, which is what makes the choice of the database interface a real test.
 */
#[Group('joomla')]
final class JoomlaStackTest extends IntegrationTestCase
{
    use TempDirectory;

    private const string TARGET = '/shops/target';

    /** @var array<int, array{string, string, string, string}> major version => directory, host, URL in the stack, database */
    private const array SITES = [
        5 => ['/joomla5', 'localhost:8195', 'http://joomla5', 'joomla5'],
        6 => ['/joomla6', 'localhost:8196', 'http://joomla6', 'joomla6'],
    ];

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    /** @return iterable<string, array{int}> */
    public static function versions(): iterable
    {
        yield 'Joomla 5.4' => [5];
        yield 'Joomla 6' => [6];
    }

    private static function password(): string
    {
        return getenv('DB_ROOT_PASSWORD') ?: 'prestashop';
    }

    private function requireSite(int $major): void
    {
        if (!is_file(self::SITES[$major][0] . '/libraries/src/Version.php') || !is_dir(self::TARGET)) {
            self::markTestSkipped('The Joomla sites are not mounted (tests/stack/seed-joomla.sh) or the target is missing.');
        }
    }

    private static function sourceDb(int $major): PDO
    {
        return new PDO('mysql:host=db;dbname=' . self::SITES[$major][3] . ';charset=utf8mb4', 'root', self::password(), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
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
            'id' => 'site', 'name' => 'Seeded Joomla ' . $major, 'type' => 'joomla', 'path' => self::SITES[$major][0], 'storage' => $base . '/storage',
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
        $admin->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }

    // ------------------------------------------------------------------------------------------------- the backup

    #[DataProvider('versions')]
    public function testTheSeededSiteIsBackedUpWithWhatItIsAndWithoutWhatJoomlaRebuilds(int $major): void
    {
        $this->requireSite($major);
        [, $host] = self::SITES[$major];
        [, $archive, $manifest] = $this->backUp($major);

        self::assertSame('Joomla', $manifest->application['name']);
        self::assertStringStartsWith($major . '.', (string) $manifest->application['version']);
        self::assertSame('snapshot', $manifest->consistency);
        self::assertSame('joom_', $manifest->extras['dbPrefix']);
        self::assertSame('Seeded Joomla ' . $major, $manifest->extras['siteName']);
        self::assertSame('', $manifest->extras['liveSite']);
        self::assertTrue($manifest->extras['sefRewrite']);
        self::assertSame($manifest->application['version'], $manifest->extras['versionInDatabase'], 'the database knows the version the files have');
        self::assertGreaterThan(150, $manifest->extras['coreExtensions']);
        self::assertSame(['plg_system_threadsafe_fixture'], array_column($manifest->extras['extensions'], 'name'), 'the core ones are counted, the third-party one is listed');
        self::assertSame('1.2.3', $manifest->extras['extensions'][0]['version']);
        self::assertSame($host, $manifest->extras['addressCandidates'][0]['host'], 'the host that the content points to most often is the site');
        self::assertContains('foreign.example', array_column($manifest->extras['addressCandidates'], 'host'));
        self::assertContains('en-GB', array_column($manifest->extras['languages'], 'code'));
        self::assertContains('cassiopeia', array_column($manifest->extras['templates'], 'template'));
        self::assertStringNotContainsString(self::password(), (string) json_encode($manifest->toArray()), 'no password in the manifest');
        self::assertDoesNotMatchRegularExpression('/"password"\s*:/', $manifest->toJson());

        self::assertTrue(ZipReader::open($archive)->verify()->ok());
        [$ok, $output] = UnzipOracle::test($archive);
        self::assertTrue($ok, $output);

        $names = [];
        foreach (ZipReader::open($archive)->entries() as $entry) {
            $names[$entry->name] = true;
        }
        foreach (['files/configuration.php', 'files/.htaccess', 'files/images/seed/photo.bin', 'files/libraries/src/Version.php'] as $name) {
            self::assertArrayHasKey($name, $names, "$name is in the backup");
        }
        foreach (['files/cache/seed.cache', 'files/administrator/cache/seed.cache', 'files/tmp/seed.tmp', 'files/administrator/logs/seed.log.php', 'files/administrator/components/com_akeebabackup/backup/seed.jpa'] as $name) {
            self::assertArrayNotHasKey($name, $names, "$name is left out");
        }
        foreach (array_keys($names) as $name) {
            self::assertStringStartsNotWith('files/cache/', $name, 'the cache directories are left out as a whole');
            self::assertStringStartsNotWith('files/tmp/', $name);
        }
        // field test: Joomla ships code in directories called "cache"; the filter left out the cache of the site, not these
        foreach (['files/plugins/system/cache/cache.xml', 'files/administrator/components/com_cache/tmpl/cache/default.php'] as $name) {
            self::assertArrayHasKey($name, $names, "$name is code and is in the backup");
        }
        self::assertGreaterThan(8000, $manifest->counts['files']);
    }

    // ------------------------------------------------------------------------------------------------- the restore by one command

    #[DataProvider('versions')]
    public function testTheBackupIsRestoredWithOneCommandOnAnotherDomain(int $major): void
    {
        $this->requireSite($major);
        [$base, $archive, $manifest] = $this->backUp($major);
        $this->emptyTarget('joomla_target');
        file_put_contents($base . '/db-password', self::password() . "\n");
        $command = [
            'restore', $archive, '--root=' . self::TARGET, '--to=' . Stack::TARGET_HOST,
            '--db-host=target-db', '--db-name=joomla_target', '--db-user=root', '--db-password-file=' . $base . '/db-password',
            '--db-driver=pdo',   // the PHP of the target's web server has no mysqli; this PHP has
            '--server-path=/var/www/html', '--self-test-url=' . Stack::TARGET_URL, '--report=' . $base . '/result.json',
        ];

        putenv('THREADSAFE_DATA=' . $base . '/data');
        try {
            [$code, $stdout, $stderr] = CliProcess::run($command, 900);
            self::assertSame(0, $code, 'Without --yes it only inspects: ' . $stderr . $stdout);
            self::assertStringContainsString('Nothing was changed.', $stdout);
            self::assertSame([], TableCompare::tables(self::targetDb('joomla_target')), 'nothing was written');

            [$code, $stdout, $stderr] = CliProcess::run([...$command, '--yes'], 1800);
            self::assertSame(0, $code, $stderr . $stdout);
            self::assertStringContainsString('Restored ' . $manifest->backupId, $stdout);
            self::assertStringContainsString('Run finished.', $stdout);
        } finally {
            putenv('THREADSAFE_DATA');
        }
        self::assertSame([], glob($base . '/data/runs/*.json') ?: [], 'the run state that held the password is deleted');
        self::assertDoesNotMatchRegularExpression('/"password"\s*:/', (string) file_get_contents($base . '/result.json'));

        $this->assertRestored($major, $manifest, $archive, self::TARGET, 'joomla_target', Stack::TARGET_HOST, '', '/var/www/html');
    }

    /**
     * What a restored Joomla must be, wherever and however it was restored.
     *
     * @param string $path the base path ('' or '/sub/') the site is served under on the target host
     */
    private function assertRestored(int $major, Manifest $manifest, string $archive, string $root, string $database, string $targetHost, string $path, string $serverPath, string $expectedDbType = 'pdomysql'): void
    {
        [$directory, $host] = self::SITES[$major];
        $source = self::sourceDb($major);
        $target = self::targetDb($database);
        $url = rtrim($path, '/');

        // --- the configuration: right for this host, and nothing else of it changed
        $config = JoomlaConfigFile::fromPath($root . '/configuration.php');
        $sourceConfig = JoomlaConfigFile::fromPath($directory . '/configuration.php');
        self::assertSame('target-db', $config->database()->host);
        self::assertSame($database, $config->database()->name);
        self::assertSame('joom_', $config->database()->prefix);
        self::assertSame($expectedDbType, $config->get('dbtype'));
        self::assertSame($serverPath . '/tmp', $config->get('tmp_path'));
        self::assertSame($serverPath . '/administrator/logs', $config->get('log_path'));
        self::assertSame($sourceConfig->get('secret'), $config->get('secret'), 'the secret of the site is kept');
        self::assertSame($sourceConfig->get('sitename'), $config->get('sitename'));
        self::assertSame('', $config->get('live_site'));
        self::assertSame('db', $sourceConfig->database()->host, 'the source was not touched');

        // --- the directories that a backup leaves out are there again, empty, and what was in them is not
        foreach (['tmp', 'cache', 'administrator/cache', 'administrator/logs'] as $relative) {
            self::assertFileExists($root . '/' . $relative . '/index.html', "$relative is made again");
        }
        foreach (['cache/seed.cache', 'administrator/cache/seed.cache', 'tmp/seed.tmp', 'administrator/logs/seed.log.php', 'administrator/components/com_akeebabackup/backup/seed.jpa'] as $left) {
            self::assertFileDoesNotExist($root . '/' . $left, "$left was left out");
        }

        // --- the database: what is kept whole is whole, what keeps its structure is empty, and the old domain is gone from the content
        $sameKind = TableCompare::sameKind($source, $target);
        $structureOnly = array_map(static fn (string $t): string => 'joom_' . $t, $manifest->filters['structureOnlyTables']);
        $different = [];
        foreach (TableCompare::tables($source) as $table) {
            if (in_array($table, $structureOnly, true)) {
                self::assertLessThan(20, (int) TableCompare::cell($target, "SELECT COUNT(*) FROM `$table`"), "$table keeps only its structure");
                continue;
            }
            if ($table === 'joom_action_logs') {
                self::assertSame(TableCompare::cell($source, "SELECT COUNT(*) FROM `$table`"), TableCompare::cell($target, "SELECT COUNT(*) FROM `$table`"), 'the audit trail is kept whole');
                continue;
            }
            if ($table === 'joom_scheduler_logs') {
                continue;   // Joomla runs its due tasks on any request, also on the restored site
            }
            if ($table === 'joom_scheduler_tasks' || TableCompare::mentions($source, $table, $host)) {
                self::assertSame(TableCompare::cell($source, "SELECT COUNT(*) FROM `$table`"), TableCompare::cell($target, "SELECT COUNT(*) FROM `$table`"), "$table has all rows");
                continue;
            }
            if (TableCompare::fingerprint($source, $table, $sameKind) !== TableCompare::fingerprint($target, $table, $sameKind)) {
                $different[] = $table;
            }
        }
        self::assertSame([], $different, 'Every table in which the old domain does not occur has the checksum of the source.');
        self::assertSame('1', TableCompare::cell($target, 'SELECT COUNT(*) FROM joom_finder_taxonomy'), 'the ROOT row of the search index is there: the indexer needs it');
        self::assertSame('0', TableCompare::cell($target, 'SELECT COUNT(*) FROM joom_finder_links'));
        self::assertGreaterThanOrEqual(3, (int) TableCompare::cell($source, 'SELECT COUNT(*) FROM joom_session'), 'the source keeps its sessions');

        $article = TableCompare::cell($target, 'SELECT introtext FROM joom_content WHERE id = 901');
        $new = $targetHost;
        self::assertStringContainsString("http://$new/index.php/about", $article, 'plain, http');
        self::assertStringContainsString("https://$new/index.php/component/content/article/about-us?catid=2", $article, 'plain, https');
        self::assertStringContainsString('http%3A%2F%2F' . str_replace(':', '%3A', $new) . '%2Findex.php%2Fabout', $article, 'URL-encoded');
        self::assertStringNotContainsString("http://$host/", $article);
        self::assertStringNotContainsString('https://' . $host, $article);
        self::assertStringNotContainsString(str_replace(':', '%3A', $host) . '%2F', $article);
        // what must stay
        self::assertStringContainsString('https://foreign.example/page', $article, 'a foreign domain');
        self::assertStringContainsString("http://shop.$host/x", $article, 'a sub-domain is another host');
        self::assertStringContainsString('mailto:editor@localhost', $article, 'an e-mail address');
        self::assertStringContainsString("The bare host $host stays in this sentence.", $article, 'a bare host name in text');
        self::assertStringContainsString('src="/images/seed/photo.bin"', $article, 'a relative link');
        self::assertStringContainsString('Grüße', TableCompare::cell($target, 'SELECT `fulltext` FROM joom_content WHERE id = 901'));
        self::assertStringContainsString('🌍', TableCompare::cell($target, 'SELECT `fulltext` FROM joom_content WHERE id = 901'), 'four-byte characters survive');
        // JSON keeps its escaped slashes and is still JSON
        $urls = TableCompare::cell($target, 'SELECT urls FROM joom_content WHERE id = 901');
        self::assertStringContainsString('https:\/\/' . $new . '\/index.php\/about', $urls);
        self::assertStringContainsString('https:\/\/foreign.example\/page', $urls);
        self::assertIsArray(json_decode($urls, true));
        $module = TableCompare::cell($target, 'SELECT params FROM joom_modules WHERE id = 901');
        self::assertStringContainsString('https:\/\/' . $new . '\/index.php\/about', $module);
        self::assertIsArray(json_decode($module, true));
        self::assertStringContainsString("http://$new/", TableCompare::cell($target, 'SELECT content FROM joom_modules WHERE id = 901'));
        self::assertSame("https://$new/index.php/welcome", TableCompare::cell($target, 'SELECT link FROM joom_menu WHERE id = 902'));
        self::assertStringContainsString('Seeded at https:\/\/' . $new . '\/', TableCompare::cell($target, "SELECT params FROM joom_template_styles WHERE template = 'cassiopeia' AND client_id = 0"));
        self::assertStringContainsString('https:\/\/' . $new . '\/api\/', TableCompare::cell($target, "SELECT params FROM joom_extensions WHERE element = 'threadsafe_fixture'"));
        self::assertSame("http://$new/old-page", TableCompare::cell($target, 'SELECT old_url FROM joom_redirect_links LIMIT 1'));
        self::assertSame('0', TableCompare::cell($target, "SELECT COUNT(*) FROM joom_content WHERE introtext LIKE '%http://$host/%'"));
        self::assertSame("http://$host/old-page", TableCompare::cell($source, 'SELECT old_url FROM joom_redirect_links LIMIT 1'), 'the source was not touched');

        // --- the files: every file of the backup is what the source has (except what the restore had to change)
        $files = 0;
        foreach (ZipReader::open($archive)->entries() as $entry) {
            if (!str_starts_with($entry->name, 'files/') || $entry->isDirectory) {
                continue;
            }
            $relative = substr($entry->name, 6);
            ++$files;
            if (in_array($relative, ['configuration.php', '.htaccess'], true)) {
                continue;
            }
            self::assertSame(md5_file($directory . '/' . $relative), md5_file($root . '/' . $relative), "The file /$relative differs from the source.");
        }
        self::assertSame($manifest->counts['files'], $files);
        self::assertSame((int) fileowner($root), (int) fileowner($root . '/index.php'), 'files belong to the owner of the target directory');

        // --- the site serves
        $home = Stack::get(Stack::TARGET_URL, $targetHost, $url . '/');
        self::assertSame(200, $home->status);
        self::assertStringContainsString('Joomla! - Open Source Content Management', $home->body);
        self::assertStringContainsString("http://$new/", $home->body, 'the custom module points to the new domain');
        $about = Stack::get(Stack::TARGET_URL, $targetHost, $url . '/about');
        self::assertSame(200, $about->status, 'the search-engine friendly address works (the .htaccess)');
        self::assertStringContainsString('About the seeded site', $about->body);
        self::assertStringContainsString("http://$new/index.php/welcome", $about->body);
        $welcome = Stack::get(Stack::TARGET_URL, $targetHost, $url . '/index.php?option=com_content&view=article&id=901&catid=2');
        self::assertSame(200, $welcome->status);
        self::assertStringContainsString('Grüße', $welcome->body);
        self::assertStringContainsString('🌍', $welcome->body);
        $admin = Stack::get(Stack::TARGET_URL, $targetHost, $url . '/administrator/');
        self::assertSame(200, $admin->status);
        self::assertStringContainsString('name="username"', $admin->body, 'the login of the administration');
        $api = Stack::get(Stack::TARGET_URL, $targetHost, $url . '/api/v1/content/articles', ['Accept' => 'application/vnd.api+json']);
        self::assertSame(401, $api->status, 'the web service API answers: it asks for a token');
        self::assertStringContainsString('json', (string) $api->header('content-type'), 'and it is the API that answers, not the front end');
    }

    // ------------------------------------------------------------------------------------------------- a sub-directory

    public function testTheBackupIsRestoredIntoASubDirectoryAndItsRewriteRulesFollow(): void
    {
        $major = 6;
        $this->requireSite($major);
        [$base, $archive, $manifest] = $this->backUp($major);
        $root = self::TARGET . '/sub';
        $this->emptyTarget('joomla_target_sub', $root);
        file_put_contents($base . '/db-password', self::password() . "\n");

        putenv('THREADSAFE_DATA=' . $base . '/data');
        try {
            [$code, $stdout, $stderr] = CliProcess::run([
                'restore', $archive, '--root=' . $root, '--to=' . Stack::TARGET_HOST, '--path=/sub/',
                '--db-host=target-db', '--db-name=joomla_target_sub', '--db-user=root', '--db-password-file=' . $base . '/db-password',
                '--db-driver=pdo', '--server-path=/var/www/html/sub', '--self-test-url=' . Stack::TARGET_URL, '--yes',
            ], 1800);
        } finally {
            putenv('THREADSAFE_DATA');
        }
        self::assertSame(0, $code, $stderr . $stdout);

        $htaccess = (string) file_get_contents($root . '/.htaccess');
        self::assertStringContainsString("\n\tRewriteBase /sub/\n", $htaccess);
        self::assertStringContainsString('RewriteCond %{REQUEST_URI} ^/sub/api/', $htaccess, 'the API rules name the directory');

        $this->assertRestored($major, $manifest, $archive, $root, 'joomla_target_sub', Stack::TARGET_HOST, '/sub/', '/var/www/html/sub');
    }

    // ------------------------------------------------------------------------------------------------- dev mode

    public function testADevCopyIsRestoredThatSendsNoMailAndKeepsSearchEnginesOut(): void
    {
        $major = 6;
        $this->requireSite($major);
        [$base, $archive] = $this->backUp($major);
        $this->emptyTarget('joomla_target');
        file_put_contents($base . '/db-password', self::password() . "\n");

        putenv('THREADSAFE_DATA=' . $base . '/data');
        try {
            [$code, $stdout, $stderr] = CliProcess::run([
                'restore', $archive, '--root=' . self::TARGET, '--to=' . Stack::TARGET_HOST, '--mode=dev',
                '--db-host=target-db', '--db-name=joomla_target', '--db-user=root', '--db-password-file=' . $base . '/db-password',
                '--db-driver=pdo', '--server-path=/var/www/html', '--self-test-url=' . Stack::TARGET_URL, '--yes',
            ], 1800);
        } finally {
            putenv('THREADSAFE_DATA');
        }
        self::assertSame(0, $code, $stderr . $stdout);

        $config = JoomlaConfigFile::fromPath(self::TARGET . '/configuration.php');
        self::assertFalse($config->get('mailonline'), 'the copy sends no mail');
        self::assertSame('noindex, nofollow', $config->get('robots'));
        self::assertSame('[DEV] Seeded Joomla 6', $config->get('sitename'));
        self::assertSame("User-agent: *\nDisallow: /\n", file_get_contents(self::TARGET . '/robots.txt'));
        self::assertStringStartsWith('# BEGIN ThreadSafe dev mode', (string) file_get_contents(self::TARGET . '/.htaccess'));

        $home = Stack::get(Stack::TARGET_URL, Stack::TARGET_HOST, '/');
        self::assertSame(200, $home->status);
        self::assertSame('noindex, nofollow', $home->header('x-robots-tag'), 'the web server sends the header');
        self::assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $home->body);
        self::assertStringContainsString('[DEV] Seeded Joomla 6', $home->body, 'the copy is marked in the title of its pages');
        $about = Stack::get(Stack::TARGET_URL, Stack::TARGET_HOST, '/about');
        self::assertSame(200, $about->status, 'the rules of Joomla still work behind the block');
        self::assertSame('noindex, nofollow', $about->header('x-robots-tag'));
        $robots = Stack::get(Stack::TARGET_URL, Stack::TARGET_HOST, '/robots.txt');
        self::assertStringContainsString('Disallow: /', $robots->body);

        // the source was not touched
        self::assertNotFalse(strpos((string) file_get_contents(self::SITES[$major][0] . '/configuration.php'), "\$sitename = 'Seeded Joomla 6'"));
        self::assertTrue(JoomlaConfigFile::fromPath(self::SITES[$major][0] . '/configuration.php')->get('mailonline'));
    }

    // ------------------------------------------------------------------------------------------------- the wizard

    public function testTheBackupIsRestoredThroughTheWizardUnderTightLimits(): void
    {
        $major = 5;
        $this->requireSite($major);
        [$base, $archive, $manifest] = $this->backUp($major);
        $this->emptyTarget('joomla_target');

        // what the person puts into the empty directory: the tool, the key, the backup
        (new RestoreBuilder(dirname(__DIR__, 2)))->build(self::TARGET . '/threadsafe-restore.php');
        file_put_contents(self::TARGET . '/threadsafe-restore-key.php', "<?php return 'a long secret from the joomla test';");
        copy($archive, self::TARGET . '/' . basename($archive));
        foreach (glob(self::TARGET . '/*') ?: [] as $file) {
            chmod($file, 0644);
        }

        $wizard = new WebWizard(baseUrl: Stack::TARGET_URL, scriptPath: '/threadsafe-restore.php');
        $journey = WizardJourney::restore($wizard, 'a long secret from the joomla test', [
            'root' => '/var/www/html', 'db_host' => 'target-db', 'db_port' => '3306', 'db_name' => 'joomla_target', 'db_user' => 'root', 'db_password' => self::password(), 'db_prefix' => 'joom_',
            'f_from' => '', 'f_to' => Stack::TARGET_HOST, 'f_path' => '',
        ]);
        self::assertGreaterThan(3, $journey['ticks'], 'a restore of 9,000 files in requests of 5 seconds takes several of them');
        $gone = $wizard->request('POST', '/', ['action' => 'cleanup', 'csrf' => $wizard->csrf()]);
        self::assertStringContainsString('The tool is gone', $gone['body']);
        foreach (['threadsafe-restore.php', 'threadsafe-restore-key.php', '.threadsafe-restore'] as $name) {
            self::assertFileDoesNotExist(self::TARGET . '/' . $name, "$name is deleted");
        }
        @unlink(self::TARGET . '/' . basename($archive));
        @unlink(self::TARGET . '/' . basename($archive, '.zip') . '.json');

        // the web server's PHP has no mysqli: the wizard runs in it, so the site is told to use the PDO interface
        $this->assertRestored($major, $manifest, $archive, self::TARGET, 'joomla_target', Stack::TARGET_HOST, '', '/var/www/html');
    }
}
