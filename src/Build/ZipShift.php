<?php

declare(strict_types=1);

namespace ThreadSafe\Build;

use RuntimeException;

/**
 * Moves the offsets of a ZIP archive by the length of what is put in front of it, as self-extracting archives do, so that the file
 * (`install.php`: a PHP stub, the installer's code, then the archive) is itself a ZIP archive that `unzip` and our `ZipReader` read
 * without a warning (docs/PHASE7.md section 8, finding 2). The offset of every local header (in the central directory) and the offset
 * of the central directory (in the end record) grow by $by.
 *
 * Only archives without ZIP64 records are handled: a release package is far below 4 GB.
 */
final class ZipShift
{
    /**
     * @throws RuntimeException the archive has no end record, uses ZIP64, or its central directory is damaged
     */
    public static function shift(string $zip, int $by): string
    {
        $end = strrpos($zip, "PK\x05\x06");
        if ($end === false || strlen($zip) < $end + 22) {
            throw new RuntimeException('The archive has no end record.');
        }
        if (str_contains($zip, "PK\x06\x07")) {
            throw new RuntimeException('The archive uses ZIP64; it is too large for an installer.');
        }
        /** @var array{1: int} $entries */
        $entries = unpack('v', substr($zip, $end + 10, 2));
        /** @var array{1: int} $directory */
        $directory = unpack('V', substr($zip, $end + 16, 4));
        $position = $directory[1];
        for ($i = 0; $i < $entries[1]; ++$i) {
            if (substr($zip, $position, 4) !== "PK\x01\x02") {
                throw new RuntimeException("The central directory is damaged at record $i.");
            }
            /** @var array{1: int} $local */
            $local = unpack('V', substr($zip, $position + 42, 4));
            $zip = substr_replace($zip, pack('V', $local[1] + $by), $position + 42, 4);
            /** @var array{1: int, 2: int, 3: int} $lengths */
            $lengths = unpack('v3', substr($zip, $position + 28, 6));
            $position += 46 + $lengths[1] + $lengths[2] + $lengths[3];
        }

        return substr_replace($zip, pack('V', $directory[1] + $by), $end + 16, 4);
    }

    private function __construct()
    {
    }
}
