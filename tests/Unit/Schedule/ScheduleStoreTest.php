<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Schedule;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Schedule\ScheduleException;
use ThreadSafe\Schedule\ScheduleState;
use ThreadSafe\Schedule\ScheduleStore;
use ThreadSafe\Tests\Support\TempDirectory;

final class ScheduleStoreTest extends TestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    public function testTheStateRoundTripsPerProfile(): void
    {
        $directory = $this->tempDirectory() . '/data/schedule';
        $store = new ScheduleStore($directory);

        self::assertNull($store->load('shop'));

        $store->save('shop', new ScheduleState(100, 200, ScheduleState::PENDING, 2, 300));
        $store->save('blog', new ScheduleState(1));

        self::assertEquals(new ScheduleState(100, 200, ScheduleState::PENDING, 2, 300), $store->load('shop'));
        self::assertEquals(new ScheduleState(1), $store->load('blog'));
        self::assertFileExists($directory . '/shop.json');
        self::assertSame(0700, fileperms($directory) & 0777);

        $store->delete('shop');
        self::assertNull($store->load('shop'));
        self::assertNotNull($store->load('blog'));
    }

    public function testOnlyAValidProfileIdBecomesAFileName(): void
    {
        $store = new ScheduleStore($this->tempDirectory());

        $this->expectException(ScheduleException::class);
        $store->save('../outside', new ScheduleState(1));
    }

    public function testStateThatMakesNoSenseReadsAsNoState(): void
    {
        $directory = $this->tempDirectory();
        $store = new ScheduleStore($directory);

        foreach (['not json', '[]', '{"since": 1, "status": "exploded"}', '{"since": "x"}', '{"since": 1, "attempts": -2}', '"text"'] as $content) {
            file_put_contents($directory . '/shop.json', $content);
            self::assertNull($store->load('shop'), $content);
        }
    }

    public function testAnInstantIsHandledOnceItLeftThePendingState(): void
    {
        self::assertFalse((new ScheduleState(1, 50))->handled(50));
        self::assertTrue((new ScheduleState(1, 50, ScheduleState::RUNNING))->handled(50));
        self::assertTrue((new ScheduleState(1, 50, ScheduleState::DONE))->handled(50));
        self::assertTrue((new ScheduleState(1, 50, ScheduleState::GAVE_UP))->handled(50));
        self::assertTrue((new ScheduleState(1, 50, ScheduleState::SKIPPED))->handled(50));
        self::assertFalse((new ScheduleState(1, 50, ScheduleState::DONE))->handled(51), 'another instant is another matter');
    }
}
