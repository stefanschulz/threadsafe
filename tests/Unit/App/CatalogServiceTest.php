<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\App;

use PHPUnit\Framework\TestCase;
use ThreadSafe\App\CatalogService;
use ThreadSafe\Backup\BackupState;
use ThreadSafe\Core\Exception\GuardFailed;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Engine\EngineException;
use ThreadSafe\Engine\Lock;
use ThreadSafe\Engine\Run;
use ThreadSafe\Engine\RunStatus;
use ThreadSafe\Profile\ProfileException;
use ThreadSafe\Storage\BackupEntry;
use ThreadSafe\Storage\StorageException;
use ThreadSafe\Tests\Support\BackupFixture;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * Listing, deleting and checking backups for the web interface.
 */
final class CatalogServiceTest extends TestCase
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

    private function service(?int $now = null): CatalogService
    {
        return new CatalogService($this->fixture->profiles, $this->fixture->runs, static fn (): Budget => new Budget(0.0), $now === null ? null : static fn (): int => $now);
    }

    public function testTheBackupsAreListedNewestFirstAndFoundByName(): void
    {
        $first = $this->fixture->backup();
        sleep(1);
        $second = $this->fixture->backup();

        $entries = $this->service()->backups('blog');

        self::assertSame([$second->base(), $first->base()], array_map(static fn (BackupEntry $e): string => $e->name->base(), $entries));
        self::assertSame($first->base(), $this->service()->find('blog', $first->base())->name->base());
    }

    public function testAnUnknownProfileOrBackupIsAnException(): void
    {
        try {
            $this->service()->backups('nope');
            self::fail('no such profile');
        } catch (ProfileException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(StorageException::class);
        $this->service()->find('blog', 'blog_20261021-120000_abcd1234');
    }

    public function testDeletingTakesEverythingOfThatBackupAndNothingOfTheOthers(): void
    {
        $keep = $this->fixture->backup(['partSize' => 1048576]);
        sleep(1);
        $drop = $this->fixture->backup(['partSize' => 1048576]);

        $this->service()->delete('blog', $drop->base());

        $names = array_map('basename', glob($this->fixture->storageDirectory . '/*') ?: []);
        self::assertSame([], array_values(array_filter($names, static fn (string $n): bool => str_contains($n, $drop->base()))), 'nothing of the deleted backup is left');
        self::assertNotSame([], array_values(array_filter($names, static fn (string $n): bool => str_contains($n, $keep->base()))), 'the other backup is untouched');
        self::assertSame([], glob($this->fixture->storageDirectory . '/.work-*') ?: []);
        self::assertCount(1, $this->service()->backups('blog'));
    }

    public function testABackupThatIsBeingWrittenOrCheckedCannotBeDeleted(): void
    {
        $name = $this->fixture->backup();
        $state = new BackupState('blog', false, false, null, null);
        $state->web = true;
        $state->backup = $name->base();
        $this->fixture->runs->save(new Run('backup-20261021-120000-aaaaaaaa', 'backup', RunStatus::Running, 1, 1000, 1000, null, null, $state->toArray()));

        try {
            $this->service(now: 1010)->delete('blog', $name->base());
            self::fail('a backup in the making must not be deleted');
        } catch (GuardFailed $e) {
            self::assertStringContainsString('still being made', $e->getMessage());
        }
        self::assertCount(1, $this->service()->backups('blog'));

        // when that run has been given up for a while the files may go
        $this->service(now: 5000)->delete('blog', $name->base());
        self::assertSame([], $this->service()->backups('blog'));
    }

    public function testADeleteAllRefusesAsAWholeAndDeletesAsAWhole(): void
    {
        $a = $this->fixture->backup();
        sleep(1);
        $b = $this->fixture->backup();
        $state = new BackupState('blog', false, false, null, null);
        $state->web = true;
        $state->backup = $b->base();
        $this->fixture->runs->save(new Run('backup-20261021-120001-bbbbbbbb', 'backup', RunStatus::Running, 1, 1000, 1000, null, null, $state->toArray()));

        try {
            $this->service(now: 1010)->deleteAll('blog');
            self::fail('one of them is in use');
        } catch (GuardFailed) {
            self::assertCount(2, $this->service()->backups('blog'), 'nothing was deleted');
        }

        self::assertSame(2, $this->service(now: 5000)->deleteAll('blog'));
        self::assertSame([], $this->service()->backups('blog'));
        self::assertNotSame('', $a->base());
    }

    public function testACheckIsDoneInTicksAndOnlyOneRunsAtATime(): void
    {
        $name = $this->fixture->backup(['partSize' => 1048576]);
        $service = $this->service();
        $run = $service->startVerify('blog', $name->base());
        self::assertSame('active', $service->state($run));

        try {
            $service->startVerify('blog', $name->base());
            self::fail('one check at a time');
        } catch (GuardFailed $e) {
            self::assertStringContainsString('One check at a time', $e->getMessage());
        }

        $ticks = 0;
        while ($run->status === RunStatus::Running) {
            $run = $service->tick($run->id);
            self::assertLessThan(100, ++$ticks);
        }
        self::assertGreaterThan(1, $ticks);
        self::assertSame('finished', $service->state($run));
        self::assertTrue($service->result($run)['ok'] ?? false);
        self::assertSame(100, $service->progress($run)['percent']);

        // the next check may start
        self::assertSame('active', $service->state($service->startVerify('blog', $name->base(), full: false)));
    }

    public function testABackupThatCannotBeCheckedIsRefused(): void
    {
        mkdir($this->fixture->storageDirectory . '/.work-blog_20261021-120000_abcd1234', 0777, true);

        $this->expectException(StorageException::class);
        $this->service()->startVerify('blog', 'blog_20261021-120000_abcd1234');
    }

    public function testACheckThatAnotherProcessIsTickingIsBusyAndCannotBeDiscarded(): void
    {
        $name = $this->fixture->backup();
        $service = $this->service();
        $run = $service->startVerify('blog', $name->base());
        $lock = Lock::tryAcquire($this->fixture->runs->lockPath($run->id));

        try {
            $service->tick($run->id);
            self::fail('busy');
        } catch (EngineException $e) {
            self::assertTrue($e->isBusy());
        }
        try {
            $service->discard($run->id);
            self::fail('busy');
        } catch (GuardFailed) {
            $this->addToAssertionCount(1);
        }
        $lock?->release();

        $service->discard($run->id);
        self::assertSame([], $service->verifications());
    }

    public function testARunOfAnotherTaskIsNotACheck(): void
    {
        $this->fixture->runs->save(new Run('backup-20261021-120002-cccccccc', 'backup', RunStatus::Running, 0, 1000, 1000, null, null, []));

        $this->expectException(EngineException::class);
        $this->service()->tick('backup-20261021-120002-cccccccc');
    }

    public function testOldRecordsOfEndedChecksAreRemoved(): void
    {
        $this->fixture->runs->save(new Run('verify-20261021-120003-dddddddd', 'verify', RunStatus::Finished, 3, 1000, 1000, null, null, []));
        $this->fixture->runs->save(new Run('verify-20261021-120004-eeeeeeee', 'verify', RunStatus::Finished, 3, 90_000, 90_000, null, null, []));

        $this->service(now: 100_000)->prune();

        self::assertSame(['verify-20261021-120004-eeeeeeee'], array_map(static fn (Run $r): string => $r->id, $this->service()->verifications()));
    }
}
