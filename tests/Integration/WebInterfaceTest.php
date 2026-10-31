<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Integration;

use PDO;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;
use ThreadSafe\Backup\Manifest;
use ThreadSafe\Backup\PartMarker;
use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Profile\SiteProfile;
use ThreadSafe\Tests\Support\CliProcess;
use ThreadSafe\Tests\Support\OathtoolOracle;
use ThreadSafe\Tests\Support\ZbarOracle;
use ThreadSafe\Tests\Support\ShopSmoke;
use ThreadSafe\Tests\Support\Stack;
use ThreadSafe\Tests\Support\WebUi;
use ThreadSafe\Tests\Support\WebWizard;
use ThreadSafe\Tests\Support\WizardJourney;

/**
 * The web interface of ThreadSafe behind a real Apache (the service `web` of the Docker stack, docs/PHASE4.md 4.2): PHP limits of 5
 * seconds and 64 MB, the seeded shop as the site. One backup made through the interface is the material of most tests; the last tests
 * use what the earlier ones left, so they run in the order of this file.
 *
 *   journey      setup in the browser, login, profile, a backup in many steps, check, comparison with a command line backup
 *   download     the warning, the token, a broken download resumed, every part against its recorded checksum
 *   resume       a run nobody ticks becomes interrupted and is continued; the archive equals the uninterrupted one
 *   concurrency  two requests tick one run at the same moment; a second start is refused
 *   restore kit  threadsafe-restore.php and the key from the page restore that backup onto the target stack through the wizard
 *   security     what a stranger and a forged request get; the two layouts of the installation; switched off
 *   audit        the story is in the log and no secret is
 *   throttle     wrong passwords are slowed down
 */
#[Group('web-ui')]
final class WebInterfaceTest extends IntegrationTestCase
{
    private const string PASSWORD = 'integration test password';

    private const string SETUP_KEY = 'integration setup key';

    private const string WORK = '/app/.cache/web-ui';

    private static ?WebUi $ui = null;

    /** The base name of the backup that the journey made. */
    private static string $backup = '';

    /** The key that the restore help made. */
    private static string $key = '';

    public static function setUpBeforeClass(): void
    {
        if (!Stack::enabled() || !is_dir('/web-data')) {
            return;
        }
        exec('find /web-data /web-storage -mindepth 1 -delete 2>&1', $ignored, $code);
        self::assertSame(0, $code, 'the volumes of the web service can be emptied');
        exec('rm -rf ' . escapeshellarg(self::WORK));
        mkdir(self::WORK, 0777, true);
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (!is_dir('/web-data')) {
            self::markTestSkipped('The web service is not part of this stack (docker compose --profile web).');
        }
    }

    private static function ui(): WebUi
    {
        return self::$ui ?? throw new \LogicException('The journey has not run.');
    }

    /**
     * Ticks a run (or a check) until it ends.
     *
     * @return array{ticks: int, last: array<string, mixed>, slowest: float}
     */
    private static function tickToTheEnd(WebUi $ui, string $path, int $limit = 600): array
    {
        $ticks = 0;
        $slowest = 0.0;
        $last = [];
        while ($ticks < $limit) {
            $started = microtime(true);
            $last = $ui->tick($path);
            $slowest = max($slowest, microtime(true) - $started);
            ++$ticks;
            if ($last['status'] === 'busy') {
                usleep(300000);
                continue;
            }
            self::assertContains($last['status'], ['running', 'finished'], 'a tick answered ' . json_encode($last));
            if ($last['status'] === 'finished') {
                return ['ticks' => $ticks, 'last' => $last, 'slowest' => $slowest];
            }
        }
        self::fail("The run did not end within $limit ticks.");
    }

    /** @return list<string> the paths of the parts of a backup in the storage of the web service */
    private static function parts(string $backup): array
    {
        $parts = glob('/web-storage/shop/' . $backup . '*.zip') ?: [];
        sort($parts);

        return $parts;
    }

    /**
     * @param list<string> $parts
     *
     * @return list<string> the names of the entries of all parts, without the manifest and the part markers
     */
    private static function entryNames(array $parts): array
    {
        $names = [];
        foreach ($parts as $part) {
            exec('unzip -Z1 ' . escapeshellarg($part) . ' 2>&1', $lines, $code);
            self::assertSame(0, $code, "unzip -Z1 $part");
            $names = [...$names, ...array_values(array_diff($lines, [Manifest::ENTRY, PartMarker::ENTRY]))];
            $lines = [];
        }
        sort($names);

        return $names;
    }

    private static function backupOf(string $location): string
    {
        self::assertMatchesRegularExpression('~^/runs/(backup-\d{8}-\d{6}-[0-9a-f]{8})$~', $location);

        return substr($location, strlen('/runs/'));
    }

    // ------------------------------------------------------------------------------------------------- the journey

