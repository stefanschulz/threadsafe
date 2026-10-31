<?php

declare(strict_types=1);

namespace ThreadSafe\Restore;

use ThreadSafe\Archive\Entry;
use ThreadSafe\Archive\ZipException;
use ThreadSafe\Archive\ZipFormat;
use ThreadSafe\Archive\ZipReader;
use ThreadSafe\Crypto\CryptoException;
use ThreadSafe\Crypto\Unlock;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Engine\TickOutcome;

/**
 * Unpacks the parts of a backup in ticks: `files/<path>` into the root of the site, `db/<file>.sql` into a work directory (where
 * {@see Importer} reads them); everything else (`threadsafe.json`, the part markers) is left alone.
 *
 * - **Safe by construction:** {@see ZipReader} checks the names (no absolute paths, `..`, NUL, backslashes); in addition no existing
 *   symbolic link inside the root is ever followed, a file never replaces a directory, and the paths in `protected` (the restore tool and
 *   its work directory) are never written, whatever the archive says.
 * - **Resumable:** a tick ends between two entries; the state holds the part, the index and the position of the next central-directory
 *   record. A big *stored* entry continues at the byte it reached (the target is cut back to that length first); a *deflated* entry cannot
 *   continue in the middle, so it is finished in the tick that began it even if that exceeds the budget (restarting it would never get
 *   further). The pure PHP reader inflates 15-364 MB/s, so only a single file of hundreds of MB can exceed a web request.
 * - **Exact:** every entry is checked against its CRC32 and size while it is written. Modes (without setuid, setgid and sticky) and
 *   modification times of files are restored; directories get the recorded mode but at least `u+rwx` and no modification time (it changes
 *   with every file created in them). Ownership stays that of the PHP user.
 */
final class Extractor
{
    /**
     * @param array<string, mixed> $parameters `parts` (paths of the archive parts, in order), `root` (the site's directory),
     *                                         `database` (directory for the SQL files), `protected` (paths relative to the
     *                                         root that are never written: the tool's own files; a directory protects everything
     *                                         below it), `guard` (paths of the tool's files below the root that a restored
     *                                         `.htaccess` must not lock out: see {@see ToolGuard})
     *
     * @return array<string, mixed>
     *
     * @throws ExtractException a part cannot be read as a ZIP archive
     */
    public function initialState(array $parameters): array
    {
        /** @var list<string> $parts */
        $parts = array_values(array_map('strval', (array) ($parameters['parts'] ?? [])));
        if ($parts === []) {
            throw new ExtractException('There is no archive part to extract.');
        }
        try {
            $unlock = Unlock::fromArray($parameters['unlock'] ?? null);
        } catch (CryptoException $e) {
            throw new ExtractException($e->getMessage(), 0, $e);
        }
        $entries = 0;
        $total = 0;
        foreach ($parts as $path) {
            try {
                $reader = Parts::open($path, $unlock);
                $entries += $reader->count();
                foreach ($reader->entries() as $entry) {
                    if (!$entry->isDirectory && (str_starts_with($entry->name, 'files/') || str_starts_with($entry->name, 'db/'))) {
                        $total += $entry->size;
                    }
                }
            } catch (ZipException | CryptoException $e) {
                throw new ExtractException('Cannot read the archive part: ' . $e->getMessage(), 0, $e);
            } finally {
                Parts::releaseAll();
            }
        }

        return [
            'parts' => $parts,
            'unlock' => $unlock?->toArray(),
            'root' => rtrim((string) ($parameters['root'] ?? ''), '/\\'),
            'database' => rtrim((string) ($parameters['database'] ?? ''), '/\\'),
            'protected' => array_values(array_map(static fn (mixed $p): string => trim((string) $p, '/'), (array) ($parameters['protected'] ?? []))),
            'guard' => array_values(array_map(static fn (mixed $p): string => trim((string) $p, '/'), (array) ($parameters['guard'] ?? []))),
            'part' => 0,
            'index' => 0,
            'position' => 0,
            'current' => null,
            'files' => 0,
            'databaseFiles' => 0,
            'directories' => 0,
            'bytes' => 0,
            'ignored' => 0,
            'warnings' => [],
            'entries' => $entries,
            'total' => $total,
        ];
    }

    /**
     * @param array<string, mixed> $state
     *
     * @throws ExtractException
     */
    public function tick(array $state, Budget $budget): TickOutcome
    {
        /** @var list<string> $parts */
        $parts = $state['parts'];
        $run = new ExtractRun($state);
        try {
            $unlock = Unlock::fromArray($state['unlock'] ?? null);
        } catch (CryptoException $e) {
            throw new ExtractException($e->getMessage(), 0, $e);
        }

        $work = 0;
        while ($run->part < count($parts) && ($work === 0 || !$budget->exhausted())) {
            try {
                $reader = Parts::open($parts[$run->part], $unlock);
                $finishedPart = true;
                foreach ($reader->entries($run->index, $run->position) as $entry) {
                    if ($work > 0 && $budget->exhausted()) {
                        $finishedPart = false;

                        break;
                    }
                    $this->handle($reader, $entry, $run, $budget);
                    ++$work;
                    $budget->note(sprintf('unpacking the backup: %d files so far, part %d of %d, now %s', $run->files, $run->part + 1, count($parts), $entry->name));
                    if ($run->current !== null) {
                        $finishedPart = false;   // a stored entry was stopped in the middle

                        break;
                    }
                    ++$run->index;
                    $run->position = $entry->nextRecord;
                }
            } catch (ZipException | CryptoException $e) {
                throw new ExtractException($run->describe('Cannot extract') . ': ' . $e->getMessage() . ' The target is in a partial state.', 0, $e);
            } finally {
                Parts::releaseAll();
            }
            if ($finishedPart) {
                ++$run->part;
                $run->index = 0;
                $run->position = 0;
            }
        }

        $finished = $run->part >= count($parts);
        $outcome = $run->toState($state);

        return new TickOutcome($outcome, $finished, $finished
            ? sprintf('extracted %d files and %d directories (%d bytes)', $run->files, $run->directories, $run->bytes)
            : sprintf('extracting files (part %d of %d, %d%%)', $run->part + 1, count($parts), (int) floor(100 * $run->bytes / max(1, (int) $state['total']))));
    }

