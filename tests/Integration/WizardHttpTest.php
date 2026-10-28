<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Integration;

use PDO;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Tests\Support\TortureDatabase;
use ThreadSafe\Tests\Support\UploadedBackup;
use ThreadSafe\Tests\Support\WebWizard;
use ThreadSafe\Tests\Support\WizardJourney;

/**
 * The wizard behind a real web server (PHP's built-in one, started on a free port, running the development entry
 * tools/restore-web.php): the whole way a person goes - key, backup, target, database, summary, ticks from the page's own script,
 * deletion of the tool - with real requests, real cookies and real headers, and a real database server. The same journey through
 * the built `threadsafe-restore.php` is RestorePhpTest.
 */
final class WizardHttpTest extends IntegrationTestCase
{
    use TempDirectory;

    private ?WebWizard $wizard = null;

    private PDO $admin;

    /** @var list<string> */
    private array $databases = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = new PDO('mysql:host=db;charset=utf8mb4', 'root', self::password(), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    protected function tearDown(): void
    {
        $this->wizard?->stop();
        foreach ($this->databases as $database) {
            $this->admin->exec("DROP DATABASE IF EXISTS `$database`");
        }
        $this->cleanUpTempDirectories();
    }

    private static function password(): string
    {
        $password = getenv('DB_ROOT_PASSWORD');

        return $password === false || $password === '' ? 'prestashop' : $password;
    }

    public function testADatabaseAndItsFilesComeBackThroughTheBrowserInterface(): void
    {
        $base = $this->tempDirectory();
        $web = $base . '/web';
        mkdir($web);
        $source = 'threadsafe_wizard_src_' . bin2hex(random_bytes(3));
        $copy = 'threadsafe_wizard_copy_' . bin2hex(random_bytes(3));
        $this->databases = [$source, $copy];
        TortureDatabase::create($this->admin, $source);
        $this->admin->exec("CREATE DATABASE `$copy` CHARACTER SET utf8mb4");
        $parts = UploadedBackup::make($base . '/work', $web, $source, self::password());
        file_put_contents($web . '/threadsafe-restore-key.php', "<?php return 'a long secret from the test';");
        file_put_contents($web . '/threadsafe-restore.php', '<?php // the tool');

        $this->wizard = new WebWizard(dirname(__DIR__, 2) . '/tools/restore-web.php', [], ['THREADSAFE_RESTORE_BASE' => $web]);
        $this->wizard->start();
        $root = $base . '/restored';

        $wrong = $this->wizard->request('POST', '/', ['action' => 'login', 'key' => 'nope nope nope']);
        self::assertSame(403, $wrong['status']);
        self::assertFalse($this->wizard->hasCookie());

        $journey = WizardJourney::restore($this->wizard, 'a long secret from the test', [
            'root' => $root, 'db_host' => 'db', 'db_port' => '3306', 'db_name' => $copy, 'db_user' => 'root', 'db_password' => self::password(), 'db_prefix' => '', 'f_from' => '', 'f_to' => '',
        ]);

        self::assertGreaterThan(0, $journey['ticks']);
        foreach (['binkey', 'blobs', 'docs', 'items', 'nopk', 'types'] as $table) {
            self::assertSame($this->checksum($source, $table), $this->checksum($copy, $table), "CHECKSUM TABLE $table");
        }
        self::assertSame('<?php echo 1;', file_get_contents($root . '/index.php'));
        self::assertStringNotContainsString(self::password(), $journey['done']['body']);

        $gone = $this->wizard->request('POST', '/', ['action' => 'cleanup', 'csrf' => $this->wizard->csrf()]);
        self::assertStringContainsString('The tool is gone', $gone['body']);
        self::assertFileDoesNotExist($web . '/threadsafe-restore.php');
        self::assertFileDoesNotExist($web . '/threadsafe-restore-key.php');
        self::assertDirectoryDoesNotExist($web . '/.threadsafe-restore');
        foreach ($parts as $part) {
            self::assertFileExists($part, 'the backup stays unless the person asks');
        }
    }

    private function checksum(string $database, string $table): string
    {
        $statement = $this->admin->query("CHECKSUM TABLE `$database`.`$table`");
        self::assertNotFalse($statement);
        $row = $statement->fetch(PDO::FETCH_NUM);

        return is_array($row) ? (string) $row[1] : '';
    }
}