    public function testTheJourneyFromTheFirstSetupToAVerifiedBackup(): void
    {
        $ui = new WebUi();
        self::$ui = $ui;

        // an installation nobody has set up is not there at all
        foreach (['/', '/login', '/setup', '/profiles'] as $path) {
            self::assertSame(404, $ui->get($path)['status'], $path);
        }
        // the person puts the key file into the data directory: now only the setup is there
        file_put_contents('/web-data/setup-key.php', "<?php return '" . self::SETUP_KEY . "';");
        chmod('/web-data/setup-key.php', 0644);
        $setup = $ui->get('/');
        self::assertSame(303, $setup['status']);
        self::assertSame('/setup', $setup['headers']['location']);
        self::assertSame(200, $ui->get('/setup')['status']);

        $wrong = $ui->request('POST', '/setup', ['key' => 'a stranger', 'password' => self::PASSWORD, 'repeat' => self::PASSWORD, 'plain' => '1']);
        self::assertSame(403, $wrong['status']);
        $done = $ui->request('POST', '/setup', ['key' => self::SETUP_KEY, 'password' => self::PASSWORD, 'repeat' => self::PASSWORD, 'plain' => '1']);
        self::assertSame(200, $done['status'], $done['body']);
        self::assertStringContainsString('Setup done', $done['body']);
        self::assertFileDoesNotExist('/web-data/setup-key.php', 'the key file is used up');
        self::assertSame(303, $ui->get('/setup')['status'], 'the setup is closed: the address is unknown now and leads to the login');

        // login
        self::assertFalse($ui->login('not the password'));
        self::assertTrue($ui->login(self::PASSWORD));
        $dashboard = $ui->get('/');
        self::assertStringContainsString('There are no sites yet', $dashboard['body']);

        // the profile of the seeded shop, with the test against the real database
        $detect = $ui->post('/profiles', ['action' => 'detect', 'type' => 'prestashop9', 'path' => '/shops/source', 'id' => '', 'storage' => '/srv/storage/shop', 'compression' => '6', 'db_mode' => 'site', 'default_filters' => '1']);
        self::assertStringContainsString('PrestaShop', $detect['body']);
        self::assertStringContainsString('read from the site', $detect['body']);
        $created = $ui->post('/profiles', [
            'action' => 'save', 'id' => 'shop', 'name' => 'Seeded shop', 'type' => 'prestashop9', 'path' => '/shops/source', 'storage' => '/srv/storage/shop',
            'compression' => '6', 'part_size' => '100M', 'db_mode' => 'site', 'default_filters' => '1', 'exclude' => '', 'structure_only' => '', 'note' => '',
        ]);
        self::assertSame(303, $created['status'], strip_tags($created['body']));
        self::assertSame('/profiles/shop', $created['headers']['location']);

        // the backup: a page, many ticks of a few seconds, a result
        $start = $ui->post('/profiles/shop/backup');
        self::assertSame(303, $start['status']);
        $run = self::backupOf($start['headers']['location']);
        self::assertStringContainsString('data-tick-url="/runs/' . $run . '/tick"', $ui->get("/runs/$run")['body']);
        $result = self::tickToTheEnd($ui, "/runs/$run/tick");
        self::assertGreaterThan(3, $result['ticks'], 'under 5 seconds a backup of the shop takes several steps');
        self::assertLessThan(15.0, $result['slowest'], 'no single request takes long');
        self::assertStringContainsString('Backup finished', $ui->get("/runs/$run")['body']);

        $first = glob('/web-storage/shop/shop_*-p01.zip') ?: [];
        self::assertCount(1, $first);
        self::$backup = substr(basename($first[0]), 0, -strlen('-p01.zip'));
        $parts = self::parts(self::$backup);
        self::assertGreaterThan(1, count($parts), 'parts of 100 MB');
        foreach ($parts as $part) {
            exec('unzip -tq ' . escapeshellarg($part) . ' 2>&1', $lines, $code);
            self::assertSame(0, $code, "unzip -t $part: " . implode("\n", $lines));
        }
        $manifest = Manifest::fromArchive((string) end($parts));
        self::assertSame('table', $manifest->consistency, 'a web run reads the database table by table');
        self::assertGreaterThan(250, $manifest->counts['tables']);

        // the check, as the page does it
        $check = $ui->post('/profiles/shop/backups/' . self::$backup . '/verify');
        self::assertSame(303, $check['status']);
        $checkId = substr($check['headers']['location'], strlen('/checks/'));
        self::tickToTheEnd($ui, "/checks/$checkId/tick");
        self::assertStringContainsString('is intact (full check: ' . count($parts) . ' part(s)', $ui->get("/checks/$checkId")['body']);
        // (the shop has symbolic links in the back office, which a backup skips with a warning: the state is "ok, with warnings")
        self::assertMatchesRegularExpression('/<strong>ok(, with warnings)?<\/strong>/', $ui->get('/profiles/shop')['body']);

        // the dashboard knows it
        $card = $ui->get('/')['body'];
        self::assertStringContainsString('<article class="card ok">', $card);

        // the same backup made by the command line has the same content (consistency and the time apart)
        $cliData = self::WORK . '/cli-data';
        (new ProfileStore($cliData . '/profiles'))->save(SiteProfile::fromArray(['id' => 'shop', 'type' => 'prestashop9', 'path' => '/shops/source', 'storage' => self::WORK . '/cli-storage']));
        putenv('THREADSAFE_DATA=' . $cliData);
        try {
            [$code, $stdout, $stderr] = CliProcess::run(['backup', 'shop', '--part-size=100M'], 1800);
            self::assertSame(0, $code, $stdout . $stderr);
        } finally {
            putenv('THREADSAFE_DATA');
        }
        $cliParts = glob(self::WORK . '/cli-storage/shop_*-p*.zip') ?: [];
        sort($cliParts);
        $cli = Manifest::fromArchive((string) end($cliParts));
        self::assertSame('snapshot', $cli->consistency);
        foreach (['files', 'directories', 'tables', 'fileBytes'] as $what) {
            self::assertSame($cli->counts[$what], $manifest->counts[$what], "$what: the web backup and the command line backup agree");
        }
        self::assertSame(self::entryNames($cliParts), self::entryNames($parts), 'the same files and the same tables are in both archives');
    }

    // ------------------------------------------------------------------------------------------------- download

