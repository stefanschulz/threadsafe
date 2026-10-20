<?php

declare(strict_types=1);

namespace ThreadSafe\Archive;

use HashContext;

/**
 * Writes one part of a backup archive as a standard ZIP file, in steps that can span PHP processes.
 *
 * - **Streaming:** local headers carry the "data descriptor" flag, so an entry is written without knowing its size or CRC in
 *   advance; the central directory is collected in a side file and appended when the part is closed.
 * - **Fixed blocks:** the data of an entry is handed over in blocks of exactly {@see BLOCK} bytes (the last one shorter).
 *   Each block is compressed on its own as a raw deflate stream ended with a full flush, the last one with a finish; the
 *   concatenation is one valid deflate stream. Because the block size never depends on when a tick ends, the archive is
 *   byte-identical however the work was cut into ticks (verified by the tests).
 * - **Resumable:** {@see state()} returns everything needed to go on in a new process (offsets, the CRC and SHA-256 hash
 *   contexts as serialised data); {@see resume()} truncates the file to the saved offset, so bytes a dead process wrote after the
 *   last saved state are dropped.
 * - **ZIP64:** an entry whose size is unknown or large gets ZIP64 records; the end records switch when counts or offsets
 *   exceed the classic limits. `forceZip64` makes every entry and the end records use them (for tests).
 * - **Atomic:** the part is written as `<path>.part` and renamed to `<path>` when it is closed; {@see PartInfo} carries its SHA-256.
 *
 * The writer knows nothing about backups: it adds entries. Rolling over to the next part is the caller's decision
 * ({@see needsRollover()}); it closes this writer and creates the next.
 */
final class ZipWriter
{
    /** Size of the blocks entry data is handed over in (and compressed in). */
    public const int BLOCK = 262144;

    private const int STATE_VERSION = 1;

    /** An entry that is expected to reach this size gets ZIP64 records (compressed data can be a little larger than the original). */
    private const int ZIP64_THRESHOLD = 0xF0000000;

    private const int COPY_CHUNK = 1048576;

    /** @var resource */
    private $part;

    /** @var resource */
    private $directory;

    private HashContext $sha;

    private int $offset = 0;

    private int $directorySize = 0;

    private int $entries = 0;

    /** @var array<string, mixed>|null the entry being written */
    private ?array $current = null;

    private bool $finished = false;

    private function __construct(
        private readonly string $path,
        private readonly int $level,
        private readonly bool $forceZip64,
    ) {
    }

    /**
     * Starts a new part. Does not touch `$path` until {@see close()}; an old `$path.part` of an abandoned run is replaced.
     *
     * @param int $level deflate level 1-9
     *
     * @throws ZipException
     */
    public static function create(string $path, int $level = 6, bool $forceZip64 = false): self
    {
        if ($level < 1 || $level > 9) {
            throw new ZipException('The compression level must be between 1 and 9.');
        }
        if (file_exists($path)) {
            throw new ZipException("The archive part \"$path\" exists already.");
        }
        $writer = new self($path, $level, $forceZip64);
        $writer->part = self::open($path . '.part', 'w+b');
        $writer->directory = self::open($path . '.cd', 'w+b');
        $writer->sha = hash_init('sha256');

        return $writer;
    }

