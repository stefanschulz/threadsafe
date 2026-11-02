<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Build;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Build\BackupBuilder;
use ThreadSafe\Build\RestoreBuilder;
use ThreadSafe\Build\ToolStub;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * The backup tool built from the real sources (docs/PHASE12.md): it has the program of the restore tool in it, with the wizard and the command of its own,
 * behind the stub of its own; the built file is run as a person with a shell runs it.
 */
final class BackupBuilderTest extends TestCase
{
    use TempDirectory;

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = $this->tempDirectory();
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    /**
     * @return array<string, string> name => content of the payload
     */
    private function payload(string $file): array
    {
        $data = (string) file_get_contents($file);
        $marker = '__halt_compiler();';
        $inflated = gzinflate(substr($data, strpos($data, $marker) + strlen($marker)));
        self::assertIsString($inflated);
        $entries = [];
        $at = 0;
        while ($at < strlen($inflated)) {
            $end = (int) strpos($inflated, "\n", $at);
            [$name, $size] = explode("\t", substr($inflated, $at, $end - $at));
            $entries[$name] = substr($inflated, $end + 1, (int) $size);
            $at = $end + 1 + (int) $size;
        }

        return $entries;
    }

    /**
     * @return array{string, string, int}
     */
    private function php(string $code, string ...$arguments): array
    {
        $command = [PHP_BINARY, '-d', 'display_errors=0', '-r', $code];
        foreach ($arguments as $argument) {
            $command[] = $argument;
        }
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);

