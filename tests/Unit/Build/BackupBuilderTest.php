<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Build;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Build\BackupBuilder;
use ThreadSafe\Build\RestoreBuilder;
use ThreadSafe\Build\ToolStub;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * The backup tool built from the real sources (docs/PHASE12.md work package 1): it has the program of the restore tool in it behind the stub of its own, and
 * until the wizard exists it loads and says so.
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

    public function testWithAKeyFileItUnpacksItselfAndSaysThatTheWizardIsNotThereYet(): void
    {
        $site = $this->directory . '/site';
        mkdir($site);
        copy($this->build($site), $site . '/threadsafe-backup.php');
        file_put_contents($site . '/threadsafe-backup-key.php', "<?php return 'a secret key';");

        // a CLI process counts as a shell even with a request method set, so the placeholder answers on the standard error
        [, $errors] = $this->php('$_SERVER["REQUEST_METHOD"] = "GET"; include $argv[1];', $site . '/threadsafe-backup.php');

        self::assertStringContainsString('not finished', $errors);
        self::assertDirectoryExists($site . '/.threadsafe-backup');
        self::assertSame(0700, fileperms($site . '/.threadsafe-backup') & 0777);
        self::assertFileExists($site . '/.threadsafe-backup/.htaccess', 'closed to the web');
        self::assertCount(1, glob($site . '/.threadsafe-backup/bundle-*.php') ?: []);
    }

    public function testInAShellItSaysSoOnTheStandardErrorAndFails(): void
    {
        $site = $this->directory . '/site';
        mkdir($site);
        copy($this->build($site), $site . '/threadsafe-backup.php');

        [, $errors, $code] = $this->php('include $argv[1];', $site . '/threadsafe-backup.php');

        self::assertSame(1, $code);
        self::assertStringContainsString('not finished', $errors);
    }

    /** Builds the tool once into a scratch place and returns its path. */
    private function build(string $site): string
    {
        $file = $this->directory . '/built-' . bin2hex(random_bytes(3)) . '.php';
        (new BackupBuilder(dirname(__DIR__, 3)))->build($file);

        return $file;
    }
}