    /**
     * Continues a part from a state returned by {@see state()}.
     *
     * @param array<string, mixed> $state
     *
     * @throws ZipException the state is invalid or the files do not match it
     */
    public static function resume(array $state): self
    {
        if (($state['version'] ?? null) !== self::STATE_VERSION || !is_string($state['path'] ?? null) || !is_int($state['offset'] ?? null)
            || !is_int($state['directorySize'] ?? null) || !is_int($state['entries'] ?? null) || !is_int($state['level'] ?? null)
            || !is_bool($state['forceZip64'] ?? null) || !is_string($state['sha'] ?? null)) {
            throw new ZipException('The saved state of the archive part is invalid.');
        }
        $writer = new self($state['path'], $state['level'], $state['forceZip64']);
        $writer->part = self::open($state['path'] . '.part', 'r+b');
        $writer->directory = self::open($state['path'] . '.cd', 'r+b');
        self::truncateTo($writer->part, max(0, $state['offset']), 'archive part');
        self::truncateTo($writer->directory, max(0, $state['directorySize']), 'central directory file');
        $sha = unserialize(base64_decode($state['sha'], true) ?: '', ['allowed_classes' => [HashContext::class]]);
        if (!$sha instanceof HashContext) {
            throw new ZipException('The saved hash state is invalid.');
        }
        $writer->sha = $sha;
        $writer->offset = $state['offset'];
        $writer->directorySize = $state['directorySize'];
        $writer->entries = $state['entries'];
        $current = $state['current'] ?? null;
        $writer->current = is_array($current) ? $current : null;

        return $writer;
    }

    /**
     * Everything needed to continue in another process. Flushes the files first; call it at the end of a tick.
     *
     * @return array<string, mixed>
     */
    public function state(): array
    {
        $this->assertOpen();
        fflush($this->part);
        fflush($this->directory);

        return [
            'version' => self::STATE_VERSION,
            'path' => $this->path,
            'level' => $this->level,
            'forceZip64' => $this->forceZip64,
            'offset' => $this->offset,
            'directorySize' => $this->directorySize,
            'entries' => $this->entries,
            'sha' => base64_encode(serialize($this->sha)),
            'current' => $this->current,
        ];
    }

    /** Bytes written to the part so far. */
    public function size(): int
    {
        return $this->offset;
    }

    public function entryCount(): int
    {
        return $this->entries;
    }

    public function hasOpenEntry(): bool
    {
        return $this->current !== null;
    }

    /**
     * True if the next entry should go into a new part: this part is not empty, no entry is open, and it is full or the
     * entry (of the given size, if known) would not fit.
     */
    public function needsRollover(int $partSize, ?int $nextEntrySize = null): bool
    {
        return $this->entries > 0 && $this->current === null
            && ($this->offset >= $partSize || ($nextEntrySize !== null && $this->offset + $nextEntrySize > $partSize));
    }

    /**
     * Opens a file entry; the data follows with {@see writeBlock()} and {@see endFile()}.
     *
     * @param int|null $expectedSize size of the data if known (decides on ZIP64 records); null = unknown
     * @param bool     $compress     false = store as it is (for data that is compressed already)
     *
     * @throws ZipException
     */
    public function beginFile(string $name, int $mtime, int $mode = 0100644, ?int $expectedSize = null, bool $compress = true): void
    {
        $this->assertOpen();
        $this->assertNoEntry();
        $this->assertName($name);
        if (str_ends_with($name, '/')) {
            throw new ZipException("A file name must not end with a slash (\"$name\").");
        }

        $zip64 = $this->forceZip64 || $expectedSize === null || $expectedSize >= self::ZIP64_THRESHOLD;
        $flags = ZipFormat::FLAG_DESCRIPTOR | (self::isAscii($name) ? 0 : ZipFormat::FLAG_UTF8);
        $method = $compress ? ZipFormat::METHOD_DEFLATE : ZipFormat::METHOD_STORE;
        $headerOffset = $this->offset;
        $this->emit(ZipFormat::localHeader($name, $flags, $method, $mtime, $zip64));
        $this->current = [
            'name' => $name,
            'flags' => $flags,
            'method' => $method,
            'zip64' => $zip64,
            'mtime' => $mtime,
            'mode' => $mode,
            'headerOffset' => $headerOffset,
            'crc' => base64_encode(serialize(hash_init('crc32b'))),
            'size' => 0,
            'compressed' => 0,
        ];
    }

    /**
     * One full block of the open entry.
     *
     * @throws ZipException the block does not have the size of {@see BLOCK}
     */
    public function writeBlock(string $block): void
    {
        $this->assertOpen();
        if ($this->current === null) {
            throw new ZipException('No entry is open.');
        }
        if (strlen($block) !== self::BLOCK) {
            throw new ZipException('A block must have exactly ' . self::BLOCK . ' bytes; the rest goes to endFile().');
        }
        $this->consume($block, false);
    }

