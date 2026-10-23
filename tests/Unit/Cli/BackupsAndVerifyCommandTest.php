<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Cli\Application;
use ThreadSafe\Cli\BackupsCommand;
use ThreadSafe\Cli\BufferedOutput;
use ThreadSafe\Cli\VerifyCommand;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Engine\Executor;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Tests\Support\BackupFixture;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * The `backups` and `verify` commands on real backups.
 */
final class BackupsAndVerifyCommandTest extends TestCase
{
    use TempDirectory;

    private BackupFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new BackupFixture($this->tempDirectory());
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
    private function cli(array $arguments): array
    {
        $application = new Application([new BackupsCommand($this->fixture->data, PluginRegistry::load()), new VerifyCommand($this->fixture->data)]);
        $output = new BufferedOutput();
        $code = $application->run(['threadsafe', ...$arguments], $output);

        return [$code, $output->stdout(), $output->stderr()];
    }

    public function testListingBeforeAndAfterBackups(): void
    {
        [$code, $stdout] = $this->cli(['backups']);
        self::assertSame(0, $code);
        self::assertStringContainsString('No backups yet', $stdout);

        $first = $this->fixture->backup();
        sleep(1);
        $second = $this->fixture->backup(['partSize' => 1_048_576]);

        [$code, $stdout] = $this->cli(['backups', 'blog']);

        self::assertSame(0, $code);
        $lines = array_values(array_filter(explode("\n", $stdout)));
        self::assertCount(2, $lines);
        self::assertTrue(str_starts_with($lines[0], $second->base()), 'Newest first.');
        self::assertMatchesRegularExpression('/\d{4}-\d\d-\d\d \d\d:\d\d UTC\s+[\d.]+ MB\s+2 parts\s+ok\b/', $lines[0]);
        self::assertMatchesRegularExpression('/1 part\s+ok\b/', $lines[1]);
        self::assertTrue(str_starts_with($lines[1], $first->base()));
    }

    public function testListingAsJson(): void
    {
        $name = $this->fixture->backup();

        [$code, $stdout] = $this->cli(['backups', '--json']);

        self::assertSame(0, $code);
        $data = json_decode($stdout, true);
        self::assertSame($name->base(), $data['backups'][0]['backup']);
        self::assertSame('blog', $data['backups'][0]['profile']);
        self::assertSame('ok', $data['backups'][0]['status']);
        self::assertSame('Other website', $data['backups'][0]['application']);
        self::assertSame([$name->base() . '.zip'], $data['backups'][0]['parts']);
        self::assertSame([], $data['interruptedRuns']);
    }

    public function testAMissingSidecarIsRebuiltAndSaid(): void
    {
        $name = $this->fixture->backup();
        unlink($this->fixture->storage()->sidecarPath($name));

        [, $stdout] = $this->cli(['backups', 'blog']);

        self::assertMatchesRegularExpression('/unverified/', $stdout);
        self::assertStringContainsString('has been made again from the archive', $stdout);
        self::assertFileExists($this->fixture->storage()->sidecarPath($name));
        [$code, $stdout] = $this->cli(['verify', 'blog']);
        self::assertSame(0, $code);
        self::assertStringContainsString('Status: ok', $stdout);
    }

    public function testVerifyIntactAndDamaged(): void
    {
        $good = $this->fixture->backup();
        [$code, $stdout, $stderr] = $this->cli(['verify', 'blog']);
        self::assertSame(0, $code, $stderr);
        self::assertStringContainsString("Backup {$good->base()} is intact (full check", $stdout);

        [$code, $stdout] = $this->cli(['verify', 'blog', '--quick']);
        self::assertSame(0, $code);
        self::assertStringContainsString('(quick check', $stdout);

        sleep(1);
        $bad = $this->fixture->backup();
        $this->fixture->damage($this->fixture->storage()->partPath($bad, 1, false));

        [$code, $stdout, $stderr] = $this->cli(['verify', 'blog']);   // the newest is the damaged one
        self::assertSame(3, $code);
        self::assertStringContainsString("Backup {$bad->base()} is NOT intact", $stderr);
        self::assertStringContainsString('SHA-256', $stderr);
        self::assertSame('', $stdout);

        [$code] = $this->cli(['verify', 'blog', $good->base(), '--quick']);
        self::assertSame(0, $code, 'The older backup is still fine.');
        [, $stdout] = $this->cli(['backups', 'blog']);
        self::assertMatchesRegularExpression('/' . $bad->base() . '.*faulty/', $stdout);
    }