    #[Depends('testTheJourneyFromTheFirstSetupToAVerifiedBackup')]
    public function testADownloadIsWarnedAboutBrokenAndResumedAndEveryPartMatchesItsChecksum(): void
    {
        $ui = self::ui();
        $route = '/profiles/shop/backups/' . self::$backup . '/download';
        $parts = self::parts(self::$backup);

        // the warning above the threshold: one megabyte, so that the 100 MB parts are large
        $saved = $ui->post('/system/settings', ['idle' => '120', 'days' => '7', 'warn_mb' => '1', 'accel' => 'none', 'prefix' => '', 'audit_mb' => '5', 'pretty' => '1']);
        self::assertSame(303, $saved['status'], strip_tags($saved['body']));
        $dialog = $ui->get($route);
        self::assertSame(200, $dialog['status']);
        self::assertStringContainsString('Large files', $dialog['body']);
        self::assertStringContainsString('scp USER@HOST:/srv/storage/shop/' . basename($parts[0]), $dialog['body']);
        foreach ($parts as $part) {
            self::assertStringContainsString((string) hash_file('sha256', $part), $dialog['body'], 'the checksum of ' . basename($part));
        }

        // part one: the connection breaks after 3 MB, the download is resumed at that byte with If-Range
        $file = basename($parts[0]);
        $issued = $ui->post($route, ['part' => $file]);
        self::assertSame(303, $issued['status']);
        $address = $issued['headers']['location'];
        self::assertMatchesRegularExpression('~^/download/[0-9a-f]{48}/' . preg_quote($file, '~') . '$~', $address);
        $size = (int) filesize($parts[0]);
        $sha = (string) hash_file('sha256', $parts[0]);

        $broken = $ui->request('GET', $address, [], [], 3_000_000);
        self::assertSame(200, $broken['status']);
        self::assertSame((string) $size, $broken['headers']['content-length']);
        self::assertSame('"' . $sha . '"', $broken['headers']['etag'] ?? '', 'the ETag is the SHA-256 recorded for the part');
        self::assertSame('bytes', $broken['headers']['accept-ranges']);
        self::assertSame(3_000_000, strlen($broken['body']));
        $context = hash_init('sha256');
        hash_update($context, $broken['body']);
        $rest = $ui->hashOf($address, ['Range' => 'bytes=3000000-', 'If-Range' => '"' . $sha . '"'], $context);
        self::assertSame(206, $rest['status']);
        self::assertSame('bytes 3000000-' . ($size - 1) . '/' . $size, $rest['headers']['content-range']);
        self::assertSame($size - 3_000_000, $rest['bytes']);
        self::assertSame($sha, $rest['sha256'], 'the two pieces are the file');

        // every part, whole, against the checksum the backup recorded
        $sidecar = json_decode((string) file_get_contents('/web-storage/shop/' . self::$backup . '.json'), true, 512, JSON_THROW_ON_ERROR);
        $recorded = [];
        foreach ($sidecar['parts'] as $record) {
            $recorded[$record['file']] = $record['sha256'];
        }
        foreach ($parts as $part) {
            $name = basename($part);
            $link = $ui->post($route, ['part' => $name])['headers']['location'];
            $answer = $ui->hashOf($link);
            self::assertSame(200, $answer['status']);
            self::assertSame($recorded[$name], $answer['sha256'], "$name as downloaded");
        }

        // what must not work: a stranger, another session, a name that is no part
        $stranger = new WebUi();
        self::assertSame(303, $stranger->get($address)['status'], 'not logged in');
        $other = new WebUi();
        self::assertTrue($other->login(self::PASSWORD));
        self::assertSame(404, $other->get($address)['status'], 'the token belongs to the session that made it');
        foreach (['index.php', '.htaccess', '../../data/admin.json', 'shop.json'] as $bad) {
            self::assertSame(404, $ui->post($route, ['part' => $bad])['status'], $bad);
        }
        $token = explode('/', $address)[2];
        self::assertSame(404, $ui->get("/download/$token/" . basename($parts[1]))['status'], 'the token of one part opens no other');
    }

    // ------------------------------------------------------------------------------------------------- resume

    #[Depends('testTheJourneyFromTheFirstSetupToAVerifiedBackup')]
    public function testARunThatNobodyTicksBecomesInterruptedAndIsContinuedToTheSameArchive(): void
    {
        $ui = self::ui();
        $run = self::backupOf($ui->post('/profiles/shop/backup')['headers']['location']);
        $ui->tick("/runs/$run/tick");
        $ui->tick("/runs/$run/tick");

        // the tab was closed: nobody ticks any more. A minute later (the record says so) the run is interrupted
        $file = "/web-data/runs/$run.json";
        $record = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        $record['updated'] = time() - 300;
        file_put_contents($file, json_encode($record, JSON_THROW_ON_ERROR));
        $page = $ui->get("/runs/$run");
        self::assertStringContainsString('Backup interrupted', $page['body']);
        self::assertStringNotContainsString('data-tick-url', $page['body']);
        self::assertStringContainsString('interrupted', $ui->get('/')['body']);

        $continue = $ui->post("/runs/$run/continue");
        self::assertSame(303, $continue['status']);
        self::assertStringContainsString('data-tick-url', $ui->get("/runs/$run")['body'], 'after one step it is going again');
        self::tickToTheEnd($ui, "/runs/$run/tick");

        $archives = array_values(array_diff(array_map(static fn (string $p): string => substr(basename($p), 0, -strlen('-p01.zip')), glob('/web-storage/shop/shop_*-p01.zip') ?: []), [self::$backup]));
        self::assertCount(1, $archives, 'the continued run made one more backup');
        $parts = self::parts($archives[0]);
        self::assertSame(self::entryNames(self::parts(self::$backup)), self::entryNames($parts), 'the continued backup has the content of the uninterrupted one');
        $check = $ui->post('/profiles/shop/backups/' . $archives[0] . '/verify');
        $id = substr($check['headers']['location'], strlen('/checks/'));
        self::tickToTheEnd($ui, "/checks/$id/tick");
        self::assertStringContainsString('is intact', $ui->get("/checks/$id")['body']);

        $deleted = $ui->post('/profiles/shop/backups/' . $archives[0] . '/delete');
        self::assertSame(303, $deleted['status']);
        self::assertSame([], self::parts($archives[0]), 'deleting takes the parts away');
        self::assertFileDoesNotExist('/web-storage/shop/' . $archives[0] . '.json');
    }