    /**
     * Ends the open entry with the last, shorter block (empty if the data was an exact multiple of the block size).
     *
     * @throws ZipException
     */
    public function endFile(string $tail = ''): Entry
    {
        $this->assertOpen();
        $current = $this->current;
        if ($current === null) {
            throw new ZipException('No entry is open.');
        }
        if (strlen($tail) >= self::BLOCK) {
            throw new ZipException('The last block must be shorter than ' . self::BLOCK . ' bytes.');
        }
        $this->consume($tail, true);

        $current = $this->current;
        if ($current === null) {
            throw new ZipException('The entry vanished while it was written.');
        }
        $crcContext = unserialize(base64_decode((string) $current['crc'], true) ?: '', ['allowed_classes' => [HashContext::class]]);
        $crc = $crcContext instanceof HashContext ? (int) hexdec(hash_final($crcContext)) : 0;

        $this->emit(ZipFormat::dataDescriptor($crc, (int) $current['compressed'], (int) $current['size'], (bool) $current['zip64']));
        $this->appendDirectory(ZipFormat::centralRecord(
            (string) $current['name'],
            (int) $current['flags'],
            (int) $current['method'],
            (int) $current['mtime'],
            (int) $current['mode'],
            false,
            $crc,
            (int) $current['compressed'],
            (int) $current['size'],
            (int) $current['headerOffset'],
            (bool) $current['zip64'],
        ));
        $this->current = null;
        ++$this->entries;

        return new Entry(
            (string) $current['name'],
            (int) $current['method'],
            (int) $current['flags'],
            $crc,
            (int) $current['compressed'],
            (int) $current['size'],
            (int) $current['headerOffset'],
            (int) $current['mtime'],
            (int) $current['mode'],
            false,
        );
    }

    /**
     * An empty directory.
     */
    public function addDirectory(string $name, int $mtime, int $mode = 040755): void
    {
        $this->assertOpen();
        $this->assertNoEntry();
        $name = rtrim($name, '/') . '/';
        $this->assertName($name);

        $flags = self::isAscii($name) ? 0 : ZipFormat::FLAG_UTF8;
        $headerOffset = $this->offset;
        $this->emit(ZipFormat::localHeader($name, $flags, ZipFormat::METHOD_STORE, $mtime, false));
        $this->appendDirectory(ZipFormat::centralRecord($name, $flags, ZipFormat::METHOD_STORE, $mtime, $mode, true, 0, 0, 0, $headerOffset, false));
        ++$this->entries;
    }

    /**
     * A whole entry from a string (manifests, small files).
     */
    public function addString(string $name, string $content, int $mtime, int $mode = 0100644): Entry
    {
        $this->beginFile($name, $mtime, $mode, strlen($content));
        $length = strlen($content);
        $position = 0;
        while ($length - $position >= self::BLOCK) {
            $this->writeBlock(substr($content, $position, self::BLOCK));
            $position += self::BLOCK;
        }

        return $this->endFile(substr($content, $position));
    }

    /**
     * Finishes the part: central directory, end records, fsync, rename to its final name.
     *
     * @throws ZipException
     */
    public function close(): PartInfo
    {
        $this->assertOpen();
        $this->assertNoEntry();

        $directoryOffset = $this->offset;
        $directorySize = $this->directorySize;
        rewind($this->directory);
        while (!feof($this->directory)) {
            $chunk = fread($this->directory, self::COPY_CHUNK);
            if ($chunk === false) {
                throw new ZipException('Cannot read the central directory file.');
            }
            if ($chunk !== '') {
                $this->emit($chunk);
            }
        }
        $this->emit(ZipFormat::endRecords($this->entries, $directorySize, $directoryOffset, $this->forceZip64));

        fflush($this->part);
        if (function_exists('fsync')) {
            fsync($this->part);
        }
        fclose($this->part);
        fclose($this->directory);
        $this->finished = true;
        @unlink($this->path . '.cd');
        if (!@rename($this->path . '.part', $this->path)) {
            throw new ZipException("Cannot rename \"{$this->path}.part\" to \"{$this->path}\".");
        }

        return new PartInfo($this->path, $this->offset, hash_final($this->sha), $this->entries);
    }

