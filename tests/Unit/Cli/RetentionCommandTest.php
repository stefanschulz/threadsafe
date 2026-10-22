<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Cli;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Cli\Application;
use ThreadSafe\Cli\BackupCommand;
use ThreadSafe\Cli\BackupsCommand;
use ThreadSafe\Cli\BufferedOutput;
use ThreadSafe\Cli\RetentionCommand;
use ThreadSafe\Tests\Support\BackupFixture;
use ThreadSafe\Tests\Support\FakeBackups;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * `threadsafe retention` and `backups --pin`, as the application runs them (the real clock: the backups are made relative to now).
 */
final class RetentionCommandTest extends TestCase
{
    use TempDirectory;

    private BackupFixture $fixture;

    private FakeBackups $fake;

    protected function setUp(): void
    {
        $this->fixture = new BackupFixture($this->tempDirectory());
        $this->fake = new FakeBackups($this->fixture);
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
        $output = new BufferedOutput();
        $code = (new Application([new RetentionCommand($this->fixture->data), new BackupsCommand($this->fixture->data), new BackupCommand($this->fixture->data)]))->run(['threadsafe', ...$arguments], $output);

        return [$code, $output->stdout(), $output->stderr()];
    }

    private static function ago(int $days): string
    {
        return (new DateTimeImmutable("-$days days"))->format('Y-m-d H:i:s');
    }

    /** @param array<string, int> $rules */
    private function rules(array $rules): void
    {
        $this->fixture->profiles->save($this->fixture->profiles->load('blog')->with(['retention' => $rules]));
    }

    public function testADryRunShowsWhatWouldGoAndWhyAndDeletesNothing(): void
    {
        $names = [];
        foreach ([1, 2, 3, 4] as $days) {
            $names[$days] = $this->fake->make(self::ago($days));
        }
        $this->rules(['keep' => 2]);

        [$code, $out] = $this->cli(['retention', 'blog', '--dry-run']);

        self::assertSame(0, $code);
        self::assertStringContainsString('4 backups count', $out);
        self::assertStringContainsString('would delete ' . $names[4]->base(), $out);
        self::assertStringContainsString('would delete ' . $names[3]->base(), $out);
        self::assertStringContainsString('keep 2: there are 4 backups that count', $out);
        self::assertStringContainsString('would free 19 MB', $out);
        self::assertStringContainsString('Nothing was deleted', $out);
        self::assertTrue($this->fake->exists($names[4]));
    }

    public function testWithoutDryRunItDeletesAndSaysSo(): void
    {
        $names = [];
        foreach ([1, 2, 3] as $days) {
            $names[$days] = $this->fake->make(self::ago($days));
        }
        $this->rules(['keep' => 1]);

        [$code, $out] = $this->cli(['retention', 'blog']);

        self::assertSame(0, $code);
        self::assertStringContainsString('deleted      ' . $names[3]->base(), $out);
        self::assertFalse($this->fake->exists($names[3]));
        self::assertFalse($this->fake->exists($names[2]));
        self::assertTrue($this->fake->exists($names[1]));
        self::assertStringContainsString('"event":"retention.deleted"', (string) file_get_contents($this->fixture->data . '/audit.log'));
    }

    public function testJson(): void
    {
        $this->fake->make(self::ago(1));
        $old = $this->fake->make(self::ago(100));
        $this->rules(['maxAgeDays' => 30]);

        [, $json] = $this->cli(['retention', 'blog', '--dry-run', '--json']);

        $data = json_decode($json, true);
        self::assertIsArray($data);
        self::assertTrue($data['dryRun']);
        self::assertSame($old->base(), $data['delete'][0]['backup']);
        self::assertSame('maxAgeDays', $data['delete'][0]['rule']);
        self::assertSame(10_000_000, $data['freed']);
    }

    public function testMistakes(): void
    {
        self::assertSame(1, $this->cli(['retention'])[0]);
        self::assertSame(1, $this->cli(['retention', 'nobody'])[0]);
        [$code, , $err] = $this->cli(['retention', 'blog']);
        self::assertSame(1, $code);
        self::assertStringContainsString('has no retention rules', $err);
    }

    public function testAStorageThatCannotBeReadIsRefused(): void
    {
        $this->fixture->profiles->save($this->fixture->profiles->load('blog')->with(['retention' => ['keep' => 1], 'storage' => $this->fixture->storageDirectory . '-gone']));

        [$code, , $err] = $this->cli(['retention', 'blog']);

        self::assertSame(2, $code);
        self::assertStringContainsString('cannot be read', $err);
    }

    public function testAManualBackupAppliesTheRulesAfterwardsAndRecordsThatItWasManual(): void
    {
        $oldest = $this->fake->make(self::ago(3));
        $older = $this->fake->make(self::ago(2));
        $this->rules(['keep' => 2]);

        [$code, $out, $err] = $this->cli(['backup', 'blog']);

        self::assertSame(0, $code, $err);
        self::assertStringContainsString('Retention of "blog": 1 backup deleted', $out);
        self::assertFalse($this->fake->exists($oldest));
        self::assertTrue($this->fake->exists($older));
        $listing = json_decode($this->cli(['backups', 'blog', '--json'])[1], true);
        self::assertSame('manual', $listing['backups'][0]['source']);
    }

    public function testAManualBackupSaysWhenTheRulesWaitForTheGoAhead(): void
    {
        foreach ([5, 4, 3, 2] as $days) {
            $this->fake->make(self::ago($days));
        }
        $this->rules(['keep' => 1]);

        [$code, , $err] = $this->cli(['backup', 'blog']);

        self::assertSame(0, $code, 'the backup went well');
        self::assertStringContainsString('waits for your go-ahead', $err);
        self::assertCount(5, glob($this->fixture->storageDirectory . '/blog_*.json') ?: []);
    }

    public function testBackupsCanBePinnedAndUnpinnedAndTheListShowsIt(): void
    {
        $name = $this->fake->make(self::ago(5));

        [$code, $out] = $this->cli(['backups', 'blog', '--pin=' . $name->base()]);
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('is pinned', $out);
        self::assertTrue($this->fixture->storage()->readSidecar($name)->pinned);
        self::assertStringContainsString(' pinned', $this->cli(['backups', 'blog'])[1]);
        $data = json_decode($this->cli(['backups', 'blog', '--json'])[1], true);
        self::assertTrue($data['backups'][0]['pinned']);

        [$code] = $this->cli(['backups', 'blog', '--unpin=' . $name->base()]);
        self::assertSame(0, $code);
        self::assertFalse($this->fixture->storage()->readSidecar($name)->pinned);
        self::assertStringNotContainsString(' pinned', $this->cli(['backups', 'blog'])[1]);

        self::assertSame(1, $this->cli(['backups', 'blog', '--pin=nonsense'])[0]);
        self::assertSame(1, $this->cli(['backups', '--pin=' . $name->base()])[0], 'the profile is needed');
        self::assertSame(1, $this->cli(['backups', 'blog', '--pin=' . $name->base(), '--unpin=' . $name->base()])[0]);
    }
}
