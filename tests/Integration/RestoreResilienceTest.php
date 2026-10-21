<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Integration;

use PDO;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Tests\Support\TortureDatabase;
use ThreadSafe\Tests\Support\UploadedBackup;

/**
 * A restore as a real process of `bin/threadsafe`, killed with signal 9 in the middle of the extraction and of the import and
 * continued with `--resume`: the result must be the same as that of an undisturbed restore - every file, every table - and
 * nothing of the work may be left behind (docs/PHASE3.md 4.2, item 6). Interrupted requests of the wizard are the case of
 * WizardTest::testAnUnfinishedRestoreGoesOnWhenThePageIsOpenedAgain.
 */
final class RestoreResilienceTest extends IntegrationTestCase
{
    use TempDirectory;

    private PDO $admin;

    /** @var list<string> */
    private array $databases = [];

    /** @var list<resource> */
    private array $children = [];

    private string $base = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = new PDO('mysql:host=db;charset=utf8mb4', 'root', self::password(), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->base = $this->tempDirectory();
    }

    protected function tearDown(): void
    {
        foreach ($this->children as $child) {
            if (is_resource($child)) {
                @proc_terminate($child, 9);
                @proc_close($child);
            }
        }
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

    private function emptyDatabase(): string
    {
        $name = 'threadsafe_resil_' . bin2hex(random_bytes(3));
        $this->databases[] = $name;
        $this->admin->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4");

        return $name;
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{resource, array<int, resource>}
     */
    private function spawn(array $arguments): array
    {
        $env = ['THREADSAFE_DATA' => $this->base . '/data', 'PATH' => (string) getenv('PATH')];
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/bin/threadsafe', ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        self::assertIsResource($process);
        $this->children[] = $process;
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return [$process, $pipes];
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string, string}
     */
    private function threadsafe(array $arguments): array
    {
        [$process, $pipes] = $this->spawn($arguments);
        stream_set_blocking($pipes[1], true);
        stream_set_blocking($pipes[2], true);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    /**
     * @param callable(array<string, mixed>): bool $condition
     *
     * @return string the id of the run that reached the wanted state
     */
    private function waitForRun(callable $condition, int $seconds = 90): string
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            foreach (glob($this->base . '/data/runs/restore-*.json') ?: [] as $file) {
                $content = @file_get_contents($file);
                $run = $content === false ? null : json_decode($content, true);
                if (is_array($run) && is_array($run['state'] ?? null) && $condition($run['state'])) {
                    return (string) $run['id'];
                }
            }
            usleep(2000);
        }
        self::fail('The restore did not reach the wanted state in time (it finished before, or hangs).');
    }

    /**
     * @return list<string> the command line of a restore into $root and $database
     */
    private function command(string $archive, string $root, string $database): array
    {
        file_put_contents($this->base . '/db-password', self::password() . "\n");

        return ['restore', $archive, '--root=' . $root, '--no-self-test', '--db-host=db', '--db-name=' . $database, '--db-user=root', '--db-password-file=' . $this->base . '/db-password', '--work-dir=' . $root . '/.threadsafe-restore'];
    }

    /** @return array<string, string> */
    private function tree(string $directory): array
    {
        $result = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $path => $info) {
            $relative = substr((string) $path, strlen($directory) + 1);
            if (str_starts_with($relative, '.threadsafe-restore')) {
                continue;
            }
            $result[$relative] = $info->isDir() ? 'dir' : (string) md5_file((string) $path);
        }
        ksort($result);

        return $result;
    }

    private function checksum(string $database, string $table): string
    {
        $statement = $this->admin->query("CHECKSUM TABLE `$database`.`$table`");
        self::assertNotFalse($statement);
        $row = $statement->fetch(PDO::FETCH_NUM);

        return is_array($row) ? (string) $row[1] : '';
    }