    // ------------------------------------------------------------------------------------------------- concurrency

    #[Depends('testTheJourneyFromTheFirstSetupToAVerifiedBackup')]
    public function testTwoRequestsOnOneRunNeverCorruptItAndASecondBackupIsRefused(): void
    {
        $ui = self::ui();
        $run = self::backupOf($ui->post('/profiles/shop/backup')['headers']['location']);

        // a second backup while this one is active
        $second = $ui->post('/profiles/shop/backup');
        self::assertSame(303, $second['status']);
        self::assertSame("/runs/$run", $second['headers']['location'], 'the person is led to the running backup');
        self::assertStringContainsString('One backup at a time', $ui->get("/runs/$run")['body']);

        // the first tick only names the backup and is over at once; the second does the work, and two of them at the same moment: one works, the other is told to wait
        $first = $ui->tick("/runs/$run/tick");
        self::assertSame('running', $first['status']);
        $answers = $ui->parallel([['POST', "/runs/$run/tick", [], ['X-CSRF-Token' => $ui->csrf]], ['POST', "/runs/$run/tick", [], ['X-CSRF-Token' => $ui->csrf]]], 0.2);
        $statuses = array_map(static fn (array $a): string => (string) (json_decode($a['body'], true)['status'] ?? $a['status']), $answers);
        sort($statuses);
        self::assertContains('busy', $statuses, 'one of them found the run busy: ' . implode(',', $statuses));
        self::assertContains('running', $statuses);

        self::tickToTheEnd($ui, "/runs/$run/tick");
        $backups = array_values(array_diff(array_map(static fn (string $p): string => substr(basename($p), 0, -strlen('-p01.zip')), glob('/web-storage/shop/shop_*-p01.zip') ?: []), [self::$backup]));
        self::assertCount(1, $backups);
        $check = $ui->post('/profiles/shop/backups/' . $backups[0] . '/verify');
        $id = substr($check['headers']['location'], strlen('/checks/'));
        self::tickToTheEnd($ui, "/checks/$id/tick");
        self::assertStringContainsString('is intact', $ui->get("/checks/$id")['body'], 'no tick was lost or done twice');
        $ui->post('/profiles/shop/backups/' . $backups[0] . '/delete');
    }

    // ------------------------------------------------------------------------------------------------- the restore kit

