<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Engine;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Engine\Lock;
use ThreadSafe\Tests\Support\TempDirectory;

final class LockTest extends TestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    public function testOnlyOneHolderAtATime(): void
    {
        $path = $this->tempDirectory() . '/locks/profile.lock';

        $first = Lock::tryAcquire($path, 'backup shop');
        self::assertNotNull($first);
        self::assertTrue(Lock::isHeld($path));
        self::assertNull(Lock::tryAcquire($path), 'A second holder is refused at once, not made to wait.');
        self::assertSame('pid ' . getmypid() . ' backup shop', Lock::holder($path));

        $first->release();
        self::assertFalse(Lock::isHeld($path));
        self::assertNotNull(Lock::tryAcquire($path));
    }

    public function testTheLockIsReleasedWhenTheObjectGoes(): void
    {
        $path = $this->tempDirectory() . '/a.lock';

        $lock = Lock::tryAcquire($path);
        self::assertTrue(Lock::isHeld($path));
        unset($lock);

        self::assertFalse(Lock::isHeld($path));
    }

    public function testFilesAndDirectoryArePrivate(): void
    {
        $directory = $this->tempDirectory() . '/new/locks';
        $lock = Lock::tryAcquire($directory . '/a.lock');

        self::assertNotNull($lock);
        self::assertSame(0600, fileperms($directory . '/a.lock') & 0777);
        self::assertSame(0700, fileperms($directory) & 0777);
    }

    public function testNothingIsHeldWithoutAFile(): void
    {
        self::assertFalse(Lock::isHeld($this->tempDirectory() . '/none.lock'));
        self::assertNull(Lock::holder($this->tempDirectory() . '/none.lock'));
    }

    /**
     * The lock of another process holds - and ends with that process, however it ends: no stale locks.
     */
    public function testAnotherProcessHoldsTheLockUntilItEnds(): void
    {
        $path = $this->tempDirectory() . '/other.lock';
        $code = '$h = fopen($argv[1], "c+"); flock($h, LOCK_EX); echo "ready\n"; fflush(STDOUT); fgets(STDIN);';
        $process = proc_open([PHP_BINARY, '-r', $code, $path], [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        self::assertSame("ready\n", fgets($pipes[1]), 'The other process has the lock.');

        self::assertTrue(Lock::isHeld($path));
        self::assertNull(Lock::tryAcquire($path));

        proc_terminate($process, 9);   // killed, no chance to clean up
        fclose($pipes[0]);
        fclose($pipes[1]);
        proc_close($process);

        self::assertFalse(Lock::isHeld($path), 'The lock of a dead process is free.');
        self::assertNotNull(Lock::tryAcquire($path));
    }
}
