<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Engine;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Engine\EngineException;
use ThreadSafe\Engine\Lock;
use ThreadSafe\Engine\RunStatus;
use ThreadSafe\Engine\RunStore;
use ThreadSafe\Tests\Support\TempDirectory;

final class RunStoreTest extends TestCase
{
    use TempDirectory;

    private string $directory;

    private RunStore $store;

    protected function setUp(): void
    {
        $this->directory = $this->tempDirectory() . '/runs';
        $this->store = new RunStore($this->directory);
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    public function testCreateLoadSaveRoundTrip(): void
    {
        $run = $this->store->create('backup', ['phase' => 'scan', 'queue' => [['a', 1], ['b', 2]], 'ratio' => 0.1, 'text' => "ü\n\"quoted\"", 'none' => null]);

        self::assertMatchesRegularExpression('/^backup-\d{8}-\d{6}-[0-9a-f]{8}$/', $run->id);
        self::assertSame(RunStatus::Running, $run->status);

        $loaded = $this->store->load($run->id);
        self::assertEquals($run, $loaded);

        $next = $loaded->with(RunStatus::Running, ['phase' => 'dump'], null, 'dumping');
        $this->store->save($next);
        $again = $this->store->load($run->id);
        self::assertSame(1, $again->ticks);
        self::assertSame(['phase' => 'dump'], $again->state);
        self::assertSame('dumping', $again->progress);
    }

    public function testFilesAndDirectoryArePrivateAndNoTemporaryFileIsLeft(): void
    {
        $run = $this->store->create('backup', []);
        $this->store->save($run->with(RunStatus::Running, ['x' => 1]));

        self::assertSame(0700, fileperms($this->directory) & 0777);
        self::assertSame(0600, fileperms($this->directory . '/' . $run->id . '.json') & 0777);
        self::assertSame([$run->id . '.json'], array_values(array_diff(scandir($this->directory) ?: [], ['.', '..'])));
    }

    public function testAllListsNewestFirstAndFilters(): void
    {
        $a = $this->store->create('backup', []);
        $b = $this->store->create('other', []);
        file_put_contents($this->directory . '/notes.json', '{}');
        file_put_contents($this->directory . '/backup-20260101-000000-deadbeef.json', 'not json');

        $ids = array_map(static fn ($r): string => $r->id, $this->store->all());

        self::assertEqualsCanonicalizing([$a->id, $b->id], $ids, 'Foreign and damaged files are skipped.');
        self::assertSame([$a->id], array_map(static fn ($r): string => $r->id, $this->store->all('backup')));
    }

    public function testDeleteRemovesTheRunAndItsLock(): void
    {
        $run = $this->store->create('backup', []);
        $lock = Lock::tryAcquire($this->store->lockPath($run->id));
        self::assertNotNull($lock);
        $lock->release();

        $this->store->delete($run->id);

        self::assertSame([], $this->store->all());
        self::assertFileDoesNotExist($this->store->lockPath($run->id));
    }

    public function testInterruptedMeansRunningWithoutALockHolder(): void
    {
        $run = $this->store->create('backup', []);
        self::assertTrue($this->store->isInterrupted($run), 'Nobody executes it.');

        $lock = Lock::tryAcquire($this->store->lockPath($run->id));
        self::assertFalse($this->store->isInterrupted($run), 'Somebody is executing it.');
        $lock?->release();

        self::assertFalse($this->store->isInterrupted($run->with(RunStatus::Finished, [])));
    }

    /** @return iterable<string, array{string}> */
    public static function badIds(): iterable
    {
        yield 'path traversal' => ['../../etc/passwd'];
        yield 'slash' => ['backup/20260101-000000-deadbeef'];
        yield 'no random part' => ['backup-20260101-000000'];
        yield 'upper case' => ['Backup-20260101-000000-deadbeef'];
        yield 'empty' => [''];
    }

    #[DataProvider('badIds')]
    public function testInvalidIdsAreRefused(string $id): void
    {
        $this->expectException(EngineException::class);
        $this->expectExceptionMessage('not a valid run id');

        $this->store->load($id);
    }

    public function testMissingAndDamagedRuns(): void
    {
        try {
            $this->store->load('backup-20260101-000000-deadbeef');
            self::fail('Missing.');
        } catch (EngineException $e) {
            self::assertStringContainsString('There is no run', $e->getMessage());
        }

        mkdir($this->directory);
        file_put_contents($this->directory . '/backup-20260101-000000-deadbeef.json', '{broken');
        try {
            $this->store->load('backup-20260101-000000-deadbeef');
            self::fail('Damaged.');
        } catch (EngineException $e) {
            self::assertStringContainsString('damaged', $e->getMessage());
        }

        file_put_contents($this->directory . '/backup-20260101-000000-deadbeef.json', json_encode(['format' => 1, 'id' => 'backup-20260101-000000-cafebabe', 'task' => 'x', 'status' => 'running', 'ticks' => 0, 'created' => 1, 'updated' => 1, 'state' => []]));
        try {
            $this->store->load('backup-20260101-000000-deadbeef');
            self::fail('Wrong id inside.');
        } catch (EngineException $e) {
            self::assertStringContainsString('not a run file', $e->getMessage());
        }
    }

    public function testStateThatCannotBeStoredIsRefused(): void
    {
        $this->expectException(EngineException::class);
        $this->expectExceptionMessage('cannot be stored as JSON');

        $this->store->create('backup', ['number' => NAN]);
    }

    public function testTaskIdsAreValidated(): void
    {
        $this->expectException(EngineException::class);

        $this->store->create('Bad Task', []);
    }
}
