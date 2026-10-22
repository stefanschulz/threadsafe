<?php

declare(strict_types=1);

namespace ThreadSafe\Archive;

use Generator;

/**
 * Reads a ZIP archive part with bounded memory and without believing it.
 *
 * Understands what {@see ZipWriter} writes and what common tools produce: stored and deflated entries, ZIP64 records,
 * data descriptors, UTF-8 names, Unix permissions. Refuses what it cannot or must not handle: encrypted entries, other
 * methods, several disks, and names that would write outside the target directory ({@see ZipFormat::unsafeReason()}).
 * Sizes in the central directory are the truth; claims that cannot be right (a compressed size beyond the file, an expansion
 * beyond what deflate can do) are refused before anything is read, and a stream that expands past its declared size is
 * stopped.
 */
final class ZipReader
{
    private const int TAIL = 65557;

    private const int CHUNK = 16384;

    /** @var resource */
    private $handle;

    private function __construct(
        private readonly string $path,
        private readonly int $fileSize,
        private readonly int $entryCount,
        private readonly int $directoryOffset,
        private readonly int $directorySize,
    ) {
        $this->handle = self::openFile($path);
    }

    public function __destruct()
    {
        fclose($this->handle);
    }

    /**
     * @throws ZipException the file is not a usable ZIP archive
     */
    public static function open(string $path): self
    {
        $handle = self::openFile($path);
        $size = (int) (fstat($handle)['size'] ?? 0);
        if ($size < 22) {
            fclose($handle);
            throw new ZipException("\"$path\" is too small to be a ZIP archive.");
        }

        $tailLength = min($size, self::TAIL);
        fseek($handle, $size - $tailLength);
        $tail = (string) fread($handle, $tailLength);
        $signature = pack('V', ZipFormat::END_RECORD);
        $position = false;
        for ($candidate = strlen($tail) - 22; $candidate >= 0; --$candidate) {
            if (substr($tail, $candidate, 4) === $signature && $candidate + 22 + (int) (unpack('v', $tail, $candidate + 20)[1] ?? -1) === strlen($tail)) {
                $position = $candidate;
                break;
            }
        }
        if ($position === false) {
            fclose($handle);
            throw new ZipException("\"$path\" has no end record: not a ZIP archive, or cut off.");
        }

        $end = unpack('Vsig/vdisk/vcdDisk/ventriesDisk/ventries/VcdSize/VcdOffset/vcomment', $tail, $position);
        if ($end === false) {
            fclose($handle);
            throw new ZipException("\"$path\": the end record is damaged.");
        }
        if ($end['disk'] !== 0 || $end['cdDisk'] !== 0) {
            fclose($handle);
            throw new ZipException("\"$path\" is part of a multi-disk archive; ThreadSafe parts are single files.");
        }

        $entries = $end['entries'];
        $directorySize = $end['cdSize'];
        $directoryOffset = $end['cdOffset'];
        if ($entries === ZipFormat::MAX_16 || $directorySize === ZipFormat::MAX_32 || $directoryOffset === ZipFormat::MAX_32) {
            $locatorAt = $size - $tailLength + $position - 20;
            fseek($handle, $locatorAt);
            $locator = $locatorAt >= 0 ? unpack('Vsig/Vdisk/Poffset/Vdisks', (string) fread($handle, 20)) : false;
            if ($locator === false || $locator['sig'] !== ZipFormat::END_LOCATOR64 || $locator['offset'] < 0 || $locator['offset'] > $size - 56) {
                fclose($handle);
                throw new ZipException("\"$path\": the ZIP64 end record is missing or damaged.");
            }
            fseek($handle, $locator['offset']);
            $end64 = unpack('Vsig/Psize/vmadeBy/vneeded/Vdisk/VcdDisk/PentriesDisk/Pentries/PcdSize/PcdOffset', (string) fread($handle, 56));
            if ($end64 === false || $end64['sig'] !== ZipFormat::END_RECORD64) {
                fclose($handle);
                throw new ZipException("\"$path\": the ZIP64 end record is damaged.");
            }
            [$entries, $directorySize, $directoryOffset] = [$end64['entries'], $end64['cdSize'], $end64['cdOffset']];
        }

        fclose($handle);
        if ($entries < 0 || $directorySize < 0 || $directoryOffset < 0 || $directoryOffset + $directorySize > $size) {
            throw new ZipException("\"$path\": the central directory lies outside the file.");
        }
        if ($entries * 46 > $directorySize) {
            throw new ZipException("\"$path\": the central directory is too small for $entries entries.");
        }

        return new self($path, $size, $entries, $directoryOffset, $directorySize);
    }

