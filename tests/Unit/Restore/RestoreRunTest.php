<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Restore;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use ThreadSafe\Core\Change;
use ThreadSafe\Core\Context;
use ThreadSafe\Core\ExitCode;
use ThreadSafe\Core\Plan;
use ThreadSafe\Core\Step;
use ThreadSafe\Core\StepResult;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Engine\Executor;
use ThreadSafe\Engine\Run;
use ThreadSafe\Engine\RunStatus;
use ThreadSafe\Engine\RunStore;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Restore\RestoreException;
use ThreadSafe\Restore\RestoreRun;
use ThreadSafe\Storage\BackupCatalog;
use ThreadSafe\Tests\Support\BackupFixture;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * The restore run on backups of a small site without a database (the database side is tested against real servers in
 * tests/Integration/RestoreRunTest.php): phases, refusals, the empty target, the fixes after a failure.
 */
final class RestoreRunTest extends TestCase
{
    use TempDirectory;

    private BackupFixture $fixture;

    private string $base;

    protected function setUp(): void
    {
        $this->base = $this->tempDirectory();
        $this->fixture = new BackupFixture($this->base . '/fixture');
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function archive(array $parameters = []): string
    {
        return (new BackupCatalog($this->fixture->storage()))->archiveParts($this->fixture->backup($parameters))[0];
    }

    /**
     * @param (callable(): list<Step>)|null $steps
     */
    private function executor(?callable $steps = null, bool $tickPerStep = false): Executor
    {
        $task = new RestoreRun(PluginRegistry::load(), $steps === null ? null : static fn () => $steps());

        return new Executor(new RunStore($this->base . '/runs'), $task, static fn (): Budget => $tickPerStep ? new Budget(0.0) : Budget::unlimited());
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function parameters(string $archive, string $root, array $extra = []): array
    {
        return [...$extra, 'archive' => $archive, 'root' => $root, 'work' => $this->base . '/work', 'settings' => ['selfTest' => false, ...($extra['settings'] ?? [])]];
    }

    /** @return array<string, string> */
    private function tree(string $directory): array
    {
        $result = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $path => $info) {
            $result[substr((string) $path, strlen($directory) + 1)] = $info->isDir() ? 'dir' : (string) md5_file((string) $path);
        }
        ksort($result);

        return $result;
    }

    public function testTheSiteComesBackPhaseByPhase(): void
    {
        $archive = $this->archive();
        $root = $this->base . '/restored';
        $executor = $this->executor(null, true);
        $progress = [];

        $run = $executor->start($this->parameters($archive, $root));
        $final = $executor->execute($run->id, null, static function (Run $r) use (&$progress): void {
            $progress[] = (string) $r->progress;
        });

        self::assertSame(RunStatus::Finished, $final->status);
        self::assertSame($this->tree($this->fixture->site), $this->tree($root), 'the restored tree equals the site');
        $phases = array_values(array_unique(array_map(static fn (string $p): string => explode(' ', $p)[0] . ' ' . explode(' ', $p)[1], $progress)));
        self::assertSame(['[1/5 preflight]', '[2/5 extract]', '[4/5 adapt]', '[5/5 finalize]'], $phases, 'no import for a site without a database');
        self::assertGreaterThan(5, $final->ticks);
        $result = $final->state['result'];
        self::assertSame(4, $result['files'], 'index.php and the three assets');
        self::assertSame($root, $result['root']);
        self::assertSame('done', $final->state['phase']);
        self::assertDirectoryDoesNotExist($this->base . '/work/db');
    }

    public function testTheWorkDirectoryIsClosedToTheWebBecauseTheJournalInItHoldsPasswords(): void
    {
        $archive = $this->archive();
        $root = $this->base . '/restored';
        $executor = $this->executor();

        $run = $executor->start(['archive' => $archive, 'root' => $root, 'settings' => ['selfTest' => false]]);
        $executor->execute($run->id);

        $work = $root . '/.threadsafe-restore';
        self::assertSame(0700, fileperms($work) & 0777);
        self::assertStringContainsString('Require all denied', (string) file_get_contents($work . '/.htaccess'));
        self::assertFileExists($work . '/index.html');
        self::assertDirectoryExists($work . '/journal');
    }

    public function testTheWorkDirectoryInTheRootIsProtectedAndNotInTheWay(): void
    {
        $archive = $this->archive();
        $root = $this->base . '/restored';
        $executor = $this->executor();

        $run = $executor->start(['archive' => $archive, 'root' => $root, 'settings' => ['selfTest' => false]]);
        $executor->execute($run->id);

        self::assertDirectoryExists($root . '/.threadsafe-restore/journal');
        self::assertSame('<?php echo 1;', file_get_contents($root . '/index.php'));
    }

    public function testANonEmptyTargetIsRefusedBeforeAnythingIsWritten(): void
    {
        $archive = $this->archive();
        $root = $this->base . '/restored';
        mkdir($root);
        file_put_contents($root . '/wp-config.php', 'someone else\'s site');
        $executor = $this->executor();
        $run = $executor->start($this->parameters($archive, $root));

        try {
            $executor->execute($run->id);
            self::fail('The restore should have been refused.');
        } catch (RestoreException $e) {
            self::assertSame(ExitCode::GuardFailed, $e->exitCode);
            self::assertStringContainsString('is not empty: wp-config.php', $e->getMessage());
            self::assertStringContainsString('never overwrites', $e->getMessage());
        }
        self::assertSame(['wp-config.php'], array_values(array_diff(scandir($root) ?: [], ['.', '..'])), 'nothing was written');
        self::assertDirectoryDoesNotExist($this->base . '/work');
    }

    public function testPlaceholdersOnlyPassWithAllowLeftovers(): void
    {
        $archive = $this->archive();
        $executor = $this->executor();

        $root = $this->base . '/with-placeholder';
        mkdir($root);
        file_put_contents($root . '/index.html', '<html>coming soon</html>');
        $run = $executor->start($this->parameters($archive, $root));
        try {
            $executor->execute($run->id);
            self::fail('A placeholder is in the way without --allow-leftovers.');
        } catch (RestoreException $e) {
            self::assertStringContainsString('--allow-leftovers', $e->getMessage());
        }

        $run = $executor->start($this->parameters($archive, $root, ['allowLeftovers' => true]));
        $executor->execute($run->id);
        self::assertSame('<?php echo 1;', file_get_contents($root . '/index.php'));
    }

    public function testTheProtectionFilesOfAnUploadedBackupDirectoryAreNotInTheWay(): void
    {
        $archive = $this->archive();
        $root = $this->base . '/restored';
        mkdir($root);
        foreach (\ThreadSafe\Storage\LocalStorage::protectionFiles() as $name => $content) {
            file_put_contents($root . '/' . $name, $content);
        }
        $executor = $this->executor();

        $run = $executor->start($this->parameters($archive, $root));
        $executor->execute($run->id);

        self::assertSame('<?php echo 1;', file_get_contents($root . '/index.php'), 'the file of the backup replaces the protection file');

        // but a file of that name with other content is somebody else's
        $other = $this->base . '/other';
        mkdir($other);
        file_put_contents($other . '/index.php', '<?php // my own page');
        $run = $executor->start($this->parameters($archive, $other));
        $this->expectException(RestoreException::class);
        $this->expectExceptionMessage('is not empty: index.php');
        $executor->execute($run->id);
    }

    public function testTheArchiveItselfInTheTargetIsNotInTheWay(): void
    {
        $archive = $this->archive();
        $root = $this->base . '/restored';
        mkdir($root);
        copy($archive, $root . '/' . basename($archive));
        $executor = $this->executor();

        $run = $executor->start($this->parameters($root . '/' . basename($archive), $root));
        $executor->execute($run->id);

        self::assertSame('<?php echo 1;', file_get_contents($root . '/index.php'));
        self::assertFileExists($root . '/' . basename($archive));
    }

    public function testNotEnoughSpaceIsFoundBeforeWriting(): void
    {
        $archive = $this->archive();
        $task = new RestoreRun(PluginRegistry::load());
        $state = $task->initialState($this->parameters($archive, $this->base . '/restored'));

        $inspection = $task->inspect($state);
        self::assertTrue($inspection->ok());

        $source = $inspection->source;
        $checks = (new \ThreadSafe\Restore\Preflight(static fn (): int => 1000))->run($source, $this->base . '/restored', null, false, [], null);
        $errors = array_filter($checks, static fn ($c): bool => $c->isError());
        self::assertCount(1, $errors);
        self::assertStringContainsString('Not enough free space', array_values($errors)[0]->message);
    }

    public function testRetryAfterAFailureContinuesInTheAdaptation(): void
    {
        $archive = $this->archive();
        $root = $this->base . '/restored';
        $calls = 0;
        $flaky = new class (static function () use (&$calls): void {
            if (++$calls === 1) {
                throw new RuntimeException('the disk is full');
            }
        }) implements Step {
            public function __construct(private readonly \Closure $work)
            {
            }

            public function id(): string
            {
                return 'flaky';
            }

            public function title(): string
            {
                return 'A step that fails once';
            }

            public function plan(Context $context): Plan
            {
                return (new Plan())->add(Change::update('x', 'change'));
            }

            public function apply(Context $context, Plan $plan): StepResult
            {
                ($this->work)();

                return new StepResult(1);
            }
        };
        $executor = $this->executor(static fn (): array => [$flaky]);
        $run = $executor->start($this->parameters($archive, $root));

        try {
            $executor->execute($run->id);
            self::fail('The step fails the first time.');
        } catch (RestoreException $e) {
            self::assertSame(ExitCode::StepFailed, $e->exitCode);
            self::assertStringContainsString('the disk is full', $e->getMessage());
            self::assertStringContainsString('files and the database are restored', $e->getMessage());
        }
        $failed = (new RunStore($this->base . '/runs'))->load($run->id);
        self::assertSame(RunStatus::Failed, $failed->status);
        self::assertSame('adapt', $failed->state['phase'], 'the state is that of the last good tick');

        $executor->retry($run->id);
        $final = $executor->execute($run->id);

        self::assertSame(RunStatus::Finished, $final->status);
        self::assertSame(2, $calls);
        self::assertSame($final->state['result']['report']['steps'][0]['status'], 'applied');
    }

    /**
     * Field test of a WordPress restore (2026-11-09): the self-test failed because the server could not look up the new name, the person fixed
     * that and had no way to say "ask again". "Try the failed step again" is that way: the state is that of the last good tick, so the
     * self-test runs again, and the restore ends as done.
     */
    public function testRetryAsksAFailedSelfTestAgain(): void
    {
        $archive = $this->archive();
        $root = $this->base . '/restored';
        $calls = 0;
        $selfTest = new class (static function () use (&$calls): void {
            if (++$calls === 1) {
                throw new \ThreadSafe\Core\Exception\SelfTestFailed('The migrated site does not work (1 check(s) failed): front page (/): no answer');
            }
        }) implements Step, \ThreadSafe\Core\AlwaysRuns {
            public function __construct(private readonly \Closure $work)
            {
            }

            public function id(): string
            {
                return 'self-test';
            }

            public function title(): string
            {
                return 'Check that the migrated site answers';
            }

            public function plan(Context $context): Plan
            {
                return new Plan();
            }

            public function apply(Context $context, Plan $plan): StepResult
            {
                ($this->work)();

                return new StepResult(0);
            }
        };
        $executor = $this->executor(static fn (): array => [$selfTest]);
        $run = $executor->start($this->parameters($archive, $root));

        try {
            $executor->execute($run->id);
            self::fail('The self-test fails the first time.');
        } catch (RestoreException $e) {
            self::assertSame(ExitCode::SelfTestFailed, $e->exitCode);
            self::assertStringContainsString('files and the database are restored', $e->getMessage());
        }

        $executor->retry($run->id);
        $final = $executor->execute($run->id);

        self::assertSame(RunStatus::Finished, $final->status);
        self::assertSame(2, $calls, 'the self-test was asked again, not answered from the saved failure');
    }

    public function testRetryNeedsAFailedRun(): void
    {
        $executor = $this->executor();
        $run = $executor->start($this->parameters($this->archive(), $this->base . '/restored'));

        $this->expectException(\ThreadSafe\Engine\EngineException::class);
        $executor->retry($run->id);
    }

    public function testASecretInTheSettingsIsBlankedWhenTheRunIsDone(): void
    {
        $archive = $this->archive();
        $executor = $this->executor(static fn (): array => []);
        $run = $executor->start($this->parameters($archive, $this->base . '/restored', ['settings' => ['database' => ['host' => 'db.example', 'name' => 'shop', 'user' => 'shop', 'password' => 's3cret-value']]]));
        self::assertStringContainsString('s3cret-value', (string) file_get_contents($this->base . '/runs/' . $run->id . '.json'), 'the state needs it while the run goes on');

        $final = $executor->execute($run->id);

        self::assertStringNotContainsString('s3cret-value', (string) json_encode($final->state));
        self::assertStringNotContainsString('s3cret-value', (string) file_get_contents($this->base . '/runs/' . $run->id . '.json'));
        self::assertContains('The backup holds no database; the database settings are not used.', $final->state['warnings']);
    }

    public function testAPasswordFileIsReadOnceAtTheStart(): void
    {
        $file = $this->base . '/pw';
        file_put_contents($file, "from-the-file\n");

        $state = (new RestoreRun(PluginRegistry::load()))->initialState($this->parameters('/x.zip', '/restored', ['settings' => ['database' => ['host' => 'h', 'name' => 'n', 'user' => 'u', 'passwordFile' => $file]]]));

        self::assertSame('from-the-file', $state['settings']['database']['password']);
        self::assertArrayNotHasKey('passwordFile', $state['settings']['database']);
    }

    public function testAnUnknownWebsiteTypeIsRefused(): void
    {
        $executor = $this->executor();
        $run = $executor->start($this->parameters($this->archive(), $this->base . '/restored', ['type' => 'nonsense']));

        $this->expectException(RestoreException::class);
        $this->expectExceptionMessage('nonsense');
        $executor->execute($run->id);
    }

    public function testBadParametersAreUsageErrors(): void
    {
        $task = new RestoreRun(PluginRegistry::load());
        foreach ([[], ['archive' => 'a.zip'], ['archive' => 'a.zip', 'root' => 'relative/dir'], ['archive' => 'a.zip', 'root' => '/x', 'settings' => ['root' => '/y']]] as $parameters) {
            try {
                $task->initialState($parameters);
                self::fail('Should have been refused: ' . json_encode($parameters));
            } catch (RestoreException $e) {
                self::assertSame(ExitCode::Usage, $e->exitCode);
            }
        }
    }
}