    public function testVerifyAsJson(): void
    {
        $this->fixture->backup();
        [$code, $stdout] = $this->cli(['verify', 'blog', '--json']);

        self::assertSame(0, $code);
        $report = json_decode($stdout, true);
        self::assertTrue($report['ok']);
        self::assertSame('full', $report['level']);
        self::assertSame('ok', $report['status']);
    }

    public function testVerifyMistakes(): void
    {
        $this->fixture->backup();
        foreach ([
            [['verify'], 'Say which profile'],
            [['verify', 'nope'], 'There is no profile'],
            [['verify', 'blog', 'blog_20200101-000000_bbbbbbbb'], 'There is no backup'],
            [['verify', 'blog', 'a', 'b'], 'Unexpected argument'],
        ] as [$arguments, $message]) {
            [$code, , $stderr] = $this->cli($arguments);
            self::assertSame(1, $code, implode(' ', $arguments));
            self::assertStringContainsString($message, $stderr);
        }

        $empty = new BackupFixture($this->tempDirectory(), 'empty');
        $application = new Application([new VerifyCommand($empty->data)]);
        $output = new BufferedOutput();
        self::assertSame(1, $application->run(['threadsafe', 'verify', 'empty'], $output));
        self::assertStringContainsString('no backup of profile "empty" yet', $output->stderr());
    }

    public function testDeleteNeedsYesAndRemovesEverythingOfThatBackup(): void
    {
        $keep = $this->fixture->backup();
        sleep(1);
        $drop = $this->fixture->backup(['partSize' => 1_048_576]);

        [$code, , $stderr] = $this->cli(['backups', 'blog', '--delete=' . $drop->base()]);
        self::assertSame(1, $code);
        self::assertStringContainsString('needs --yes', $stderr);
        self::assertNotSame([], $this->fixture->storage()->files($drop));

        [$code, $stdout] = $this->cli(['backups', 'blog', '--delete=' . $drop->base(), '--yes']);
        self::assertSame(0, $code);
        self::assertStringContainsString('deleted', $stdout);
        self::assertSame([], $this->fixture->storage()->files($drop));
        self::assertNotSame([], $this->fixture->storage()->files($keep));
        self::assertTrue($this->fixture->storage()->isProtected());

        foreach ([[['backups', '--delete=' . $keep->base(), '--yes'], 'needs the profile'], [['backups', 'blog', '--delete=' . $drop->base(), '--yes'], 'There is no backup'], [['backups', 'nope'], 'There is no profile']] as [$arguments, $message]) {
            [$code, , $stderr] = $this->cli($arguments);
            self::assertSame(1, $code);
            self::assertStringContainsString($message, $stderr);
        }
    }

    public function testAnInterruptedRunIsListedAndCanBeGivenUp(): void
    {
        $executor = new Executor($this->fixture->runs, $this->fixture->task(), static fn (): Budget => new Budget(0.0));
        $run = $executor->start(['profile' => 'blog']);
        $executor->execute($run->id, 6);   // the process dies here
        self::assertNotSame([], glob($this->fixture->storageDirectory . '/.work-*') ?: []);

        [, $stdout] = $this->cli(['backups']);
        self::assertStringContainsString("Interrupted run {$run->id} (profile blog)", $stdout);
        self::assertStringContainsString("backup --resume={$run->id}", $stdout);
        $json = json_decode($this->cli(['backups', '--json'])[1], true);
        self::assertSame($run->id, $json['interruptedRuns'][0]['run']);

        [$code, , $stderr] = $this->cli(['backups', '--discard-run=' . $run->id]);
        self::assertSame(1, $code);
        self::assertStringContainsString('needs --yes', $stderr);

        [$code, $stdout] = $this->cli(['backups', '--discard-run=' . $run->id, '--yes']);
        self::assertSame(0, $code);
        self::assertStringContainsString('given up', $stdout);
        self::assertSame([], glob($this->fixture->storageDirectory . '/.work-*') ?: []);
        self::assertSame([], $this->fixture->runs->all());
        self::assertStringNotContainsString('Interrupted run', $this->cli(['backups'])[1]);

        [$code, , $stderr] = $this->cli(['backups', '--discard-run=nope-20260101-000000-00000000', '--yes']);
        self::assertSame(1, $code);
        self::assertStringContainsString('There is no run', $stderr);
    }
}
