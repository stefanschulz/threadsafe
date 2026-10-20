<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use ThreadSafe\Cli\Application;
use ThreadSafe\Cli\BufferedOutput;
use ThreadSafe\Cli\MigrateCommand;
use ThreadSafe\Core\Exception\GuardFailed;
use ThreadSafe\Core\Exception\SelfTestFailed;
use ThreadSafe\Core\Step;
use ThreadSafe\Tests\Support\EventLog;
use ThreadSafe\Tests\Support\FakeStep;
use ThreadSafe\Tests\Support\TempDirectory;

final class MigrateCommandTest extends TestCase
{
    use TempDirectory;

    private string $root;

    private EventLog $log;

    protected function setUp(): void
    {
        $this->root = (string) realpath($this->tempDirectory());
        $this->log = new EventLog();
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    /**
     * @param list<Step> $steps
     */
    private function application(array $steps): Application
    {
        return new Application([new MigrateCommand(static fn (): array => $steps)]);
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, BufferedOutput}
     */
    private function execute(Application $application, array $arguments, ?BufferedOutput $output = null): array
    {
        $output ??= new BufferedOutput();
        $code = $application->run(['threadsafe', 'migrate', ...$arguments], $output);

        return [$code, $output];
    }

    /** @return list<string> the usual arguments of a run that names everything explicitly (no database needed) */
    private function base(): array
    {
        return ['prestashop9', '--root=' . $this->root, '--from=old.test', '--to=new.test'];
    }

    public function testWithoutYesItOnlyPlansAndExplainsHowToApply(): void
    {
        [$code, $output] = $this->execute($this->application([new FakeStep('a', $this->log, 2)]), [...$this->base(), '--journal-dir=' . $this->root . '/journal']);

        self::assertSame(0, $code);
        self::assertSame(['plan:a'], $this->log->events);
        self::assertStringContainsString('Dry run – nothing was changed.', $output->stdout());
        self::assertStringContainsString('~ a – Step a (2 change(s) planned)', $output->stdout());
        self::assertStringContainsString('Run the same command with --yes', $output->stdout());
        self::assertDirectoryDoesNotExist($this->root . '/journal');
    }

    public function testDryRunFlagBehavesLikeNoFlag(): void
    {
        [$code] = $this->execute($this->application([new FakeStep('a', $this->log)]), [...$this->base(), '--dry-run']);

        self::assertSame(0, $code);
        self::assertSame(['plan:a'], $this->log->events);
    }

    public function testYesAppliesAndWritesTheJournal(): void
    {
        $journal = $this->root . '/journal';

        [$code, $output] = $this->execute($this->application([new FakeStep('a', $this->log), new FakeStep('b', $this->log)]), [...$this->base(), '--yes', '--journal-dir=' . $journal]);

        self::assertSame(0, $code);
        self::assertSame(['plan:a', 'plan:b', 'apply:a', 'apply:b'], $this->log->events);
        self::assertStringContainsString('Run finished.', $output->stdout());
        self::assertStringContainsString('+ a – Step a (1 change(s) applied)', $output->stdout());
        $files = glob($journal . '/threadsafe-journal-*.jsonl') ?: [];
        self::assertCount(1, $files);
        self::assertStringContainsString('Journal: ' . $files[0], $output->stdout());
        self::assertSame(0600, fileperms($files[0]) & 0777);
    }

    public function testNothingToApplyLeavesNoJournalBehind(): void
    {
        [$code, $output] = $this->execute($this->application([new FakeStep('a', $this->log, 0)]), [...$this->base(), '--yes', '--journal-dir=' . $this->root . '/journal']);

        self::assertSame(0, $code);
        self::assertDirectoryDoesNotExist($this->root . '/journal');
        self::assertStringNotContainsString('Journal:', $output->stdout());
    }

    public function testNothingToDoIsSaidInTheDryRun(): void
    {
        [, $output] = $this->execute($this->application([new FakeStep('a', $this->log, 0)]), $this->base());

        self::assertStringContainsString('Nothing to do: the installation is already in the requested state.', $output->stdout());
    }

    public function testFailingStepGivesExitCodeThreeAndStillReports(): void
    {
        $failing = new FakeStep('b', $this->log, onApply: static function (): never {
            throw new RuntimeException('disk full');
        });
        $report = $this->root . '/report.json';

        [$code, $output] = $this->execute($this->application([new FakeStep('a', $this->log), $failing, new FakeStep('c', $this->log)]), [...$this->base(), '--yes', '--journal-dir=' . $this->root . '/j', '--report=' . $report]);

        self::assertSame(3, $code);
        self::assertStringContainsString('x b – Step b (FAILED: disk full)', $output->stdout());
        self::assertStringContainsString('- c – Step c (skipped)', $output->stdout());
        self::assertStringContainsString('Step "b" failed: disk full', $output->stderr());
        $data = json_decode((string) file_get_contents($report), true);
        self::assertIsArray($data);
        self::assertSame('failed', $data['steps'][1]['status']);
    }

    public function testFailedSelfTestGivesExitCodeFour(): void
    {
        $selfTest = new FakeStep('self-test', $this->log, onApply: static function (): never {
            throw new SelfTestFailed('home page answers 500');
        });

        [$code, $output] = $this->execute($this->application([$selfTest]), [...$this->base(), '--yes', '--journal-dir=' . $this->root . '/j']);

        self::assertSame(4, $code);
        self::assertStringContainsString('home page answers 500', $output->stderr());
    }

    public function testRefusedRunGivesExitCodeTwo(): void
    {
        $refusing = new FakeStep('read', $this->log, onPlan: static function (): never {
            throw new GuardFailed('"wrong.test" is not a domain of this shop');
        });

        [$code, $output] = $this->execute($this->application([$refusing]), [...$this->base(), '--yes']);

        self::assertSame(2, $code);
        self::assertStringContainsString('Refused: "wrong.test" is not a domain of this shop', $output->stderr());
        self::assertSame(['plan:read'], $this->log->events);
    }

    public function testReportIsWrittenAsPrivateJson(): void
    {
        $report = $this->root . '/report.json';

        $this->execute($this->application([new FakeStep('a', $this->log)]), [...$this->base(), '--report=' . $report]);

        $data = json_decode((string) file_get_contents($report), true);
        self::assertIsArray($data);
        self::assertTrue($data['dryRun']);
        self::assertSame(1, $data['plannedChanges']);
        self::assertSame('planned', $data['steps'][0]['status']);
        self::assertSame(0600, fileperms($report) & 0777);
    }

    public function testRequestFileSuppliesSettingsAndFlagsOverrideThem(): void
    {
        $file = $this->root . '/request.json';
        file_put_contents($file, json_encode(['root' => $this->root, 'from' => 'old.test', 'to' => 'from-file.test', 'path' => '/shop/']));
        $seen = null;
        $step = new FakeStep('a', $this->log, onPlan: static function ($context) use (&$seen): void {
            $seen = $context->request;
        });

        $this->execute($this->application([$step]), ['prestashop9', '--request=' . $file, '--to=from-flag.test']);

        self::assertInstanceOf(\ThreadSafe\Migration\MigrationRequest::class, $seen);
        self::assertSame('from-flag.test', $seen->domains[0]->to);
        self::assertSame('/shop/', $seen->physicalUri);
    }

    public function testRepeatedDomainFlagsBuildSeveralMappings(): void
    {
        $seen = null;
        $step = new FakeStep('a', $this->log, onPlan: static function ($context) use (&$seen): void {
            $seen = $context->request;
        });

        $this->execute($this->application([$step]), ['prestashop9', '--root=' . $this->root, '--domain=a.test=b.test', '--domain=c.test=d.test']);

        self::assertInstanceOf(\ThreadSafe\Migration\MigrationRequest::class, $seen);
        self::assertSame(['a.test', 'c.test'], array_map(static fn ($m): string => $m->from, $seen->domains));
    }

    public function testSameOldAndNewDomainIsReportedAsANote(): void
    {
        [$code, $output] = $this->execute($this->application([new FakeStep('a', $this->log, 0)]), ['prestashop9', '--root=' . $this->root, '--from=same.test', '--to=same.test']);

        self::assertSame(0, $code);
        self::assertStringContainsString('Note: The shop already uses "same.test"', $output->stdout());
    }

    /**
     * @param list<string> $arguments
     *
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('usageErrors')]
    public function testUsageErrors(array $arguments, string $messagePart): void
    {
        [$code, $output] = $this->execute($this->application([new FakeStep('a', $this->log)]), $arguments);

        self::assertSame(1, $code);
        self::assertStringContainsString($messagePart, $output->stderr());
        self::assertSame([], $this->log->events, 'Nothing may run after a usage error.');
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function usageErrors(): iterable
    {
        yield 'no website type' => [[], 'prestashop9'];
        yield 'unknown website type' => [['wordpress', '--root=/x'], 'prestashop9'];
        yield 'extra argument' => [['prestashop9', 'more', '--root=/x'], 'Unexpected argument "more"'];
        yield 'dry run and yes' => [['prestashop9', '--root=/x', '--dry-run', '--yes'], 'contradict'];
        yield 'missing root' => [['prestashop9', '--to=a.test'], 'root'];
        yield 'dev mode' => [['prestashop9', '--root=/tmp', '--mode=dev'], 'dev mode is not available'];
        yield 'unknown option' => [['prestashop9', '--bogus'], 'Unknown option --bogus'];
        yield 'option without value' => [['prestashop9', '--root'], 'needs a value'];
        yield 'missing request file' => [['prestashop9', '--request=/nope.json'], 'cannot be read'];
    }

    public function testBrokenRequestFileIsAUsageError(): void
    {
        $file = $this->root . '/request.json';
        file_put_contents($file, '{not json');

        [$code, $output] = $this->execute($this->application([new FakeStep('a', $this->log)]), ['prestashop9', '--request=' . $file]);

        self::assertSame(1, $code);
        self::assertStringContainsString('not valid JSON', $output->stderr());
    }

    public function testHelpListsEveryOption(): void
    {
        $output = new BufferedOutput();

        (new Application([new MigrateCommand()]))->run(['threadsafe', 'help', 'migrate'], $output);

        foreach (['--root=PATH', '--to=DOMAIN', '--from=DOMAIN', '--domain=OLD=NEW', '--ssl=MODE', '--db-password-file=FILE', '--journal-dir=DIR', '--report=FILE', '--yes', '--dry-run'] as $synopsis) {
            self::assertStringContainsString($synopsis, $output->stdout());
        }
    }
}