    public function count(): int
    {
        return $this->entryCount;
    }

    /**
     * All entries in central directory order.
     *
     * A walk can be resumed: with the number and the {@see Entry::$nextRecord} of the last entry seen, the next call starts at
     * the following record without reading the ones before.
     *
     * @param int $from     index of the first record
     * @param int $position where that record begins, counted from the start of the central directory
     *
     * @return Generator<int, Entry>
     *
     * @throws ZipException a record is damaged, or the position is not inside the directory
     */
    public function entries(int $from = 0, int $position = 0): Generator
    {
        if ($from < 0 || $position < 0 || $position > $this->directorySize || ($from === 0 && $position !== 0)) {
            throw new ZipException("\"{$this->path}\": cannot continue the walk through the central directory there.");
        }
        $directory = self::openFile($this->path);
        try {
            fseek($directory, $this->directoryOffset + $position);
            $consumed = $position;
            for ($index = $from; $index < $this->entryCount; ++$index) {
                $fixed = (string) fread($directory, 46);
                $r = strlen($fixed) === 46 ? unpack('Vsig/vmadeBy/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vsize/vnameLength/vextraLength/vcommentLength/vdisk/vinternal/Vexternal/Voffset', $fixed) : false;
                if ($r === false || $r['sig'] !== ZipFormat::CENTRAL_HEADER) {
                    throw new ZipException("\"{$this->path}\": central directory record " . ($index + 1) . ' is damaged.');
                }
                $variable = $r['nameLength'] + $r['extraLength'] + $r['commentLength'];
                $consumed += 46 + $variable;
                if ($consumed > $this->directorySize) {
                    throw new ZipException("\"{$this->path}\": the central directory is longer than its size says.");
                }
                $rest = $variable > 0 ? (string) fread($directory, $variable) : '';
                if (strlen($rest) !== $variable) {
                    throw new ZipException("\"{$this->path}\": central directory record " . ($index + 1) . ' is cut off.');
                }

                yield $index => $this->entryFrom($r, substr($rest, 0, $r['nameLength']), substr($rest, $r['nameLength'], $r['extraLength']), $consumed);
            }
        } finally {
            fclose($directory);
        }
    }

