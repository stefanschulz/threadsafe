<?php

declare(strict_types=1);

namespace ThreadSafe\Backup;

use RuntimeException;

/**
 * The list of everything a backup will pack, as a file of JSON lines: written by the scan phase, read sequentially by the
 * pack phase. A file keeps 70,000 entries out of the run state (which is saved after every tick) and out of memory.
 *
 * Both sides are driven by byte offsets that the run state stores: the writer truncates the file to the saved size when it
 * continues, the reader goes on at the saved offset.
 */
final class FileList
{
    /**
     * Appends items. The file is opened, extended and closed on every call; $size is the length the file must have before
     * (what the state remembers; anything beyond is leftover of a process that died after saving) and the new length is returned.
     *
     * @param list<ScanItem> $items
     *
     * @throws RuntimeException the file cannot be written or is shorter than the state says
     */
    public static function append(string $path, int $size, array $items): int
    {
        $handle = @fopen($path, 'c+b');
        if ($handle === false) {
            throw new RuntimeException("Cannot open the file list \"$path\".");
        }
        try {
            $stat = fstat($handle);
            if ($stat === false || $stat['size'] < $size) {
                throw new RuntimeException("The file list \"$path\" is shorter than the saved state says.");
            }
            ftruncate($handle, max(0, $size));
            fseek($handle, 0, SEEK_END);
            $text = '';
            foreach ($items as $item) {
                $text .= json_encode($item->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
            }
            if ($text !== '' && fwrite($handle, $text) !== strlen($text)) {
                throw new RuntimeException("Cannot write to the file list \"$path\" (disk full?).");
            }
            fflush($handle);

            return $size + strlen($text);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Reads up to $limit items from $offset on.
     *
     * @return array{list<ScanItem>, int} the items and the offset after the last one read
     *
     * @throws RuntimeException the file is damaged
     */
    public static function read(string $path, int $offset, int $limit): array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Cannot open the file list \"$path\".");
        }
        try {
            fseek($handle, $offset);
            $items = [];
            while (count($items) < $limit && ($line = fgets($handle)) !== false) {
                if (!str_ends_with($line, "\n")) {
                    break;   // a line that is still being written is not there yet
                }
                $row = json_decode($line, true);
                if (!is_array($row) || count($row) !== 5) {
                    throw new RuntimeException("The file list \"$path\" is damaged near offset $offset.");
                }
                $items[] = ScanItem::fromArray($row);
                $offset += strlen($line);
            }

            return [$items, $offset];
        } finally {
            fclose($handle);
        }
    }

    private function __construct()
    {
    }
}
