<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Support;

/**
 * A read-only stream wrapper over a string that hands out at most one read chunk per fread(), as the wrapper of an encrypted
 * archive part does. `ChunkedStream::serve($bytes)` returns the path to open; `ChunkedStream::forget()` removes the wrapper.
 */
final class ChunkedStream
{
    private const SCHEME = 'chunked';

    /** @var array<string, string> */
    private static array $files = [];

    /** @var resource|null set by PHP */
    public $context;

    private string $bytes = '';
    private int $position = 0;

    public static function serve(string $bytes): string
    {
        if (!in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::SCHEME, self::class);
        }
        $name = 'file' . count(self::$files);
        self::$files[$name] = $bytes;

        return self::SCHEME . '://' . $name;
    }

    public static function forget(): void
    {
        self::$files = [];
        if (in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(self::SCHEME);
        }
    }

    public function stream_open(string $path): bool
    {
        $name = substr($path, strlen(self::SCHEME . '://'));
        if (!isset(self::$files[$name])) {
            return false;
        }
        $this->bytes = self::$files[$name];

        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr($this->bytes, $this->position, $count);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen($this->bytes);
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    public function stream_seek(int $offset, int $whence): bool
    {
        $target = match ($whence) {
            SEEK_SET => $offset,
            SEEK_CUR => $this->position + $offset,
            SEEK_END => strlen($this->bytes) + $offset,
            default => -1,
        };
        if ($target < 0) {
            return false;
        }
        $this->position = $target;

        return true;
    }

    /** @return array<string, int> */
    public function stream_stat(): array
    {
        return ['size' => strlen($this->bytes)];
    }
}