    #[Depends('testTheJourneyFromTheFirstSetupToAVerifiedBackup')]
    public function testTheKitFromThePageRestoresTheWebBackupOntoTheTargetStack(): void
    {
        if (!is_dir('/shops/target')) {
            self::markTestSkipped('The target stack is not mounted.');
        }
        $ui = self::ui();
        $help = $ui->get('/profiles/shop/backups/' . self::$backup . '/restore-help');
        self::assertSame(200, $help['status']);
        self::assertStringContainsString('Download threadsafe-restore.php', $help['body']);

        $tool = $ui->get('/restore-php');
        self::assertSame(200, $tool['status']);
        self::assertStringStartsWith("<?php\n// ThreadSafe restore tool", $tool['body']);
        $keyFile = $ui->post('/restore-key', ['mode' => 'file']);
        self::assertSame(200, $keyFile['status']);
        self::assertMatchesRegularExpression("/^<\\?php return '([A-Za-z0-9]{24})';\n\$/", $keyFile['body']);
        preg_match("/return '([A-Za-z0-9]{24})'/", $keyFile['body'], $m);
        $key = (string) ($m[1] ?? '');
        self::$key = $key;

        // the numbers of the source, before anything is done to it
        $source = Stack::sourceDb();
        $parts = self::parts(self::$backup);
        $manifest = Manifest::fromArchive((string) end($parts));
        $structureOnly = array_map(static fn (string $t): string => 'ps_' . $t, $manifest->filters['structureOnlyTables']);
        $counts = self::rowCounts($source, 'prestashop', $structureOnly);
        $paths = ShopSmoke::paths($source);

        // an empty target, as a host hands it over
        exec('find /shops/target -mindepth 1 -delete 2>&1', $ignored, $code);
        self::assertSame(0, $code);
        exec('chown www-data:www-data /shops/target 2>&1', $ignored, $code);
        self::assertSame(0, $code);
        $admin = new PDO('mysql:host=target-db;charset=utf8mb4', 'root', getenv('DB_ROOT_PASSWORD') ?: 'prestashop', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $admin->exec('DROP DATABASE IF EXISTS prestashop_target');
        $admin->exec('CREATE DATABASE prestashop_target CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');

        // what the person uploads: the two files from the page, and the parts of the backup (downloaded from the page, here read from the storage)
        file_put_contents('/shops/target/threadsafe-restore.php', $tool['body']);
        file_put_contents('/shops/target/threadsafe-restore-key.php', $keyFile['body']);
        foreach (self::parts(self::$backup) as $part) {
            copy($part, '/shops/target/' . basename($part));
        }
        foreach (glob('/shops/target/*') ?: [] as $file) {
            chmod($file, 0644);
        }
        file_put_contents('/shops/target/index.html', '<html>The host says: coming soon</html>');

        $wizard = new WebWizard(baseUrl: Stack::TARGET_URL, scriptPath: '/threadsafe-restore.php');
        $journey = WizardJourney::restore($wizard, $key, [
            'root' => '/var/www/html', 'db_host' => 'target-db', 'db_port' => '3306', 'db_name' => 'prestashop_target', 'db_user' => 'target', 'db_password' => 'target', 'db_prefix' => 'ps_',
            'f_to' => Stack::TARGET_HOST, 'f_path' => '', 'f_ssl' => '', 'f_mode' => 'production', 'f_mailCatcher' => '', 'allow_leftovers' => '1',
        ]);
        self::assertGreaterThan(3, $journey['ticks']);
        $gone = $wizard->request('POST', '/', ['action' => 'cleanup', 'csrf' => $wizard->csrf()]);
        self::assertStringContainsString('The tool is gone', $gone['body']);
        foreach (['threadsafe-restore.php', 'threadsafe-restore-key.php', '.threadsafe-restore'] as $name) {
            self::assertFileDoesNotExist('/shops/target/' . $name);
        }
        // the wizard leaves the backup next to the shop (the person decides); this test is not the last user of the target, so it clears away
        foreach (self::parts(self::$backup) as $part) {
            self::assertTrue(unlink('/shops/target/' . basename($part)));
        }

        // the restored shop has the rows of the source in every table that has rows in the backup
        $target = Stack::targetDb();
        $restored = self::rowCounts($target, 'prestashop_target', $structureOnly);
        $different = [];
        foreach ($counts as $table => $rows) {
            if (($restored[$table] ?? null) !== $rows) {
                $different[$table] = [$rows, $restored[$table] ?? null];
            }
        }
        self::assertSame([], $different, 'tables whose number of rows differs from the source (source, restored)');
        self::assertSame(array_keys($counts), array_keys($restored), 'the same tables');

        // and it serves: the pages answer as they do on the source
        $onSource = ShopSmoke::visit(Stack::SOURCE_URL, Stack::SOURCE_HOST, $paths);
        $onTarget = ShopSmoke::visit(Stack::TARGET_URL, Stack::TARGET_HOST, $paths);
        self::assertSame(array_map(static fn (array $p): int => $p['status'], $onSource), array_map(static fn (array $p): int => $p['status'], $onTarget));
        foreach ($onTarget as $name => $page) {
            self::assertSame(200, $page['status'], "$name answers on the restored shop");
        }
        self::assertStringNotContainsString(Stack::SOURCE_HOST, Stack::get(Stack::TARGET_URL, Stack::TARGET_HOST, '/')->body);
    }

    /**
     * @param list<string> $skip tables of which only the structure is in the backup
     *
     * @return array<string, int> table => number of rows
     */
    private static function rowCounts(PDO $db, string $schema, array $skip): array
    {
        $counts = [];
        $tables = $db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = " . $db->quote($schema) . " AND TABLE_TYPE = 'BASE TABLE' ORDER BY 1")?->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($tables as $table) {
            if (!in_array($table, $skip, true)) {
                $counts[(string) $table] = (int) $db->query('SELECT COUNT(*) FROM `' . $table . '`')?->fetchColumn();
            }
        }

        return $counts;
    }

    // ------------------------------------------------------------------------------------------------- security

    #[Depends('testTheJourneyFromTheFirstSetupToAVerifiedBackup')]
    public function testTheSchedulePageSetsSortsAndPausesAScheduleBehindApache(): void
    {
        $ui = self::ui();
        $store = new ProfileStore('/web-data/profiles');

        // the page is behind the login like every other, and is in the menu
        self::assertSame(303, (new WebUi())->get('/schedule')['status']);
        $page = $ui->get('/schedule');
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('aria-current="page"', $page['body']);
        self::assertStringContainsString('name="p_shop" value="1"', $page['body'], 'the profile of the journey is a line of the table');

        // set a schedule through the form, read it in words, sort, pause, resume
        $save = $ui->post('/schedule/edit', ['p_shop' => '1', 'action' => 'save', 'schedule_every' => 'week', 'schedule_on_mon' => '1', 'schedule_on_thu' => '1', 'schedule_time' => '02:15']);
        self::assertSame(303, $save['status'], $save['body']);
        self::assertSame('15 2 * * mon,thu', $store->load('shop')->schedule?->cron());
        $table = $ui->follow($save);
        self::assertStringContainsString('Every Monday and Thursday at 02:15', $table['body']);
        self::assertStringContainsString('aria-sort="ascending"', $table['body']);
        self::assertSame(200, $ui->get('/schedule?sort=name&dir=desc')['status']);
        self::assertSame(200, $ui->get('/schedule?sort=type&dir=asc')['status']);

        $ui->post('/schedule/action', ['do' => 'pause:shop']);
        self::assertTrue($store->load('shop')->schedule?->paused);
        self::assertStringContainsString('<span class="badge paused">Paused</span>', $ui->get('/schedule')['body']);

        // the trigger keeps what the page promises: a paused schedule is not due, a resumed one counts from now
        $ui->post('/schedule/action', ['do' => 'resume:shop']);
        self::assertFalse($store->load('shop')->schedule?->paused);
        self::assertFileExists('/web-data/schedule/shop.json', 'a resumed schedule has a state that counts from now');

        $ui->post('/schedule/edit', ['p_shop' => '1', 'action' => 'save', 'schedule_every' => '']);
        self::assertNull($store->load('shop')->schedule, 'not scheduled: the schedule is gone again, as the other tests of the journey expect');
        self::assertFileDoesNotExist('/web-data/schedule/shop.json');
    }