    public function testKillingTheProcessInTheExtractionAndInTheImportLeavesAResumableRestore(): void
    {
        $source = $this->emptyDatabase();
        $this->admin->exec("DROP DATABASE `$source`");
        TortureDatabase::create($this->admin, $source);
        $web = $this->base . '/web';
        mkdir($web);
        $parts = UploadedBackup::make($this->base . '/work', $web, $source, self::password(), static function (string $site): void {
            for ($i = 0; $i < 1500; ++$i) {
                $directory = $site . '/modules/bulk/d' . ($i % 30);
                if (!is_dir($directory)) {
                    mkdir($directory, 0777, true);
                }
                file_put_contents($directory . '/file' . $i . '.php', "<?php // file $i\n" . str_repeat('x', 300));
            }
            file_put_contents($site . '/big.bin', random_bytes(3_000_000));
        });

        // the reference: an undisturbed restore
        $referenceDatabase = $this->emptyDatabase();
        [$code, $stdout, $stderr] = $this->threadsafe([...$this->command($parts[0], $this->base . '/reference', $referenceDatabase), '--yes']);
        self::assertSame(0, $code, $stdout . $stderr);
        $expected = $this->tree($this->base . '/reference');
        self::assertGreaterThan(1500, count($expected));

        $conditions = [
            'extract' => static fn (array $s): bool => ($s['phase'] ?? '') === 'extract' && (int) ($s['extract']['index'] ?? 0) >= 200,
            'import' => static fn (array $s): bool => ($s['phase'] ?? '') === 'import' && (int) ($s['import']['statements'] ?? 0) >= 40,
        ];
        foreach ($conditions as $phase => $condition) {
            $database = $this->emptyDatabase();
            $root = $this->base . '/killed-in-' . $phase;

            [$process] = $this->spawn([...$this->command($parts[0], $root, $database), '--yes', '--tick-seconds=0']);
            $runId = $this->waitForRun($condition);
            proc_terminate($process, 9);   // signal 9: no cleanup of any kind
            proc_close($process);

            [$code, $stdout, $stderr] = $this->threadsafe(['restore', "--resume=$runId", '--tick-seconds=0.5']);
            self::assertSame(0, $code, "resume after a kill in the $phase phase: $stdout $stderr");

            self::assertSame($expected, $this->tree($root), "killed in the $phase phase and continued: the same files");
            foreach (['binkey', 'blobs', 'docs', 'items', 'log', 'nopk', 'types'] as $table) {
                self::assertSame($this->checksum($referenceDatabase, $table), $this->checksum($database, $table), "killed in the $phase phase: CHECKSUM TABLE $table");
            }
            self::assertSame([], glob($this->base . '/data/runs/restore-*.json') ?: [], 'the state of the finished restore is gone');
            self::assertDirectoryDoesNotExist($root . '/.threadsafe-restore/db', 'the unpacked SQL files are gone');
        }
    }

    public function testARestoreThatFailedContinuesAfterTheCauseIsRemoved(): void
    {
        $source = $this->emptyDatabase();
        $this->admin->exec("DROP DATABASE `$source`");
        TortureDatabase::create($this->admin, $source);
        $web = $this->base . '/web';
        mkdir($web);
        $parts = UploadedBackup::make($this->base . '/work', $web, $source, self::password());
        $database = $this->emptyDatabase();
        $root = $this->base . '/restored';

        // the cause: someone takes the privilege to create tables away (here: the database is dropped under the run)
        [$process] = $this->spawn([...$this->command($parts[0], $root, $database), '--yes', '--tick-seconds=0']);
        $runId = $this->waitForRun(static fn (array $s): bool => ($s['phase'] ?? '') === 'import' && (int) ($s['import']['statements'] ?? 0) >= 20);
        proc_terminate($process, 9);
        proc_close($process);
        $this->admin->exec("DROP DATABASE `$database`");

        [$code, , $stderr] = $this->threadsafe(['restore', "--resume=$runId"]);
        self::assertNotSame(0, $code, 'the resume cannot work without the database');
        self::assertStringContainsString("--resume=$runId", $stderr, 'it says how to go on');

        $this->admin->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4");
        [$code, $stdout, $stderr] = $this->threadsafe(['restore', "--resume=$runId"]);
        self::assertSame(0, $code, $stdout . $stderr);
        foreach (['binkey', 'blobs', 'items', 'types'] as $table) {
            self::assertSame($this->checksum($source, $table), $this->checksum($database, $table), "CHECKSUM TABLE $table");
        }
    }
}