    /**
     * @throws ExtractException
     * @throws ZipException
     */
    private function handle(ZipReader $reader, Entry $entry, ExtractRun $run, Budget $budget): void
    {
        $name = $entry->name;
        $run->entry = $name;
        // before anything touches the file system: a name the reader would refuse must not leave even an empty file behind
        $reason = ZipFormat::unsafeReason($name);
        if ($reason !== null) {
            throw new ExtractException($run->describe('Refusing') . ": $reason. The target is in a partial state.");
        }
        if (str_starts_with($name, 'files/')) {
            $relative = rtrim(substr($name, 6), '/');
            if ($relative === '') {
                return;     // the directory "files/" itself
            }
            if ($run->isProtected($relative)) {
                $run->warn("Not restored, the path belongs to the restore tool: /$relative");
                ++$run->ignored;

                return;
            }
            $target = $run->root . '/' . $relative;
            if ($entry->isDirectory) {
                $this->directory($run, $relative, $entry);

                return;
            }
            $run->ensureDirectory(dirname($relative) === '.' ? '' : dirname($relative));
        } elseif (preg_match('~^db/[A-Za-z0-9_.\-]+$~', $name) === 1 && !$entry->isDirectory) {
            $target = $run->database . '/' . substr($name, 3);
            if (!is_dir($run->database) && !@mkdir($run->database, 0700, true) && !is_dir($run->database)) {
                throw new ExtractException("Cannot create the directory \"{$run->database}\".");
            }
        } else {
            if ($name !== 'threadsafe.json' && $name !== 'threadsafe-part.json') {
                ++$run->ignored;
            }

            return;
        }

        $this->file($reader, $entry, $run, $target, $budget, str_starts_with($name, 'db/'));
        // the .htaccess of the site is in place now and may refuse the tool's own page: from this moment the tool is let through
        if ($run->current === null && $run->guard !== [] && $target === $run->root . '/.htaccess') {
            ToolGuard::protect($run->root, $run->guard);
        }
    }

    private function directory(ExtractRun $run, string $relative, Entry $entry): void
    {
        $run->ensureDirectory($relative);
        if ($entry->mode !== null && ($entry->mode & 0170000) === 0040000) {
            @chmod($run->root . '/' . $relative, ($entry->mode & 0777) | 0700);
        }
        ++$run->directories;
    }

    /**
     * @throws ExtractException
     * @throws ZipException
     */
    private function file(ZipReader $reader, Entry $entry, ExtractRun $run, string $target, Budget $budget, bool $isDump): void
    {
        if (is_link($target) || is_dir($target)) {
            throw new ExtractException($run->describe('Cannot write') . ': the target is ' . (is_link($target) ? 'a symbolic link' : 'a directory') . ', not a file. The target is in a partial state.');
        }
        $stored = $entry->method === ZipFormat::METHOD_STORE;
        $from = $stored ? (int) ($run->current ?? 0) : 0;
        if (file_exists($target) && !is_writable($target)) {
            @chmod($target, 0644);
            clearstatcache(true, $target);
            if (!is_writable($target)) {
                // a file of somebody else (a placeholder the host or an upload left, owned by another user) cannot be opened, but it can be
                // replaced: whoever owns the directory may delete what is in it
                @unlink($target);
            }
        }
        $handle = @fopen($target, 'cb');
        if ($handle === false) {
            throw new ExtractException($run->describe('Cannot write') . ': the file cannot be opened for writing. The target is in a partial state.');
        }
        try {
            $stat = fstat($handle);
            if ($from > 0 && ($stat === false || $stat['size'] < $from)) {
                $from = 0;      // the file is shorter than the state says: start again
            }
            ftruncate($handle, max(0, $from));
            fseek($handle, $from);

            $written = $from;
            $complete = $reader->extract($entry, static function (string $chunk) use ($handle, &$written, $run, $budget, $stored, $entry): bool {
                $length = strlen($chunk);
                if (fwrite($handle, $chunk) !== $length) {
                    throw new ExtractException($run->describe('Cannot write') . ': the disk is full or the file cannot be written. The target is in a partial state.');
                }
                $written += $length;
                $run->bytes += $length;

                return !($stored && $written < $entry->size && $budget->exhausted());
            }, $from);
        } finally {
            fclose($handle);
        }
        if (!$complete) {
            $run->current = $written;     // stopped at the budget: continues at this byte

            return;
        }

        if ($from > 0 && $this->crc($target) !== $entry->crc32) {
            throw new ExtractException($run->describe('Cannot extract') . ': the CRC32 does not match (the data is damaged). The target is in a partial state.');
        }
        $run->current = null;
        $run->adopt($target);
        $mode = $entry->mode !== null && ($entry->mode & 0170000) === 0100000 ? $entry->mode & 0777 : 0644;
        if (!@chmod($target, $mode)) {
            $run->warn("Cannot set the permissions of /{$run->relative($target)}");
        }
        if ($entry->mtime > 0 && !@touch($target, $entry->mtime)) {
            $run->warn("Cannot set the modification time of /{$run->relative($target)}");
        }
        $isDump ? ++$run->databaseFiles : ++$run->files;
    }

    private function crc(string $path): int
    {
        return (int) hexdec((string) hash_file('crc32b', $path));
    }
}
