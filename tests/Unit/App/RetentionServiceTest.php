<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\App;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Admin\AuditLog;
use ThreadSafe\App\RetentionService;
use ThreadSafe\Backup\BackupState;
use ThreadSafe\Engine\Run;
use ThreadSafe\Engine\RunStatus;
use ThreadSafe\Retention\RetentionStateStore;
use ThreadSafe\Tests\Support\BackupFixture;
use ThreadSafe\Tests\Support\FakeBackups;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * Retention for the command, the pages and the trigger: applied by hand, by itself after a backup, once an hour; the go-ahead that a
 * rule needs before it deletes more than one backup on its own; the audit log.
 */
final class RetentionServiceTest extends TestCase
{
    use TempDirectory;

    private BackupFixture $fixture;

    private FakeBackups $fake;

    private int $now;

    protected function setUp(): void
    {
        $this->fixture = new BackupFixture($this->tempDirectory());
        $this->fake = new FakeBackups($this->fixture);
        $this->now = (new DateTimeImmutable('2026-10-22 12:00:00', new DateTimeZone('UTC')))->getTimestamp();
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    private function service(): RetentionService
    {
        $clock = fn (): int => $this->now;

        return new RetentionService($this->fixture->profiles, $this->fixture->runs, new RetentionStateStore($this->fixture->data . '/retention'), new AuditLog($this->fixture->data . '/audit.log', 5242880, $clock), $clock);
    }

    /** @param array<string, int> $rules */
    private function rules(array $rules): void
    {
        $this->fixture->profiles->save($this->fixture->profiles->load('blog')->with(['retention' => $rules]));
    }

    private function profile(): \ThreadSafe\Profile\SiteProfile
    {
        return $this->fixture->profiles->load('blog');
    }

    private static function days(int $days): string
    {
        return (new DateTimeImmutable('2026-10-22 12:00:00', new DateTimeZone('UTC')))->modify("-$days days")->format('Y-m-d H:i:s');
    }

    private function audit(): string
    {
        return (string) @file_get_contents($this->fixture->data . '/audit.log');
    }

    public function testAnUnconfirmedRuleThatWouldDeleteMoreThanOneBackupWaitsForTheGoAhead(): void
    {
        foreach ([1, 2, 3, 4, 5] as $days) {
            $this->fake->make(self::days($days));
        }
        $this->rules(['keep' => 2]);

        self::assertNull($this->service()->automatic($this->profile()), 'three would go: it waits');
        self::assertSame(3, $this->service()->state('blog')->waiting);
        self::assertSame(3, $this->service()->waiting($this->profile()));
        self::assertCount(5, glob($this->fixture->storageDirectory . '/blog_*.json') ?: [], 'nothing was deleted');
        self::assertStringContainsString('"event":"retention.waiting"', $this->audit());
        $this->service()->automatic($this->profile());
        self::assertSame(1, substr_count($this->audit(), 'retention.waiting'), 'said once');

        $done = $this->service()->apply($this->profile(), 'admin', '10.0.0.1');
        self::assertCount(3, $done->delete);
        self::assertCount(2, glob($this->fixture->storageDirectory . '/blog_*.json') ?: []);
        self::assertNull($this->service()->state('blog')->waiting);
        self::assertSame(3, $this->service()->state('blog')->lastDeleted);
        self::assertSame(3, substr_count($this->audit(), '"event":"retention.deleted"'));
        self::assertStringContainsString('"rule":"keep"', $this->audit());
        self::assertStringContainsString('"user":"admin"', $this->audit());

        // confirmed: from now on the rules work by themselves, also for several backups at once
        foreach ([1, 2, 3] as $days) {
            $this->fake->make(self::days($days - 1) . ' ');
        }
        $auto = $this->service()->automatic($this->profile());
        self::assertNotNull($auto);
        self::assertGreaterThan(1, count($auto->delete));
    }

    public function testChangingTheRulesAsksAgain(): void
    {
        foreach ([1, 2, 3, 4] as $days) {
            $this->fake->make(self::days($days));
        }
        $this->rules(['keep' => 4]);
        $this->service()->apply($this->profile());

        $this->rules(['keep' => 1]);

        self::assertNull($this->service()->automatic($this->profile()), 'other rules, three deletions: it waits again');
        self::assertCount(4, glob($this->fixture->storageDirectory . '/blog_*.json') ?: []);
    }

    public function testASingleDeletionNeedsNoGoAhead(): void
    {
        foreach ([1, 2, 3] as $days) {
            $this->fake->make(self::days($days));
        }
        $this->rules(['keep' => 2]);

        $done = $this->service()->automatic($this->profile());

        self::assertNotNull($done);
        self::assertCount(1, $done->delete, 'the ordinary case: one backup came, one goes');
        self::assertCount(2, glob($this->fixture->storageDirectory . '/blog_*.json') ?: []);
    }

    public function testHourlyRunsOncePerHourAndSkipsAProfileWhoseLastBackupFailed(): void
    {
        foreach ([1, 40] as $days) {
            $this->fake->make(self::days($days));
        }
        $this->rules(['maxAgeDays' => 30]);

        self::assertCount(1, $this->service()->hourly(), 'the first time it runs');
        self::assertSame([], $this->service()->hourly(), 'not again within the hour');
        $this->now += 3601;
        self::assertCount(1, $this->service()->hourly(), 'and an hour later it does');

        $this->fake->make(self::days(60));
        $this->now += 3601;
        $state = new BackupState('blog', false, false, null, null);
        $this->fixture->runs->save(new Run('backup-20261022-110000-aaaaaaaa', 'backup', RunStatus::Failed, 1, $this->now - 100, $this->now - 100, 'boom', null, $state->toArray()));
        self::assertSame([], $this->service()->hourly(), 'after trouble nothing is deleted on a hunch');
        self::assertCount(2, glob($this->fixture->storageDirectory . '/blog_*.json') ?: []);
    }

    public function testABackupThatARunWritesIsNotTouched(): void
    {
        $this->fake->make(self::days(1));
        $old = $this->fake->make(self::days(100));
        $this->rules(['maxAgeDays' => 30]);
        $state = new BackupState('blog', false, false, null, null);
        $state->backup = $old->base();
        $this->fixture->runs->save(new Run('backup-20261022-110000-bbbbbbbb', 'backup', RunStatus::Running, 1, $this->now, $this->now, null, null, $state->toArray()));

        $done = $this->service()->apply($this->profile());

        self::assertSame([], $done->delete);
        self::assertTrue($this->fake->exists($old));
    }

    public function testAProfileWithoutRulesIsLeftAlone(): void
    {
        $this->fake->make(self::days(100));

        self::assertNull($this->service()->automatic($this->profile()));
        self::assertSame([], $this->service()->hourly());
    }
}