    public function find(string $name): ?Entry
    {
        foreach ($this->entries() as $entry) {
            if ($entry->name === $name) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Streams the content of an entry to $sink in chunks, checking sizes and CRC32 on the way.
     *
     * The sink may return `false` to stop after the chunk it just got (anything else, including nothing, goes on). A stored entry
     * can then be continued later by passing the number of bytes already delivered as $from; the CRC32 is not checked for such a
     * partial read (the caller checks the whole content), the size still is.
     *
     * @param callable(string): mixed $sink
     * @param int                     $from bytes of a stored entry to skip; must be 0 for a deflated one
     *
     * @return bool true if the whole entry was delivered and checked, false if the sink stopped it
     *
     * @throws ZipException the entry is damaged, unsafe, unsupported, or expands beyond its declared size
     */
    public function extract(Entry $entry, callable $sink, int $from = 0): bool
    {
        $this->assertReadable($entry);
        if ($entry->isDirectory) {
            return true;
        }
        if ($from < 0 || $from > $entry->size || ($from > 0 && $entry->method !== ZipFormat::METHOD_STORE)) {
            throw new ZipException("\"{$entry->name}\": cannot continue at byte $from.");
        }

        $dataOffset = $this->dataOffset($entry);
        fseek($this->handle, $dataOffset + $from);
        $crc = hash_init('crc32b');
        $produced = $from;
        $remaining = $entry->compressedSize - $from;
        $inflater = $entry->method === ZipFormat::METHOD_DEFLATE ? inflate_init(ZLIB_ENCODING_RAW) : false;
        if ($entry->method === ZipFormat::METHOD_DEFLATE && $inflater === false) {
            throw new ZipException('Cannot start decompression.');
        }

        while ($remaining > 0) {
            $chunk = fread($this->handle, min(self::CHUNK, $remaining));
            if ($chunk === false || $chunk === '') {
                throw new ZipException("\"{$entry->name}\": the data is cut off.");
            }
            $remaining -= strlen($chunk);
            if ($inflater !== false) {
                $out = @inflate_add($inflater, $chunk);
                if ($out === false) {
                    throw new ZipException("\"{$entry->name}\": the compressed data is damaged.");
                }
            } else {
                $out = $chunk;
            }
            $produced += strlen($out);
            if ($produced > $entry->size) {
                throw new ZipException("\"{$entry->name}\": the data expands beyond its declared size of {$entry->size} bytes.");
            }
            if ($out !== '') {
                hash_update($crc, $out);
                if ($sink($out) === false) {
                    return false;
                }
            }
        }
        if ($inflater !== false && inflate_get_status($inflater) !== ZLIB_STREAM_END) {
            throw new ZipException("\"{$entry->name}\": the compressed data ends too early.");
        }
        if ($produced !== $entry->size) {
            throw new ZipException("\"{$entry->name}\": the data has $produced bytes, the directory says {$entry->size}.");
        }
        if ($from === 0 && (int) hexdec(hash_final($crc)) !== $entry->crc32) {
            throw new ZipException("\"{$entry->name}\": the CRC32 does not match (the data is damaged).");
        }

        return true;
    }

    /**
     * The content of a small entry.
     *
     * @throws ZipException as {@see extract()}, or the entry is larger than $maxSize
     */
    public function contents(Entry $entry, int $maxSize = 16777216): string
    {
        if ($entry->size > $maxSize) {
            throw new ZipException("\"{$entry->name}\" has {$entry->size} bytes, more than the allowed $maxSize.");
        }
        $content = '';
        $this->extract($entry, static function (string $chunk) use (&$content): void {
            $content .= $chunk;
        });

        return $content;
    }

    /**
     * Reads the whole part: the structure, every entry's data against its CRC32 and size, the names, the layout.
     * Collects the problems instead of stopping at the first.
     */
    public function verify(): VerifyResult
    {
        $problems = [];
        $bytes = 0;
        $count = 0;
        $ranges = [];

        try {
            foreach ($this->entries() as $entry) {
                ++$count;
                $reason = ZipFormat::unsafeReason($entry->name);
                if ($reason !== null) {
                    $problems[] = "\"{$entry->name}\": $reason";
                    continue;
                }
                try {
                    $this->extract($entry, static function (string $chunk) use (&$bytes): void {
                        $bytes += strlen($chunk);
                    });
                    if (!$entry->isDirectory) {
                        $ranges[] = [$entry->localOffset, $this->dataOffset($entry) + $entry->compressedSize, $entry->name];
                    }
                } catch (ZipException $e) {
                    $problems[] = $e->getMessage();
                }
            }
        } catch (ZipException $e) {
            $problems[] = $e->getMessage();
        }

        usort($ranges, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        for ($i = 1; $i < count($ranges); ++$i) {
            if ($ranges[$i][0] < $ranges[$i - 1][1]) {
                $problems[] = "\"{$ranges[$i][2]}\" overlaps \"{$ranges[$i - 1][2]}\" in the file.";
            }
        }
        if ($count !== $this->entryCount) {
            $problems[] = "The directory announces {$this->entryCount} entries but holds $count.";
        }

        return new VerifyResult($count, $bytes, $problems);
    }

    /**
     * @param array<string, int> $r
     */
    private function entryFrom(array $r, string $name, string $extra, int $nextRecord): Entry
    {
        $fields = ZipFormat::parseExtra($extra);
        $size = $r['size'];
        $compressed = $r['compressed'];
        $offset = $r['offset'];
        if (isset($fields[ZipFormat::EXTRA_ZIP64])) {
            $zip64 = $fields[ZipFormat::EXTRA_ZIP64];
            $position = 0;
            $take = function (int $value) use ($zip64, &$position, $name): int {
                if ($value !== ZipFormat::MAX_32) {
                    return $value;
                }
                $unpacked = strlen($zip64) >= $position + 8 ? unpack('P', $zip64, $position) : false;
                $big = $unpacked === false ? -1 : (int) $unpacked[1];
                if ($big < 0) {
                    throw new ZipException("\"{$this->path}\": the ZIP64 field of \"" . self::printable($name) . '" is damaged.');
                }
                $position += 8;

                return $big;
            };
            $size = $take($size);
            $compressed = $take($compressed);
            $offset = $take($offset);
        }
        if ($size === ZipFormat::MAX_32 || $compressed === ZipFormat::MAX_32 || $offset === ZipFormat::MAX_32) {
            throw new ZipException("\"{$this->path}\": \"" . self::printable($name) . '" needs ZIP64 values that are missing.');
        }

        $timestamp = $fields[ZipFormat::EXTRA_TIMESTAMP] ?? '';
        $unpacked = strlen($timestamp) >= 5 && (ord($timestamp[0]) & 1) === 1 ? unpack('V', $timestamp, 1) : false;
        $mtime = $unpacked !== false ? (int) $unpacked[1] : ZipFormat::unixFromDos($r['time'], $r['date']);
        $mode = ($r['madeBy'] >> 8) === 3 ? ($r['external'] >> 16) & 0xFFFF : null;

        return new Entry(
            $name,
            $r['method'],
            $r['flags'],
            $r['crc'],
            $compressed,
            $size,
            $offset,
            $mtime,
            $mode,
            str_ends_with($name, '/') || ($r['external'] & 0x10) !== 0,
            $nextRecord,
        );
    }

    /**
     * @throws ZipException
     */
    private function assertReadable(Entry $entry): void
    {
        $reason = ZipFormat::unsafeReason($entry->name);
        if ($reason !== null) {
            throw new ZipException('Refusing "' . self::printable($entry->name) . "\": $reason.");
        }
        if ($entry->isEncrypted()) {
            throw new ZipException("\"{$entry->name}\" is encrypted; ZIP encryption is not supported.");
        }
        if ($entry->method !== ZipFormat::METHOD_STORE && $entry->method !== ZipFormat::METHOD_DEFLATE) {
            throw new ZipException("\"{$entry->name}\" uses compression method {$entry->method}, which is not supported.");
        }
        if ($entry->isDirectory) {
            return;
        }
        if ($entry->localOffset >= $this->directoryOffset || $entry->compressedSize > $this->fileSize) {
            throw new ZipException("\"{$entry->name}\": its data lies outside the file.");
        }
        if ($entry->method === ZipFormat::METHOD_STORE && $entry->size !== $entry->compressedSize) {
            throw new ZipException("\"{$entry->name}\": stored sizes differ.");
        }
        if ($entry->method === ZipFormat::METHOD_DEFLATE && $entry->size > $entry->compressedSize * ZipFormat::MAX_EXPANSION + 1024) {
            throw new ZipException("\"{$entry->name}\" claims {$entry->size} bytes from {$entry->compressedSize} compressed bytes: more than deflate can do.");
        }
    }

    /**
     * Where the data of an entry starts, after checking its local header against the central directory.
     *
     * @throws ZipException
     */
    public function dataOffset(Entry $entry): int
    {
        fseek($this->handle, $entry->localOffset);
        $header = (string) fread($this->handle, 30);
        $local = strlen($header) === 30 ? unpack('Vsig/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vsize/vnameLength/vextraLength', $header) : false;
        if ($local === false || $local['sig'] !== ZipFormat::LOCAL_HEADER) {
            throw new ZipException("\"{$entry->name}\": its local header is missing or damaged.");
        }
        if ((string) fread($this->handle, $local['nameLength']) !== $entry->name) {
            throw new ZipException("\"{$entry->name}\": the local header names another file.");
        }
        $offset = $entry->localOffset + 30 + $local['nameLength'] + $local['extraLength'];
        if ($offset + $entry->compressedSize > $this->directoryOffset) {
            throw new ZipException("\"{$entry->name}\": its data runs into the central directory.");
        }

        return $offset;
    }

    /**
     * @return resource
     */
    private static function openFile(string $path)
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new ZipException("Cannot open \"$path\".");
        }
        if (stream_get_meta_data($handle)['wrapper_type'] !== 'plainfile') {
            // a stream of a wrapper (an encrypted part read through its decrypting stream) hands out at most one chunk, 8 KiB by default,
            // per fread(); the tail of an archive is read in one piece
            stream_set_chunk_size($handle, 1048576);
        }

        return $handle;
    }

    private static function printable(string $name): string
    {
        return addcslashes(mb_strcut($name, 0, 120), "\0..\37\177");
    }
}
