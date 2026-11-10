<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Web;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Web\AgentReplayStore;

final class AgentReplayStoreTest extends TestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    public function testANewNonceIsAcceptedOnceAndARepeatIsNot(): void
    {
        $store = new AgentReplayStore($this->tempDirectory() . '/replays');

        self::assertTrue($store->remember('nonce-one', 1000));
        self::assertFalse($store->remember('nonce-one', 1001));
        self::assertTrue($store->remember('nonce-two', 1002), 'another nonce is new');
    }

    public function testANonceIsForgottenWhenItsRequestCouldNotBeAcceptedAnyMore(): void
    {
        $store = new AgentReplayStore($this->tempDirectory() . '/replays');
        $store->remember('nonce-one', 1000);

        self::assertFalse($store->remember('nonce-one', 1000 + 2 * 120));
        self::assertTrue($store->remember('nonce-one', 1000 + 2 * 120 + 11), 'older than two windows: a request that old is refused by its time');
    }

    public function testTheFileIsPrivateAndDamagedLinesAreIgnored(): void
    {
        $file = $this->tempDirectory() . '/replays';
        file_put_contents($file, "garbage\n\n12 \nnot-a-number nonce-x\n");
        $store = new AgentReplayStore($file);

        self::assertTrue($store->remember('nonce-one', 1000));
        self::assertStringNotContainsString('garbage', (string) file_get_contents($file));
        if (DIRECTORY_SEPARATOR === '/') {
            self::assertSame(0600, fileperms($file) & 0777);
        }
    }

    public function testAnUnusableFileMeansNothingIsAccepted(): void
    {
        $directory = $this->tempDirectory();
        mkdir($directory . '/replays');   // a directory where the file should be

        self::assertFalse((new AgentReplayStore($directory . '/replays'))->remember('nonce-one', 1000), 'an agent that cannot tell a repeat from a new request must not answer');
    }

    public function testANonceThatCannotBeWrittenIsNotAccepted(): void
    {
        // a full disk: the file opens and locks, and nothing can be written to it
        stream_wrapper_register('fulldisk', FullDiskStream::class);
        try {
            $store = new AgentReplayStore('fulldisk://data/replays');

            self::assertFalse($store->remember('nonce-one', 1000), 'a nonce that is not remembered could be sent again: the request is refused');
            self::assertFalse($store->remember('nonce-one', 1001));
        } finally {
            stream_wrapper_unregister('fulldisk');
        }
    }

    public function testTheDirectoryIsMadeWhenItIsMissing(): void
    {
        $store = new AgentReplayStore($this->tempDirectory() . '/data/nested/replays');

        self::assertTrue($store->remember('nonce-one', 1000));
    }
}

/**
 * A file on a disk that is full, as a stream wrapper: it can be opened, locked, read (it is empty) and cut, and every write stores nothing.
 */
final class FullDiskStream
{
    /** @var resource|null */
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        return true;
    }

    public function stream_lock(int $operation): bool
    {
        return true;
    }

    public function stream_read(int $count): string
    {
        return '';
    }

    public function stream_eof(): bool
    {
        return true;
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        return $offset === 0;
    }

    public function stream_tell(): int
    {
        return 0;
    }

    public function stream_truncate(int $size): bool
    {
        return true;
    }

    public function stream_write(string $data): int
    {
        return 0;
    }

    public function stream_flush(): bool
    {
        return true;
    }

    public function stream_close(): void
    {
    }

    public function stream_metadata(string $path, int $option, mixed $value): bool
    {
        return true;
    }

    /**
     * @return array<string, int>|false
     */
    public function url_stat(string $path, int $flags): array|false
    {
        // the directory is there, the file is not yet
        return str_ends_with($path, '/replays') ? false : ['mode' => 040700, 'size' => 0];
    }
}
