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
        self::assertStringContainsString('final class AgentSignature', $bundleOfBackup, 'the agent is in the backup tool');
        self::assertStringContainsString('final class AgentConfig', $bundleOfBackup);
        self::assertMatchesRegularExpression('/final class Agent\R/', $bundleOfBackup, 'the agent itself');
        self::assertStringNotContainsString('AgentSignature', $bundleOfRestore, 'and not in the restore tool');
        self::assertStringNotContainsString('AgentConfig', $bundleOfRestore);
        self::assertDoesNotMatchRegularExpression('/final class Agent\R/', $bundleOfRestore);
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

    public function testAnAgentRequestGetsNothingFromAToolThatWasNeverSetUpForAnAgent(): void
    {
        $site = $this->directory . '/site';
        mkdir($site);
        copy($this->build($site), $site . '/threadsafe-backup.php');

        [$output] = $this->php('$_SERVER["REQUEST_METHOD"] = "GET"; $_GET["api"] = "status"; include $argv[1];', $site . '/threadsafe-backup.php');

        self::assertStringContainsString('does nothing until you give it a key', $output, 'a stranger who adds ?api= gets the same page as before');
        self::assertSame(['threadsafe-backup.php'], array_values(array_diff(scandir($site) ?: [], ['.', '..'])), 'and nothing is written');
    }

    public function testAnAgentRequestPassesTheStubOnceTheToolHasTheSettingsOfAnAgent(): void
    {
        $site = $this->directory . '/site';
        mkdir($site . '/.threadsafe-backup/data-' . str_repeat('ab', 16), 0777, true);
        file_put_contents($site . '/.threadsafe-backup/data-' . str_repeat('ab', 16) . '/agent.json', '{}');
        copy($this->build($site), $site . '/threadsafe-backup.php');

        $this->php('$_SERVER["REQUEST_METHOD"] = "GET"; $_GET["api"] = "status"; include $argv[1];', $site . '/threadsafe-backup.php');

        self::assertCount(1, glob($site . '/.threadsafe-backup/bundle-*.php') ?: [], 'the stub did not stop at the key: the program was unpacked and the entry was called');
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

    /**
     * The wizard run from the bundle alone: a PHP that has no autoloader and no other file of the program than the plugin data (THREADSAFE_PROGRAM_DIR),
     * so that a class the bundle lacks is a fatal error. It goes from the key to the finished backup, and every step on the way is one of the pages.
     */
    public function testTheBundleHoldsEverythingTheWizardNeedsToMakeABackup(): void
    {
        $tool = $this->directory . '/built.php';
        (new BackupBuilder(dirname(__DIR__, 3)))->build($tool);
        file_put_contents($this->directory . '/bundle.php', $this->payload($tool)['bundle.php']);
        $site = $this->directory . '/site';
        mkdir($site);
        file_put_contents($site . '/index.php', '<?php echo 1;');
        file_put_contents($site . '/threadsafe-backup-key.php', "<?php return 'correct horse battery';");

        $script = <<<'PHP'
        <?php
        define('THREADSAFE_PROGRAM_DIR', $argv[2]);
        require $argv[1];
        use ThreadSafe\Web\BackupWizard;
        use ThreadSafe\Web\Request;
        $wizard = new BackupWizard($argv[3]);
        $cookie = null;
        $csrf = '';
        $step = static function (string $method, array $query, array $post) use ($wizard, &$cookie, &$csrf): string {
            $response = $wizard->handle(new Request($method, $query, $post + ($csrf !== '' ? ['csrf' => $csrf] : []), $cookie === null ? [] : [BackupWizard::COOKIE => $cookie], false, '127.0.0.1', '/tool.php'));
            foreach ($response->headers('Set-Cookie') as $line) {
                if (preg_match('/^' . BackupWizard::COOKIE . '=([0-9a-f]+);/', $line, $m) === 1) {
                    $cookie = $m[1];
                }
            }
            $location = $response->header('Location');
            if ($response->status === 303 && $location !== null) {
                parse_str((string) parse_url($location, PHP_URL_QUERY), $q);
                return $GLOBALS['step']('GET', $q, []);
            }
            if (preg_match('/name="csrf" value="([0-9a-f]+)"/', $response->body, $m) === 1) {
                $csrf = $m[1];
            }
            return $response->body;
        };
        $GLOBALS['step'] = $step;
        $step('GET', [], []);
        $page = $step('POST', [], ['action' => 'login', 'key' => 'correct horse battery']);
        echo str_contains($page, 'Directory of the site') ? "site\n" : "FAILED site\n";
        $page = $step('POST', [], ['action' => 'save-site', 'root' => $argv[3], 'type' => 'auto', 'no_db' => '1']);
        echo str_contains($page, 'Who may open this backup?') ? "options\n" : "FAILED options\n";
        $page = $step('POST', [], ['action' => 'save-options', 'mode' => 'none', 'plain' => '1', 'partMb' => '1', 'exclude' => '', 'note' => '', 'storage' => '']);
        echo str_contains($page, 'Back up now') ? "summary\n" : "FAILED summary\n";
        $step('POST', [], ['action' => 'probe', 'result' => 'closed']);
        $page = $step('POST', [], ['action' => 'start']);
        echo str_contains($page, 'Backing up') ? "run\n" : "FAILED run\n";
        for ($i = 0; $i < 100; ++$i) {
            $answer = json_decode($step('POST', [], ['action' => 'tick', 'format' => 'json']), true);
            if (($answer['status'] ?? '') !== 'running') {
                break;
            }
        }
        echo ($answer['status'] ?? '') === 'finished' ? "finished\n" : 'FAILED tick ' . json_encode($answer) . "\n";
        echo str_contains($step('GET', ['step' => 'done'], []), 'The backup is made') ? "done\n" : "FAILED done\n";
        PHP;
        file_put_contents($this->directory . '/walk.php', $script);

        [$out, $errors, $code] = $this->shell($this->directory . '/walk.php', $this->directory . '/bundle.php', dirname(__DIR__, 3), $site);

        self::assertSame("site\noptions\nsummary\nrun\nfinished\ndone\n", $out, $errors);
        self::assertSame(0, $code, $errors);
        self::assertNotSame([], glob($site . '/.threadsafe-backup/storage-*/*.zip') ?: []);
    }

    /** Builds the tool once into a scratch place and returns its path. */
    private function build(string $site): string
    {
        $file = $this->directory . '/built-' . bin2hex(random_bytes(3)) . '.php';
        (new BackupBuilder(dirname(__DIR__, 3)))->build($file);

        return $file;
    }
}
