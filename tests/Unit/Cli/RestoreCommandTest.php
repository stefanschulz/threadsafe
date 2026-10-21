<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Cli\Application;
use ThreadSafe\Cli\BufferedOutput;
use ThreadSafe\Cli\RestoreCommand;
use ThreadSafe\Core\Change;
use ThreadSafe\Core\Context;
use ThreadSafe\Core\Plan;
use ThreadSafe\Core\Step;
use ThreadSafe\Core\StepResult;
use ThreadSafe\Engine\RunStore;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Storage\BackupCatalog;
use ThreadSafe\Tests\Support\BackupFixture;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * The `restore` command on a backup of a small site without a database.
 */
final class RestoreCommandTest extends TestCase
{
    use TempDirectory;

    private string $base;

    private BackupFixture $fixture;

    private string $archive;

    protected function setUp(): void
    {
        $this->base = $this->tempDirectory();
        $this->fixture = new BackupFixture($this->base . '/fixture');
        $this->archive = (new BackupCatalog($this->fixture->storage()))->archiveParts($this->fixture->backup())[0];
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string, string}
     */
    private function cli(array $arguments, ?\Closure $steps = null): array
    {
        $application = new Application([new RestoreCommand($this->base . '/data', $steps, PluginRegistry::load())]);
        $output = new BufferedOutput();
        $code = $application->run(['threadsafe', 'restore', ...$arguments], $output);

        return [$code, $output->stdout(), $output->stderr()];
    }

    public function testWithoutYesItOnlyInspects(): void
    {
        $root = $this->base . '/restored';

        [$code, $stdout, $stderr] = $this->cli([$this->archive, '--root=' . $root, '--no-self-test']);

        self::assertSame(0, $code, $stderr);
        self::assertStringContainsString('Backup blog_', $stdout);
        self::assertStringContainsString('4 files', $stdout);
        self::assertStringContainsString('Restore into: ' . $root, $stdout);
        self::assertStringContainsString('will be created', $stdout);
        self::assertStringContainsString('Nothing was changed. Run the same command with --yes to restore.', $stdout);
        self::assertDirectoryDoesNotExist($root);
        self::assertSame([], (new RunStore($this->base . '/data/runs'))->all(), 'a dry run leaves no run behind');
    }

    public function testWithYesTheSiteComesBackAndTheRunIsRemoved(): void
    {
        $root = $this->base . '/restored';

        [$code, $stdout, $stderr] = $this->cli([$this->archive, '--root=' . $root, '--no-self-test', '--yes', '--report=' . $this->base . '/result.json']);

        self::assertSame(0, $code, $stderr . $stdout);
        self::assertSame('<?php echo 1;', file_get_contents($root . '/index.php'));
        self::assertStringContainsString('[2/5 extract]', $stdout);
        self::assertStringContainsString('Restored blog_', $stdout);
        self::assertStringContainsString('Run finished.', $stdout, 'the report of the adaptation');
        self::assertSame([], (new RunStore($this->base . '/data/runs'))->all(), 'the state is deleted, it may hold secrets');
        $result = json_decode((string) file_get_contents($this->base . '/result.json'), true);
        self::assertSame(4, $result['files']);
        self::assertSame(0600, fileperms($this->base . '/result.json') & 0777);
    }

    public function testANonEmptyTargetIsRefusedWithExitCode2(): void
    {
        $root = $this->base . '/restored';
        mkdir($root);
        file_put_contents($root . '/old-site.php', 'x');

        [$code, , $stderr] = $this->cli([$this->archive, '--root=' . $root, '--no-self-test', '--yes']);

        self::assertSame(2, $code);
        self::assertStringContainsString('is not empty: old-site.php', $stderr);
        self::assertSame(['old-site.php'], array_values(array_diff(scandir($root) ?: [], ['.', '..'])));
    }

    public function testAFailedRunIsKeptAndResumed(): void
    {
        $root = $this->base . '/restored';
        $calls = 0;
        $steps = static function () use (&$calls): array {
            return [new class (static function () use (&$calls): void {
                if (++$calls === 1) {
                    throw new \RuntimeException('the connection was lost');
                }
            }) implements Step {
                public function __construct(private readonly \Closure $work)
                {
                }

                public function id(): string
                {
                    return 'once';
                }

                public function title(): string
                {
                    return 'Fails once';
                }

                public function plan(Context $context): Plan
                {
                    return (new Plan())->add(Change::update('x', 'y'));
                }

                public function apply(Context $context, Plan $plan): StepResult
                {
                    ($this->work)();

                    return new StepResult(1);
                }
            }];
        };

        [$code, , $stderr] = $this->cli([$this->archive, '--root=' . $root, '--no-self-test', '--yes'], $steps);

        self::assertSame(3, $code);
        self::assertStringContainsString('the connection was lost', $stderr);
        $runs = (new RunStore($this->base . '/data/runs'))->all();
        self::assertCount(1, $runs);
        self::assertStringContainsString('threadsafe restore --resume=' . $runs[0]->id, $stderr);

        [$code, $stdout, $stderr] = $this->cli(['--resume=' . $runs[0]->id], $steps);

        self::assertSame(0, $code, $stderr . $stdout);
        self::assertStringContainsString('Continuing run ' . $runs[0]->id . ' after the failure', $stdout);
        self::assertSame(2, $calls);
        self::assertSame([], (new RunStore($this->base . '/data/runs'))->all());
    }

    public function testUsageErrors(): void
    {
        [$code, , $stderr] = $this->cli([]);
        self::assertSame(1, $code);
        self::assertStringContainsString('Say which backup to restore', $stderr);

        [$code, , $stderr] = $this->cli([$this->archive]);
        self::assertSame(1, $code);
        self::assertStringContainsString('--root', $stderr);

        [$code, , $stderr] = $this->cli(['--resume=restore-20260101-000000-00000000']);
        self::assertSame(1, $code);
        self::assertStringContainsString('no run', $stderr);

        [$code, , $stderr] = $this->cli([$this->archive, '--root=' . $this->base . '/r', '--type=nonsense']);
        self::assertSame(1, $code);
        self::assertStringContainsString('nonsense', $stderr);
    }

    public function testSomethingThatIsNoBackupIsRefused(): void
    {
        file_put_contents($this->base . '/photo.zip', 'x');

        [$code, , $stderr] = $this->cli([$this->base . '/photo.zip', '--root=' . $this->base . '/r']);

        self::assertSame(2, $code);
        self::assertStringContainsString('not a part of a ThreadSafe backup', $stderr);
    }
}
