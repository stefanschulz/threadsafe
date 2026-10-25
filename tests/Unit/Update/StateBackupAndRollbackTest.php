<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Update;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Core\Home;
use ThreadSafe\Engine\Lock;
use ThreadSafe\Engine\RunStatus;
use ThreadSafe\Engine\RunStore;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Update\Busy;
use ThreadSafe\Update\Releases;
use ThreadSafe\Update\Rollback;
use ThreadSafe\Update\StateBackup;
use ThreadSafe\Update\UpdateException;

/**
 * The copy of the state files, the roll-back by hand, the releases of a home, and the question whether something is running.
 */
final class StateBackupAndRollbackTest extends TestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    private function data(): string
    {
        $data = $this->tempDirectory();
        mkdir($data . '/profiles', 0700);
        mkdir($data . '/schedule', 0700);
        mkdir($data . '/runs', 0700);
        mkdir($data . '/backups-would-be-elsewhere');
        file_put_contents($data . '/profiles/shop.json', '{"id": "shop"}');
        file_put_contents($data . '/schedule/shop.json', '{"next": 1}');
        file_put_contents($data . '/config.json', '{"a": 1}');
        file_put_contents($data . '/admin.json', '{"hash": "x"}');
        file_put_contents($data . '/runs/old.json', 'a run, which is not part of the copy');
        file_put_contents($data . '/audit.log', 'not part of the copy');

        return $data;
    }

    public function testTheCopyHoldsTheStateFilesAndNothingElse(): void
    {
        $data = $this->data();

        $copy = StateBackup::make($data, '0.1.0-20270115-080000');

        self::assertSame($data . '/update-backup/0.1.0-20270115-080000', $copy);
        self::assertSame('{"id": "shop"}', file_get_contents($copy . '/profiles/shop.json'));
        self::assertSame('{"next": 1}', file_get_contents($copy . '/schedule/shop.json'));
        self::assertFileExists($copy . '/config.json');
        self::assertFileExists($copy . '/admin.json');
        self::assertFileDoesNotExist($copy . '/runs/old.json');
        self::assertFileDoesNotExist($copy . '/audit.log');
        if (DIRECTORY_SEPARATOR === '/') {
            self::assertSame('0700', substr(sprintf('%o', fileperms(dirname($copy))), -4), 'the copy holds profiles with passwords');
        }
    }

    public function testRestoringPutsBackWhatWasThereAndRemovesWhatCameLater(): void
    {
        $data = $this->data();
        $copy = StateBackup::make($data, 'x');
        file_put_contents($data . '/config.json', 'damaged');
        unlink($data . '/admin.json');
        unlink($data . '/profiles/shop.json');
        file_put_contents($data . '/profiles/made-by-the-failed-update.json', '{}');
        file_put_contents($data . '/cron-secret', 'new');

        StateBackup::restore($data, $copy);

        self::assertSame('{"a": 1}', file_get_contents($data . '/config.json'));
        self::assertSame('{"hash": "x"}', file_get_contents($data . '/admin.json'));
        self::assertSame('{"id": "shop"}', file_get_contents($data . '/profiles/shop.json'));
        self::assertFileDoesNotExist($data . '/profiles/made-by-the-failed-update.json');
        self::assertFileDoesNotExist($data . '/cron-secret', 'a file the copy did not have is gone');
        self::assertFileExists($data . '/audit.log', 'what is not state is not touched');
        self::assertFileExists($data . '/runs/old.json');
    }

    public function testACopyThatIsNotThereCannotBeRestored(): void
    {
        $this->expectException(UpdateException::class);

        StateBackup::restore($this->data(), '/nowhere/copy');
    }

    /** A home with releases 0.1.0 and 0.2.0 unpacked and the pointer on 0.2.0. */
    private function home(?string $previous = '0.1.0'): string
    {
        $home = $this->tempDirectory();
        foreach (['0.1.0', '0.2.0'] as $release) {
            mkdir($home . '/releases/' . $release . '/vendor', 0777, true);
            file_put_contents($home . '/releases/' . $release . '/vendor/autoload.php', '<?php');
        }
        mkdir($home . '/data');
        Home::writePointer($home, '0.2.0', $previous);

        return $home;
    }

    public function testARollbackWritesThePreviousReleaseBackAndLeavesTheData(): void
    {
        $home = $this->home();
        file_put_contents($home . '/data/profile-since-the-update.json', 'kept');

        $done = Rollback::run($home, $home . '/data');

        self::assertSame(['from' => '0.2.0', 'to' => '0.1.0'], $done);
        self::assertSame(['release' => '0.1.0', 'previous' => null], Home::readPointer($home));
        self::assertFileExists($home . '/data/profile-since-the-update.json');
    }

    public function testThereMustBeAPreviousReleaseOnTheHost(): void
    {
        $home = $this->home(null);
        try {
            Rollback::run($home, $home . '/data');
            self::fail('expected a refusal');
        } catch (UpdateException $e) {
            self::assertStringContainsString('no previous release', $e->getMessage());
        }

        $home = $this->home('0.0.9');
        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage('not on this host any more');
        Rollback::run($home, $home . '/data');
    }

    public function testARollbackWaitsForWhatIsRunning(): void
    {
        $home = $this->home();
        $lock = Lock::tryAcquire($home . '/data/locks/backup.lock', 'backup of shop');
        self::assertNotNull($lock);

        try {
            Rollback::run($home, $home . '/data');
            self::fail('expected a refusal');
        } catch (UpdateException $e) {
            self::assertStringContainsString('backup of shop', $e->getMessage());
        } finally {
            $lock->release();
        }
        self::assertSame('0.2.0', Home::readPointer($home)['release'] ?? null);
    }

    public function testReleasesAreListedAndPrunedExceptTheOnesToKeep(): void
    {
        $home = $this->home();
        mkdir($home . '/releases/0.3.0.partial/src', 0777, true);
        file_put_contents($home . '/releases/0.3.0.partial/src/a.php', '<?php');
        mkdir($home . '/releases/0.0.5');

        self::assertSame(['0.0.5', '0.1.0', '0.2.0', '0.3.0.partial'], Releases::all($home));

        $problems = Releases::prune($home, ['0.2.0', '0.1.0']);

        self::assertSame([], $problems);
        self::assertSame(['0.1.0', '0.2.0'], Releases::all($home));
    }

    public function testAReleaseNameThatIsAPathIsNotRemoved(): void
    {
        $home = $this->home();
        mkdir($home . '/important');
        file_put_contents($home . '/important/file', 'x');

        Releases::remove($home, '../important');
        Releases::remove($home, '..');

        self::assertFileExists($home . '/important/file');
    }

    public function testNothingIsBusyInAQuietDataDirectory(): void
    {
        self::assertNull(Busy::reason($this->data()));
    }

    public function testAHeldLockIsBusyAndTheLockOfTheUpdateItselfIsNot(): void
    {
        $data = $this->data();
        $update = Lock::tryAcquire($data . '/locks/update.lock', 'update');
        self::assertNotNull($update);
        self::assertNull(Busy::reason($data), 'the update does not wait for itself');
        $backup = Lock::tryAcquire($data . '/locks/profile-shop.lock', 'backup of shop');
        self::assertNotNull($backup);

        self::assertStringContainsString('backup of shop', (string) Busy::reason($data));

        $backup->release();
        $update->release();
    }

    public function testARecentRunOfTheWebInterfaceIsBusyAnOldOneIsNot(): void
    {
        $data = $this->data();
        $store = new RunStore($data . '/runs');
        $run = $store->create('backup', ['profileId' => 'shop']);
        $now = $run->updated;

        self::assertStringContainsString('backup is running', (string) Busy::reason($data, static fn (): int => $now + 10));
        self::assertNull(Busy::reason($data, static fn (): int => $now + 3600), 'a run that nobody has worked on for an hour is interrupted, not running');

        $store->save($run->with(RunStatus::Finished, $run->state));
        self::assertNull(Busy::reason($data, static fn (): int => $now + 10));
    }

    public function testARunOfTheUpdateIsNotBusyForTheUpdate(): void
    {
        $data = $this->data();
        $store = new RunStore($data . '/runs');
        $store->create('update', []);

        self::assertNull(Busy::reason($data));
    }
}