    #[Depends('testTheJourneyFromTheFirstSetupToAVerifiedBackup')]
    public function testAStrangerAForgedRequestAndTheTwoLayoutsOfTheInstallation(): void
    {
        $ui = self::ui();
        $stranger = new WebUi();

        // behind the login nothing is there for a stranger, not even a hint that an address exists
        foreach (['/', '/profiles', '/profiles/shop', '/system', '/restore-php', '/runs/backup-20261021-120000-aaaaaaaa', '/profiles/nothing/backups/x'] as $path) {
            $answer = $stranger->get($path);
            self::assertSame(303, $answer['status'], $path);
            self::assertSame('/login', $answer['headers']['location']);
        }
        self::assertSame(401, $stranger->request('POST', '/runs/backup-20261021-120000-aaaaaaaa/tick', [], ['X-CSRF-Token' => 'x'])['status']);

        // the session cookie and the headers of every answer
        $login = new WebUi();
        $login->get('/login');
        $answer = $login->request('POST', '/login', ['user' => 'admin', 'password' => self::PASSWORD, 'plain' => '1']);
        self::assertMatchesRegularExpression('/^threadsafe_session=[0-9a-f]{64}; Path=\/; HttpOnly; SameSite=Strict$/', $answer['cookies'][0]);
        $page = $login->get('/');
        self::assertStringContainsString("frame-ancestors 'none'", $page['headers']['content-security-policy']);
        self::assertStringContainsString("script-src 'self'", $page['headers']['content-security-policy']);
        self::assertSame('nosniff', $page['headers']['x-content-type-options']);
        self::assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)/i', $page['body']);

        // a forged request: no token, and a token from a page of another site
        self::assertSame(403, $ui->request('POST', '/profiles/shop/test')['status'], 'no token');
        self::assertSame(403, $ui->request('POST', '/profiles/shop/test', ['csrf' => $ui->csrf], ['Origin' => 'http://evil.example'])['status'], 'another site');
        self::assertSame(405, $ui->get('/logout')['status'], 'a link logs nobody out');
        self::assertSame(405, $ui->get('/profiles/shop/backup')['status']);

        // the layout with public/ as the document root: nothing else is served
        foreach (['/data/admin.json', '/src/Admin/Kernel.php', '/vendor/autoload.php', '/composer.json', '/bin/threadsafe', '/plugins/generic/plugin.json'] as $path) {
            self::assertSame(404, $ui->get($path)['status'], "$path with public/ as the document root");
        }
        // the weaker layout, the whole program directory below the web root: the .htaccess files close it
        $tree = new WebUi('web', 81);
        foreach (['/composer.json', '/src/Admin/Kernel.php', '/vendor/autoload.php', '/bin/threadsafe', '/plugins/generic/plugin.json', '/.htaccess', '/public/.htaccess', '/data/admin.json', '/docs/PHASE4.md'] as $path) {
            self::assertSame(403, $tree->get($path)['status'], "$path with the whole tree below the web root");
        }
        self::assertSame(200, $tree->get('/public/app.css')['status']);
        self::assertSame(200, $tree->get('/public/login')['status'], 'the pages are reached through public/ in that layout');
        // the data directory of the installation lies outside both
        self::assertFileExists('/web-data/admin.json');
        self::assertSame(0600, fileperms('/web-data/admin.json') & 0777);
        self::assertStringNotContainsString(self::PASSWORD, (string) file_get_contents('/web-data/admin.json'));

