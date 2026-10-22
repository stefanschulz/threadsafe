<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\App;

use PHPUnit\Framework\TestCase;
use ThreadSafe\App\BackupService;
use ThreadSafe\App\Overview;
use ThreadSafe\Backup\BackupState;
use ThreadSafe\Backup\BackupStatus;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Engine\Run;
use ThreadSafe\Engine\RunStatus;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Profile\SiteProfile;
use ThreadSafe\Tests\Support\BackupFixture;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * What the dashboard knows about a site, from the cheap reads only.
 */
final class OverviewTest extends TestCase
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
     * @param (\Closure(string): ?int)|null $free
     */
    private function overview(?int $now = null, ?\Closure $free = null, int $days = 7): Overview
    {
        $backups = new BackupService($this->fixture->profiles, PluginRegistry::load(), $this->fixture->runs, $this->fixture->data, static fn (): Budget => new Budget(0.0), $now === null ? null : static fn (): int => $now, 60);

        return new Overview($this->fixture->profiles, $this->fixture->runs, $backups, $days, $now === null ? null : static fn (): int => $now, $free);
    }

    /**
     * @return array<string, mixed>
     */
    private function card(?int $now = null, ?\Closure $free = null): array
    {
        $built = $this->overview($now, $free)->build();
        self::assertCount(1, $built['cards']);

        return $built['cards'][0];
    }

    /**
     * @param array<string, mixed> $card
     *
     * @return list<string>
     */
    private function texts(array $card): array
    {
        $texts = [];
        foreach ($card['warnings'] as $warning) {
            $texts[] = (string) $warning['text'];
        }

        return $texts;
    }

    private function record(string $id, RunStatus $status, bool $web, int $updated, ?string $error = null): void
    {
        $state = new BackupState('blog', false, false, null, null);
        $state->web = $web;
        $this->fixture->runs->save(new Run($id, 'backup', $status, 1, $updated, $updated, $error, null, $state->toArray()));
    }

    public function testASiteWithoutABackupIsAWarning(): void
    {
        $card = $this->card();

        self::assertSame(Overview::WARNING, $card['level']);
        self::assertContains('There is no backup yet.', $this->texts($card));
        self::assertSame(0, $card['backups']);
        self::assertNull($card['last']);
    }

    public function testAFreshGoodBackupIsOkWithItsSizeAndTotals(): void
    {
        $name = $this->fixture->backup();

        $built = $this->overview(time() + 60)->build();

        $card = $built['cards'][0];
        self::assertSame(Overview::OK, $card['level']);
        self::assertSame([], $card['warnings']);
        self::assertSame($name->base(), $card['last']->name->base());
        self::assertSame(1, $card['backups']);
        self::assertGreaterThan(1_000_000, $card['bytes']);
        self::assertSame(['profiles' => 1, 'backups' => 1, 'bytes' => $card['bytes'], 'running' => 0], $built['totals']);
    }

    public function testNoSuccessfulBackupForTooLongIsAWarningWithTheNumberOfDays(): void
    {
        $name = $this->fixture->backup();

        $card = $this->card($name->time->getTimestamp() + 9 * 86400 + 100);

        self::assertSame(Overview::WARNING, $card['level']);
        self::assertContains('No successful backup for 9 days.', $this->texts($card));
        $fresh = $this->card($name->time->getTimestamp() + 6 * 86400);
        self::assertSame(Overview::OK, $fresh['level']);
    }

    public function testAFaultyNewestBackupIsAProblemEvenWhenAnOlderOneIsGood(): void
    {
        $good = $this->fixture->backup();
        sleep(1);
        $bad = $this->fixture->backup();
        $storage = $this->fixture->storage();
        $storage->writeSidecar($bad, $storage->readSidecar($bad)->withStatus(BackupStatus::Faulty));

        $card = $this->card($bad->time->getTimestamp() + 60);

        self::assertSame(Overview::PROBLEM, $card['level']);
        self::assertContains('The newest backup is faulty.', $this->texts($card));
        self::assertSame($good->base(), $card['lastGood']->name->base(), 'the newest usable one is the older');
    }

    public function testAFailedRunIsAProblemWithItsReasonAndAnInterruptedOneAWarning(): void
    {
        $this->fixture->backup();
        $now = time() + 60;
        $this->record('backup-20261021-120000-aaaaaaaa', RunStatus::Failed, true, $now - 100, 'ThreadSafe\\Core\\Exception\\GuardFailed: The storage is full.');

        $failed = $this->card($now);
        self::assertSame(Overview::PROBLEM, $failed['level']);
        self::assertContains('The last backup run failed: The storage is full.', $this->texts($failed));

        $this->fixture->runs->delete('backup-20261021-120000-aaaaaaaa');
        $this->record('backup-20261021-120001-bbbbbbbb', RunStatus::Running, true, $now - 3600);
        $interrupted = $this->card($now);
        self::assertSame('interrupted', $interrupted['runState']);
        self::assertSame(Overview::WARNING, $interrupted['level']);
    }

    public function testARunThatIsGoingIsCountedAndShown(): void
    {
        $this->fixture->backup();
        $now = time() + 60;
        $this->record('backup-20261021-120002-cccccccc', RunStatus::Running, true, $now - 5);

        $built = $this->overview($now)->build();

        self::assertSame('active', $built['cards'][0]['runState']);
        self::assertSame(1, $built['totals']['running']);
    }

    public function testASiteThatIsGoneAndAStorageWithoutProtectionAreReported(): void
    {
        $this->fixture->backup();
        $now = time() + 60;

        unlink($this->fixture->storageDirectory . '/.htaccess');
        $unprotected = $this->card($now);
        self::assertSame(Overview::WARNING, $unprotected['level']);
        self::assertStringContainsString('no protection files', implode(' ', $this->texts($unprotected)));

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->fixture->site, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->fixture->site);
        $gone = $this->card($now);
        self::assertSame(Overview::PROBLEM, $gone['level']);
        self::assertStringContainsString('site directory no longer exists', implode(' ', $this->texts($gone)));
    }

    public function testLittleFreeSpaceComparedWithTheLastBackupIsAWarning(): void
    {
        $name = $this->fixture->backup();
        $size = $this->fixture->storage()->readSidecar($name)->size();
        $now = $name->time->getTimestamp() + 60;

        $tight = $this->card($now, static fn (string $dir): int => (int) ($size * 1.1));
        self::assertStringContainsString('Little free space', implode(' ', $this->texts($tight)));

        $roomy = $this->card($now, static fn (string $dir): int => $size * 3);
        self::assertSame([], $roomy['warnings']);
    }

    public function testAnUnreadableStorageDoesNotBreakTheOverview(): void
    {
        $this->fixture->profiles->save(SiteProfile::fromArray(['id' => 'other', 'type' => 'generic', 'path' => $this->fixture->site, 'storage' => $this->fixture->storageDirectory . '/nope/never']));

        $built = $this->overview()->build();

        self::assertCount(2, $built['cards']);
        self::assertSame(2, $built['totals']['profiles']);
    }
}
