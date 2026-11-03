<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Integration;

use PDO;
use ThreadSafe\Build\RestoreBuilder;
use ThreadSafe\Tests\Support\CliProcess;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Tests\Support\TortureDatabase;
use ThreadSafe\Tests\Support\UploadedBackup;
use ThreadSafe\Tests\Support\WebWizard;
use ThreadSafe\Tests\Support\WizardJourney;

/**
 * The built, single-file `threadsafe-restore.php` - the thing that is uploaded to a server where nothing else is installed - doing the
 * restore of a database and its files: through the browser interface under tight limits (`max_execution_time=5`,
 * `memory_limit=64M`, as docs/PHASE3.md 6 demands), and in a shell with `php threadsafe-restore.php restore ...`.
 */
final class RestorePhpTest extends IntegrationTestCase
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

    /**
     * A directory like the one on the server: the tool, a key file, the backup - and databases to restore from and to.
     *
     * @return array{string, string, string, string, list<string>} base, web directory, source database, empty database, backup parts
     */
    private function upload(): array
    {
        $base = $this->tempDirectory();
        $web = $base . '/web';
        mkdir($web);
        $source = 'threadsafe_rp_src_' . bin2hex(random_bytes(3));
        $copy = 'threadsafe_rp_copy_' . bin2hex(random_bytes(3));
        $this->databases = [$source, $copy];
        TortureDatabase::create($this->admin, $source);
        $this->admin->exec("CREATE DATABASE `$copy` CHARACTER SET utf8mb4");
        $parts = UploadedBackup::make($base . '/work', $web, $source, self::password());
        $result = (new RestoreBuilder(dirname(__DIR__, 2)))->build($web . '/threadsafe-restore.php');
        self::assertLessThan(450 * 1024, $result['bytes']);
        file_put_contents($web . '/threadsafe-restore-key.php', "<?php return 'a long secret from the test';");

        return [$base, $web, $source, $copy, $parts];
    }

    public function testTheSingleFileRestoresThroughTheBrowserUnderTightLimits(): void
    {
        [$base, $web, $source, $copy, $parts] = $this->upload();
        $this->wizard = new WebWizard($web . '/threadsafe-restore.php', ['max_execution_time' => '5', 'memory_limit' => '64M']);
        $this->wizard->start();
        $root = $base . '/restored';

        // nothing but a password form for a stranger
        foreach (['/', '/?step=run', '/?step=done', '/?step=summary'] as $path) {
            $page = $this->wizard->request('GET', $path);
            self::assertStringContainsString('name="key"', $page['body']);
            self::assertStringNotContainsString($parts[0], $page['body']);
        }
        $wrong = $this->wizard->request('POST', '/', ['action' => 'login', 'key' => 'guessing the key']);
        self::assertSame(403, $wrong['status']);

        $journey = WizardJourney::restore($this->wizard, 'a long secret from the test', [
            'root' => $root, 'db_host' => 'db', 'db_port' => '3306', 'db_name' => $copy, 'db_user' => 'root', 'db_password' => self::password(), 'db_prefix' => '', 'f_from' => '', 'f_to' => '',
        ]);

        self::assertGreaterThanOrEqual(3, $journey['ticks']);
        foreach (['binkey', 'blobs', 'docs', 'items', 'log', 'nopk', 'types'] as $table) {
            self::assertSame($this->checksum($source, $table), $this->checksum($copy, $table), "CHECKSUM TABLE $table");
        }
        self::assertSame('<?php echo 1;', file_get_contents($root . '/index.php'));

        // the tool takes itself off the server
        self::assertFileExists($web . '/threadsafe-restore.php');
        $gone = $this->wizard->request('POST', '/', ['action' => 'cleanup', 'csrf' => $this->wizard->csrf()]);
        self::assertStringContainsString('The tool is gone', $gone['body']);
        self::assertFileDoesNotExist($web . '/threadsafe-restore.php');
        self::assertFileDoesNotExist($web . '/threadsafe-restore-key.php');
        self::assertDirectoryDoesNotExist($web . '/.threadsafe-restore');
        self::assertSame([], array_values(array_filter(scandir($web) ?: [], static fn (string $f): bool => !in_array($f, ['.', '..'], true) && !str_ends_with($f, '.zip') && !str_ends_with($f, '.json'))), 'only the backup is left');
    }

    public function testTheSingleFileDoesNothingWithoutAKey(): void
    {
        [, $web] = $this->upload();
        unlink($web . '/threadsafe-restore-key.php');
        $this->wizard = new WebWizard($web . '/threadsafe-restore.php');
        $this->wizard->start();

        $page = $this->wizard->request('GET', '/');

        self::assertSame(403, $page['status']);
        self::assertStringContainsString('does nothing until you give it a key', $page['body']);
        self::assertDirectoryDoesNotExist($web . '/.threadsafe-restore', 'nothing was written');
    }

    public function testTheSingleFileRestoresInAShellWithoutAKey(): void
    {
        [$base, $web, $source, $copy, $parts] = $this->upload();
        unlink($web . '/threadsafe-restore-key.php');
        $root = $base . '/restored-by-shell';
        file_put_contents($base . '/db-password', self::password() . "\n");
        $command = [
            $web . '/threadsafe-restore.php', 'restore', $parts[0], '--root=' . $root, '--no-self-test',
            '--db-host=db', '--db-name=' . $copy, '--db-user=root', '--db-password-file=' . $base . '/db-password',
        ];
        putenv('THREADSAFE_DATA=' . $base . '/data');
        try {
            [$code, $stdout, $stderr] = self::shell($command);
            self::assertSame(0, $code, $stderr . $stdout);
            self::assertStringContainsString('Nothing was changed.', $stdout);

            [$code, $stdout, $stderr] = self::shell([...$command, '--yes']);
            self::assertSame(0, $code, $stderr . $stdout);
        } finally {
            putenv('THREADSAFE_DATA');
        }

        self::assertStringContainsString('Restored ', $stdout);
        foreach (['binkey', 'blobs', 'docs', 'items', 'nopk', 'types'] as $table) {
            self::assertSame($this->checksum($source, $table), $this->checksum($copy, $table), "CHECKSUM TABLE $table");
        }
        self::assertSame('<?php echo 1;', file_get_contents($root . '/index.php'));
    }

    /**
     * @param list<string> $arguments the script and its arguments
     *
     * @return array{int, string, string}
     */
    private static function shell(array $arguments): array
    {
        $process = proc_open([PHP_BINARY, ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    private function checksum(string $database, string $table): string
    {
        $statement = $this->admin->query("CHECKSUM TABLE `$database`.`$table`");
        self::assertNotFalse($statement);
        $row = $statement->fetch(PDO::FETCH_NUM);

        return is_array($row) ? (string) $row[1] : '';
    }
}
