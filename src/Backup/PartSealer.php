<?php

declare(strict_types=1);

namespace ThreadSafe\Backup;

use HashContext;
use RuntimeException;
use ThreadSafe\Crypto\AgeSealer;
use ThreadSafe\Crypto\CryptoException;
use ThreadSafe\Crypto\Recipient;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Storage\LocalStorage;

/**
 * Seals a finished ZIP part into the `age` format (`<part>.zip.age`) in ticks (docs/PHASE5.md 3.5, "In the backup"): block by block,
 * saving after the last block it wrote the position in the plain part, the position in the sealed file, the running SHA-256 of the sealed
 * file and the state of the sealer (the file key is in it, so the state is secret and lives in the 0600 file of the run). A tick that
 * dies after writing a few blocks more is cut back to the length the saved state remembers when work goes on.
 *
 * The sealed file is written as `.zip.age.part` and renamed when it is complete and its size is what the plain size and the format
 * say it must be; only then is the plain part deleted. So at most one plain part is on the disk at a time, in the work directory.
 */
final class PartSealer
{
    /** @param list<Recipient> $recipients */
    public function __construct(
        private readonly LocalStorage $storage,
        private readonly string $plainDirectory,
        private readonly array $recipients,
    ) {
    }

    /**
     * Works on one part.
     *
     * @param string                   $plain   the file name of the plain part (`<base>-p01.zip`) in the plain directory
     * @param int                      $entries the number of entries of the part, for its record
     * @param array<string, mixed>|null $job     the saved job of this part, or null to begin; updated in place
     *
     * @return PartRecord|null the record of the sealed part (its name, size and SHA-256), or null if the budget ran out first
     *
     * @throws CryptoException|RuntimeException the part cannot be sealed
     */
    public function seal(string $plain, int $entries, ?array &$job, Budget $budget): ?PartRecord
    {
        $plainPath = $this->plainDirectory . '/' . $plain;
        $sealedName = $plain . '.age';
        $partial = $this->storage->directory() . '/' . $sealedName . '.part';
        $plainSize = @filesize($plainPath);
        if ($plainSize === false) {
            throw new RuntimeException("The part \"$plain\" is missing; it cannot be encrypted.");
        }

        if ($job === null) {
            $sealer = AgeSealer::begin($this->recipients);
            $head = $sealer->head();
            $out = $this->open($partial, 0, true);
            $this->put($out, $head);
            $sha = hash_init('sha256');
            hash_update($sha, $head);
            $job = ['sealer' => $sealer->state(), 'index' => 0, 'written' => strlen($head), 'head' => strlen($head), 'sha' => base64_encode(serialize($sha))];
        } else {
            $sealer = AgeSealer::resume($job['sealer']);
            $sha = unserialize(base64_decode((string) $job['sha'], true) ?: '', ['allowed_classes' => [HashContext::class]]);
            if (!$sha instanceof HashContext) {
                throw new RuntimeException('The saved state of the encryption is not valid.');
            }
            $out = $this->open($partial, (int) $job['written'], false);
        }

        $in = @fopen($plainPath, 'rb');
        if ($in === false) {
            fclose($out);

            throw new RuntimeException("The part \"$plain\" cannot be read.");
        }
        try {
            $index = (int) $job['index'];
            $chunks = max(1, intdiv($plainSize + AgeSealer::CHUNK - 1, AgeSealer::CHUNK));
            fseek($in, $index * AgeSealer::CHUNK);
            while ($index < $chunks) {
                $block = (string) stream_get_contents($in, AgeSealer::CHUNK);
                if ($block === '' && $plainSize > 0) {
                    throw new RuntimeException("The part \"$plain\" ended early; it cannot be encrypted.");
                }
                $sealed = $sealer->seal($block, $index, $index === $chunks - 1);
                $this->put($out, $sealed);
                hash_update($sha, $sealed);
                $job['written'] += strlen($sealed);
                ++$index;
                $budget->note(sprintf('encrypting %s (%d of %d blocks)', basename($plain), $index, $chunks));
                $job['index'] = $index;
                $job['sha'] = base64_encode(serialize($sha));
                if ($index < $chunks && $budget->exhausted()) {
                    fflush($out);

                    return null;
                }
            }
        } finally {
            fclose($in);
            fclose($out);
        }

        $expected = AgeSealer::sealedSize($plainSize, (int) $job['head']);
        $size = (int) filesize($partial);
        if ($size !== $expected || $size !== (int) $job['written']) {
            throw new RuntimeException("The encrypted part \"$sealedName\" has $size bytes, but should have $expected: it is not kept.");
        }
        $target = $this->storage->directory() . '/' . $sealedName;
        if (!@rename($partial, $target)) {
            throw new RuntimeException("Cannot finish the encrypted part \"$sealedName\".");
        }
        @chmod($target, 0600);
        @unlink($plainPath);
        $job = null;

        return new PartRecord($sealedName, $size, hash_final($sha), $entries);
    }

    /**
     * Opens the sealed file for writing at the length the saved state remembers (a new one is made empty).
     *
     * @return resource
     */
    private function open(string $path, int $length, bool $fresh)
    {
        $out = @fopen($path, $fresh ? 'wb' : 'c+b');
        if ($out === false) {
            throw new RuntimeException('Cannot write "' . basename($path) . '".');
        }
        @chmod($path, 0600);
        if (!$fresh) {
            ftruncate($out, max(0, $length));
            fseek($out, max(0, $length));
        }

        return $out;
    }

    /**
     * @param resource $out
     */
    private function put($out, string $bytes): void
    {
        $length = strlen($bytes);
        $done = 0;
        while ($done < $length) {
            $n = fwrite($out, substr($bytes, $done));
            if ($n === false || $n === 0) {
                throw new RuntimeException('Cannot write the encrypted part (disk full?).');
            }
            $done += $n;
        }
    }
}