    /**
     * Gives the part up and deletes its files.
     */
    public function abort(): void
    {
        if (!$this->finished) {
            fclose($this->part);
            fclose($this->directory);
            $this->finished = true;
        }
        @unlink($this->path . '.part');
        @unlink($this->path . '.cd');
    }

    public function __destruct()
    {
        if (!$this->finished) {
            // The files stay: a saved state can still continue them.
            fclose($this->part);
            fclose($this->directory);
            $this->finished = true;
        }
    }

    private function consume(string $data, bool $final): void
    {
        $current = $this->current;
        if ($current === null) {
            return;
        }

        $crc = unserialize(base64_decode((string) $current['crc'], true) ?: '', ['allowed_classes' => [HashContext::class]]);
        if (!$crc instanceof HashContext) {
            throw new ZipException('The saved CRC state is invalid.');
        }
        hash_update($crc, $data);

        if ($current['method'] === ZipFormat::METHOD_DEFLATE) {
            $packed = (string) deflate_add(deflate_init(ZLIB_ENCODING_RAW, ['level' => $this->level]) ?: throw new ZipException('Cannot start compression.'), $data, $final ? ZLIB_FINISH : ZLIB_FULL_FLUSH);
        } else {
            $packed = $data;
        }

        $current['size'] += strlen($data);
        $current['compressed'] += strlen($packed);
        if (!$current['zip64'] && ($current['size'] >= ZipFormat::MAX_32 || $current['compressed'] >= ZipFormat::MAX_32)) {
            throw new ZipException("\"{$current['name']}\" grew beyond 4 GiB while it was written, but was announced as smaller.");
        }
        $current['crc'] = base64_encode(serialize($crc));
        $this->current = $current;
        $this->emit($packed);
    }

    private function emit(string $data): void
    {
        $length = strlen($data);
        $written = 0;
        while ($written < $length) {
            $count = fwrite($this->part, $written === 0 ? $data : substr($data, $written));
            if ($count === false || $count === 0) {
                throw new ZipException("Cannot write to \"{$this->path}.part\" (disk full?).");
            }
            $written += $count;
        }
        hash_update($this->sha, $data);
        $this->offset += $length;
    }

    private function appendDirectory(string $record): void
    {
        if (fwrite($this->directory, $record) !== strlen($record)) {
            throw new ZipException("Cannot write to \"{$this->path}.cd\" (disk full?).");
        }
        $this->directorySize += strlen($record);
    }

    /**
     * @param resource   $handle
     * @param int<0,max> $size
     */
    private static function truncateTo($handle, int $size, string $label): void
    {
        $stat = fstat($handle);
        if ($stat === false || $stat['size'] < $size) {
            throw new ZipException("The $label is shorter than the saved state says.");
        }
        ftruncate($handle, $size);
        fseek($handle, 0, SEEK_END);
    }

    /**
     * @return resource
     */
    private static function open(string $file, string $mode)
    {
        $handle = @fopen($file, $mode);
        if ($handle === false) {
            throw new ZipException("Cannot open \"$file\".");
        }

        return $handle;
    }

    private function assertOpen(): void
    {
        if ($this->finished) {
            throw new ZipException('The archive part is closed.');
        }
    }

    private function assertNoEntry(): void
    {
        if ($this->current !== null) {
            throw new ZipException("The entry \"{$this->current['name']}\" is still open.");
        }
    }

    private function assertName(string $name): void
    {
        $reason = ZipFormat::unsafeReason($name);
        if ($reason !== null) {
            throw new ZipException("Cannot store \"$name\": $reason.");
        }
    }

    private static function isAscii(string $name): bool
    {
        return preg_match('/^[\x00-\x7F]*$/', $name) === 1;
    }
}