        return [$output, $errors, proc_close($process)];
    }

    public function testTheFileLeadsWithTheStubOfTheBackupTool(): void
    {
        $file = $this->directory . '/threadsafe-backup.php';
        $result = (new BackupBuilder(dirname(__DIR__, 3), '0.1.0'))->build($file);

        $content = (string) file_get_contents($file);
        self::assertTrue(str_starts_with($content, ToolStub::backup('0.1.0')));
        self::assertSame($result['bytes'], strlen($content));
        self::assertStringContainsString('threadsafe-backup-key.php', substr($content, 0, 4000));
        self::assertStringNotContainsString('threadsafe-restore-key.php', substr($content, 0, (int) strpos($content, '__halt_compiler')));
    }

    public function testItHasTheProgramOfTheRestoreToolButTheWizardOfItsOwn(): void
    {
        $backup = $this->directory . '/threadsafe-backup.php';
        $restore = $this->directory . '/threadsafe-restore.php';
        (new BackupBuilder(dirname(__DIR__, 3)))->build($backup);
        (new RestoreBuilder(dirname(__DIR__, 3)))->build($restore);

        $inBackup = $this->payload($backup);
        $inRestore = $this->payload($restore);

        $bundleOfBackup = $inBackup['bundle.php'];
        $bundleOfRestore = $inRestore['bundle.php'];
        unset($inBackup['bundle.php'], $inRestore['bundle.php']);
        self::assertSame($inRestore, $inBackup, 'the plugin files are the same');

        self::assertStringContainsString('final class BackupWizard', $bundleOfBackup);
        self::assertStringContainsString('final class BackupRun', $bundleOfBackup, 'the engine of the backup is in it');
        self::assertStringContainsString('final class Download', $bundleOfBackup, 'it hands the archive out like the app');
        self::assertStringNotContainsString("'threadsafe_restore'", $bundleOfBackup, 'the wizard of the restore is not');
        self::assertStringNotContainsString('namespace ThreadSafe\Admin', $bundleOfBackup, 'the web interface of the app is not');

        self::assertStringContainsString("'threadsafe_restore'", $bundleOfRestore);
        self::assertStringNotContainsString('BackupWizard', $bundleOfRestore, 'the restore tool has none of the backup tool');
        self::assertStringNotContainsString('BackupWorkspace', $bundleOfRestore);
        self::assertStringNotContainsString('BackupTarget', $bundleOfRestore);
        self::assertStringNotContainsString('ToolBackupCommand', $bundleOfRestore);
        self::assertStringContainsString('ToolBackupCommand', $bundleOfBackup);
        self::assertStringNotContainsString('final class RestoreCommand', $bundleOfBackup, 'the restore command is the restore tool\'s');
    }

    public function testWithoutAKeyFileTheToolOnlyExplainsAndWritesNothingOnTheWeb(): void
    {
        $site = $this->directory . '/site';
        mkdir($site);
        copy($this->build($site), $site . '/threadsafe-backup.php');

        [$output] = $this->php('$_SERVER["REQUEST_METHOD"] = "GET"; include $argv[1];', $site . '/threadsafe-backup.php');

        self::assertStringContainsString('does nothing until you give it a key', $output);
        self::assertStringContainsString('threadsafe-backup-key.php', $output);
        self::assertSame(['threadsafe-backup.php'], array_values(array_diff(scandir($site) ?: [], ['.', '..'])), 'nothing was written');
    }

    /**
     * Runs the tool as a person with a shell does.
     *
     * @return array{string, string, int} standard output, standard error, exit code
     */
    private function shell(string $tool, string ...$arguments): array
    {
        $command = [PHP_BINARY, '-d', 'display_errors=0', $tool];
        foreach ($arguments as $argument) {
            $command[] = $argument;
        }
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);

        return [$output, $errors, proc_close($process)];
    }

    public function testInAShellItIsTheBackupCommandAndNeedsNoKeyFile(): void
    {
        $site = $this->directory . '/site';
        mkdir($site);
        copy($this->build($site), $site . '/threadsafe-backup.php');

        [$overview, , $code] = $this->shell($site . '/threadsafe-backup.php');
        self::assertSame(0, $code);
        self::assertStringContainsString('backup', $overview);
        self::assertStringNotContainsString('restore', $overview, 'it is not the restore tool');

        [, $errors, $code] = $this->shell($site . '/threadsafe-backup.php', 'backup');
        self::assertSame(1, $code);
        self::assertStringContainsString('Say who may open the backup', $errors);
    }

    public function testInAShellItMakesABackupOfTheSiteItLiesIn(): void
    {
        $site = $this->directory . '/site';
        mkdir($site);
        file_put_contents($site . '/index.php', '<?php echo 1;');
        file_put_contents($site . '/data.txt', str_repeat("data\n", 3000));
        copy($this->build($site), $site . '/threadsafe-backup.php');

        [$out, $errors, $code] = $this->shell($site . '/threadsafe-backup.php', 'backup', '--no-db', '--no-encryption');

        self::assertSame(0, $code, $errors . $out);
        $storages = glob($site . '/.threadsafe-backup/storage-*', GLOB_ONLYDIR) ?: [];
        self::assertCount(1, $storages);
        $parts = glob($storages[0] . '/*.zip') ?: [];
        self::assertNotSame([], $parts);
        $names = [];
        foreach ($parts as $part) {
            foreach (\ThreadSafe\Archive\ZipReader::open($part)->entries() as $entry) {
                $names[] = $entry->name;
            }
        }
        self::assertContains('files/index.php', $names);
        self::assertContains('files/data.txt', $names);
        self::assertSame([], array_values(array_filter($names, static fn (string $n): bool => str_contains($n, 'threadsafe-backup'))), 'the tool and its directory are not in it');
        self::assertSame(0700, fileperms($site . '/.threadsafe-backup') & 0777);
        self::assertFileExists($site . '/.threadsafe-backup/.htaccess', 'closed to the web');
        self::assertCount(1, glob($site . '/.threadsafe-backup/bundle-*.php') ?: [], 'unpacked once');
    }

    /** Builds the tool once into a scratch place and returns its path. */
    private function build(string $site): string
    {
        $file = $this->directory . '/built-' . bin2hex(random_bytes(3)) . '.php';
        (new BackupBuilder(dirname(__DIR__, 3)))->build($file);

        return $file;
    }
}
