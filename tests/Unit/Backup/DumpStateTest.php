<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Backup;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ThreadSafe\Backup\AppendFile;
use ThreadSafe\Backup\Column;
use ThreadSafe\Backup\DumpState;
use ThreadSafe\Backup\RowGroup;
use ThreadSafe\Tests\Support\TempDirectory;

final class DumpStateTest extends TestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    public function testStateSurvivesJson(): void
    {
        $state = new DumpState('/work', true, ['log', 'ps_connections'], 1000, 2000, 3000);
        $state->phase = DumpState::TABLES;
        $state->ticks = 3;
        $state->tables = ['a', 'b'];
        $state->index = 1;
        $state->current = ['file' => '0002_b.sql', 'size' => 123, 'key' => [5, 'x', '00FF'], 'rows' => 9, 'chunk' => 40];
        $state->done = [['table' => 'a', 'file' => '0001_a.sql', 'rows' => 4, 'bytes' => 99, 'structureOnly' => false]];
        $state->objectFiles = [['file' => '_views.sql', 'bytes' => 10]];
        $state->unsupported = [['type' => 'function', 'name' => 'f']];
        $state->warnings = ['careful'];
        $state->rows = 13;
        $state->bytes = 222;

        $json = json_encode($state->toArray(), JSON_THROW_ON_ERROR);
        $again = DumpState::fromArray(json_decode($json, true, 512, JSON_THROW_ON_ERROR));

        self::assertSame($state->toArray(), $again->toArray());
        self::assertSame('snapshot', $again->consistency);
    }

    public function testAStateWithoutSnapshotIsConsistentPerTable(): void
    {
        self::assertSame('table', (new DumpState('/w', false, [], 1, 1, 1))->consistency);
    }

    public function testInvalidStateIsRefused(): void
    {
        $good = (new DumpState('/w', false, [], 1, 1, 1))->toArray();
        foreach ([
            ['directory' => 5],
            ['ticks' => '3'],
            ['tables' => [1]],
            ['current' => ['file' => 'x']],
            ['done' => [['table' => 'a']]],
            ['unsupported' => [['type' => 'x']]],
        ] as $damage) {
            try {
                DumpState::fromArray([...$good, ...$damage]);
                self::fail('Accepted ' . json_encode($damage));
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('dump state is invalid', $e->getMessage());
            }
        }
    }

    public function testAppendFileCutsWhatAnInterruptedRunLeftBehind(): void
    {
        $path = $this->tempDirectory() . '/t.sql';
        $file = new AppendFile($path, 0);
        $file->write('first;');
        $file->write('second;');
        $file->close();
        self::assertSame(13, $file->size());

        $again = new AppendFile($path, 6);
        $again->write('other;');
        $again->close();

        self::assertSame('first;other;', file_get_contents($path));
        self::assertSame(12, $again->size());
    }

    public function testAppendFileRefusesAFileShorterThanTheState(): void
    {
        $path = $this->tempDirectory() . '/t.sql';
        file_put_contents($path, 'abc');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('shorter than the saved state');

        new AppendFile($path, 10);
    }

    public function testRowGroupWritesStatementsOfTheRightSize(): void
    {
        $path = $this->tempDirectory() . '/t.sql';
        $file = new AppendFile($path, 0);
        $group = new RowGroup("INSERT INTO t VALUES\n", 10, $file);

        $group->add('(1)');
        $group->add('(2)');
        self::assertSame(0, $file->size(), 'Below the size nothing is written yet.');
        $group->add('(3333)');
        $group->add('(4)');
        $group->flush();
        $group->flush();
        $file->close();

        self::assertSame("INSERT INTO t VALUES\n(1),\n(2),\n(3333);\nINSERT INTO t VALUES\n(4);\n", file_get_contents($path));
    }

    public function testColumnNamesKeepTheirBackticks(): void
    {
        self::assertSame('we`ird', (new Column('we`ird', 'int'))->name);
    }
}