        // switched off: nothing but 404, and the CLI-visible switch is the setting
        $config = json_decode((string) file_get_contents('/web-data/config.json'), true, 512, JSON_THROW_ON_ERROR);
        $off = $config;
        $off['web']['enabled'] = false;
        file_put_contents('/web-data/config.json', json_encode($off, JSON_THROW_ON_ERROR));
        try {
            self::assertSame(404, (new WebUi())->get('/login')['status']);
            self::assertSame(404, $ui->get('/')['status'], 'even a valid session gets nothing');
            self::assertSame(404, (new WebUi())->request('POST', '/login', ['user' => 'admin', 'password' => self::PASSWORD, 'plain' => '1'])['status']);
        } finally {
            file_put_contents('/web-data/config.json', json_encode($config, JSON_THROW_ON_ERROR));
        }
        self::assertSame(200, $ui->get('/')['status'], 'and it is back');
    }

    // ------------------------------------------------------------------------------------------------- audit

    #[Depends('testTheKitFromThePageRestoresTheWebBackupOntoTheTargetStack')]
    public function testTheAuditLogHoldsTheStoryAndNoSecret(): void
    {
        $log = (string) file_get_contents('/web-data/audit.log');
        $events = [];
        foreach (array_filter(explode("\n", $log)) as $line) {
            $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $events[$entry['event']] = ($events[$entry['event']] ?? 0) + 1;
        }

        foreach (['setup', 'login', 'login.failed', 'profile.created', 'backup.started', 'backup.finished', 'backup.check', 'backup.checked', 'backup.deleted', 'settings.changed', 'download.start', 'download.end', 'restore.tool', 'restore.key'] as $event) {
            self::assertArrayHasKey($event, $events, "the log has \"$event\"");
        }
        self::assertSame($events['backup.started'], $events['backup.finished'], 'every backup that was started ended');
        foreach ([self::PASSWORD, self::SETUP_KEY, 'not the password', self::ui()->cookie, self::ui()->csrf] as $secret) {
            self::assertStringNotContainsString($secret, $log, 'a secret in the audit log');
        }
        // the key that the page made is nowhere in the data directory: not in the log, not in a cache, not in a session
        self::assertMatchesRegularExpression('/^[A-Za-z0-9]{24}$/', self::$key);
        exec('grep -rlF ' . escapeshellarg(self::$key) . ' /web-data 2>/dev/null', $found);
        self::assertSame([], $found, 'the restore key is not stored');
    }

    // ------------------------------------------------------------------------------------------------- the WordPress type

    #[Depends('testTheAuditLogHoldsTheStoryAndNoSecret')]
    public function testTheWordPressTypeIsInTheProfileFormAndItsBackupRunsInTheBrowsersSteps(): void
    {
        if (!is_file('/wordpress7/wp-includes/version.php')) {
            self::markTestSkipped('The WordPress site is not mounted into the web service (tests/stack/seed-wordpress.sh runs before this phase).');
        }
        $ui = self::ui();
        $form = ['type' => 'wordpress', 'path' => '/shops/wordpress7', 'id' => '', 'storage' => '/srv/storage/blog', 'compression' => '6', 'db_mode' => 'site', 'default_filters' => '1'];
        $detect = $ui->post('/profiles', ['action' => 'detect'] + $form);
        self::assertStringContainsString('WordPress', $detect['body']);
        self::assertStringContainsString('read from the site', $detect['body'], 'the database access comes from wp-config.php');
        $created = $ui->post('/profiles', ['action' => 'save', 'name' => 'Seeded blog', 'part_size' => '500M', 'exclude' => '', 'structure_only' => '', 'note' => '', 'id' => 'blog'] + $form);
        self::assertSame(303, $created['status'], strip_tags($created['body']));
        self::assertSame('/profiles/blog', $created['headers']['location']);
        self::assertStringNotContainsString('Seed-admin', $ui->get('/profiles/blog')['body']);

        $start = $ui->post('/profiles/blog/backup');
        self::assertSame(303, $start['status']);
        $run = self::backupOf($start['headers']['location']);
        $result = self::tickToTheEnd($ui, "/runs/$run/tick");
        self::assertGreaterThanOrEqual(2, $result['ticks'], 'a backup of the blog takes more than one step');
        self::assertStringContainsString('Backup finished', $ui->get("/runs/$run")['body']);

        $first = glob('/web-storage/blog/blog_*-p01.zip') ?: [];
        self::assertCount(1, $first);
        $manifest = Manifest::fromArchive($first[0]);
        self::assertSame('WordPress', $manifest->application['name']);
        self::assertSame('table', $manifest->consistency);
        self::assertSame('wp_', $manifest->extras['dbPrefix']);
        exec('unzip -Z1 ' . escapeshellarg($first[0]) . ' 2>&1', $names);
        self::assertContains('files/wp-config.php', $names);
        exec('unzip -tq ' . escapeshellarg($first[0]) . ' 2>&1', $lines, $code);
        self::assertSame(0, $code, implode("
", $lines));
        self::assertStringContainsString('<article class="card', $ui->get('/')['body']);
    }

    // ------------------------------------------------------------------------------------------------- the second factor

    /**
     * The second factor behind the real Apache (docs/PHASE11.md 4.2), with two tools that are not ours as oracles: `oathtool` computes the
     * codes, and `zbarimg` reads the QR code of the page. Switched on through the pages, a login in two steps with a code of oathtool (the
     * code of the next step, which the window of one step accepts), a replayed code, a recovery code used by two requests at the same moment
     * (the lock lets exactly one in), the other logins ending on switching off. It leaves the installation as it found it.
     */
    #[Depends('testTheAuditLogHoldsTheStoryAndNoSecret')]
    public function testTheSecondFactorLogsInWithTheCodesOfOathtoolAndTheQrCodeIsReadByZbar(): void
    {
        if (!OathtoolOracle::available() || !ZbarOracle::available()) {
            self::markTestSkipped('oathtool and zbarimg (the packages oathtool and zbar-tools of the tools image) are needed.');
        }
        $ui = self::ui();
        $passwordStep = static function (): WebUi {
            $browser = new WebUi();
            $browser->get('/login');
            $answer = $browser->request('POST', '/login', ['user' => 'admin', 'password' => self::PASSWORD, 'plain' => '1']);
            self::assertSame('/login/code', $answer['headers']['location'] ?? '', 'the password alone leads to the second step');

            return $browser;
        };

        $key = '';
        $recovery = [];
        try {
            // switching on: the password again, the key and the QR code, one code
            self::assertStringContainsString('Set up the second factor', $ui->get('/security')['body']);
            self::assertSame(403, $ui->post('/security/start', ['password' => 'not the password'])['status'], 'a wrong password makes no secret');
            self::assertSame(303, $ui->post('/security/start', ['password' => self::PASSWORD])['status']);
            $page = $ui->get('/security')['body'];
            self::assertSame(1, preg_match('~<code id="totp-key" class="secret">([A-Z2-7 ]+)</code>~', $page, $m));
            $key = str_replace(' ', '', $m[1] ?? '');
            self::assertSame(1, preg_match('~<svg class="qr".*?</svg>~s', $page, $svg));
            self::assertSame(1, preg_match('~<a href="(otpauth://[^"]+)">this link</a>~', $page, $link));
            $address = html_entity_decode($link[1] ?? '');
            self::assertSame($address, ZbarOracle::read(ZbarOracle::modulesOfSvg($svg[0] ?? ''), self::WORK), 'zbar reads the QR code of the page as the address of the link');
            self::assertStringContainsString('secret=' . $key, $address);
            self::assertFileDoesNotExist('/web-data/two-factor.json', 'nothing is kept before a code proves the key');

            $confirm = $ui->post('/security/confirm', ['code' => OathtoolOracle::code($key)]);
            self::assertSame(200, $confirm['status'], 'the code of oathtool is the code of this program');
            self::assertSame(1, preg_match('~<pre id="recovery-codes" class="secret"><code>(.*?)</code></pre>~s', $confirm['body'], $codes));
            $recovery = array_values(array_filter(explode("\n", trim($codes[1] ?? ''))));
            self::assertCount(10, $recovery);
            self::assertSame(0600, fileperms('/web-data/two-factor.json') & 0777);

            // the first login: the password alone opens nothing; the code of the next step is accepted (a window of one step)
            $first = $passwordStep();
            foreach (['/', '/profiles', '/profiles/shop', '/system', '/security'] as $path) {
                self::assertSame('/login', $first->get($path)['headers']['location'] ?? '', "$path is closed to a login that has only the password");
            }
            self::assertSame(403, $first->request('POST', '/login/code', ['code' => '000000'])['status'], 'a wrong code');
            $next = OathtoolOracle::code($key, time() + 30);
            self::assertSame('/', $first->request('POST', '/login/code', ['code' => $next])['headers']['location'] ?? '');
            self::assertSame(200, $first->get('/profiles')['status']);

            // the same code a second time, in another login: refused
            $second = $passwordStep();
            self::assertSame(403, $second->request('POST', '/login/code', ['code' => $next])['status'], 'a code is accepted once');

            // two requests at the same moment with the same recovery code: the lock lets exactly one in
            $one = $passwordStep();
            $two = $passwordStep();
            $answers = $one->parallel([
                ['POST', '/login/code', ['code' => $recovery[0], 'recovery' => '1'], ['Cookie' => 'threadsafe_session=' . $one->cookie]],
                ['POST', '/login/code', ['code' => $recovery[0], 'recovery' => '1'], ['Cookie' => 'threadsafe_session=' . $two->cookie]],
            ]);
            $statuses = [$answers[0]['status'], $answers[1]['status']];
            sort($statuses);
            self::assertSame([303, 403], $statuses, 'one recovery code, one login');

            // a recovery code in place of the code; it is struck out
            $third = $passwordStep();
            self::assertSame('/', $third->request('POST', '/login/code', ['code' => $recovery[1], 'recovery' => '1'])['headers']['location'] ?? '');
            $fourth = $passwordStep();
            self::assertSame(403, $fourth->request('POST', '/login/code', ['code' => $recovery[1], 'recovery' => '1'])['status']);

            // switching off needs the password and a code: here a recovery code; the other logins end
            self::assertSame(200, $first->get('/')['status']);
            $ui->get('/security');
            self::assertSame(403, $ui->post('/security/disable', ['off_password' => 'not the password', 'off_code' => $recovery[2]])['status']);
            self::assertSame(303, $ui->post('/security/disable', ['off_password' => self::PASSWORD, 'off_code' => $recovery[2]])['status']);
            self::assertFileDoesNotExist('/web-data/two-factor.json');
            self::assertSame('/login', $first->get('/')['headers']['location'] ?? '', 'the other login ended with the change');
            self::assertSame(200, $ui->get('/')['status'], 'this one stays');
        } finally {
            @unlink('/web-data/two-factor.json');
            @unlink('/web-data/two-factor.json.lock');
        }

        // nothing of it is left behind in clear: not the key, not the recovery codes (the file is gone), and the audit log has the events and no secret
        foreach ([$key, ...$recovery] as $secret) {
            $found = [];
            exec('grep -rlF ' . escapeshellarg($secret) . ' /web-data 2>/dev/null', $found);
            self::assertSame([], $found, 'a secret of the second factor is stored');
        }
        $log = (string) file_get_contents('/web-data/audit.log');
        foreach (['twofactor.enabled', 'twofactor.disabled', 'login.recovery', 'login.code.failed', '"mfa":"totp"', '"mfa":"recovery"', 'security.password.failed'] as $expected) {
            self::assertStringContainsString($expected, $log, $expected);
        }
    }

    // ------------------------------------------------------------------------------------------------- throttle

    #[Depends('testTheAuditLogHoldsTheStoryAndNoSecret')]
    public function testWrongPasswordsAreSlowedDownAndTheRightOneGetsInAfterTheWait(): void
    {
        $ui = new WebUi();
        $ui->get('/login');
        $statuses = [];
        for ($i = 0; $i < 5; ++$i) {
            $statuses[] = $ui->request('POST', '/login', ['user' => 'admin', 'password' => 'wrong ' . $i . ' password', 'plain' => '1'])['status'];
        }

        self::assertSame([403, 403, 403, 429, 429], $statuses);
        $waiting = $ui->request('POST', '/login', ['user' => 'admin', 'password' => self::PASSWORD, 'plain' => '1']);
        self::assertSame(429, $waiting['status'], 'even the right password waits');
        $seconds = (int) ($waiting['headers']['retry-after'] ?? 0);
        self::assertGreaterThan(0, $seconds);
        self::assertLessThanOrEqual(10, $seconds);

        sleep($seconds + 1);
        self::assertTrue($ui->login(self::PASSWORD), 'after the wait the right password works');
    }
}
