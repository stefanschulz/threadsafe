<?php

declare(strict_types=1);

namespace ThreadSafe\Restore;

use ThreadSafe\Archive\Entry;
use ThreadSafe\Archive\ZipException;
use ThreadSafe\Archive\ZipFormat;
use ThreadSafe\Archive\ZipReader;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Engine\TickOutcome;

/**
 * Unpacks the parts of a backup in ticks: the entries `files/<path>` into the root of the site, the entries `db/<file>.sql`
 * into a work directory (where {@see Importer} reads them), everything else (`threadsafe.json`, the part markers) is not
 * touched.
 *
 * - **Safe by construction:** the names are checked by {@see ZipReader} (no absolute paths, `..`, NUL, backslashes); on top of
 *   that no existing path inside the root that is a symbolic link is ever followed, a file never replaces a directory, and the
 *   paths in `protected` (the restore tool itself and its work directory) are never written, whatever the archive says.
 * - **Resumable:** the state holds the part, the index and the position of the next record in the central directory, so a tick
 *   does not read the records before it. A tick ends between two entries. A big *stored* entry (media files, archives) stops
 *   when the budget is used up and continues at the byte it reached, with the target cut back to that length first; a
 *   *deflated* entry cannot continue in the middle and is therefore always finished in the tick that began it, even if that
 *   takes longer than the budget (restarting it would never get further if it does not fit). Measured: the pure PHP reader
 *   inflates 15-364 MB/s, so only a single file of hundreds of MB can exceed a web request.
 * - **Exact:** every entry is checked against its CRC32 and size while it is written.
 * - Modes (without setuid, setgid and sticky) and modification times of files are restored; directories get the recorded mode
 *   but at least `u+rwx`, so that the extraction can go on; their modification times are not restored (they change with every
 *   file created in them). Ownership stays that of the PHP user.
 *
 * State: `parts`, `root`, `database`, `protected`, `part`, `index`, `position`, `current` (null, or the byte reached in the
 * stored entry in progress), `files`, `directories`, `bytes`, `ignored`, `warnings`, and `entries`/`total` for the progress.
 */
final class Extractor
{
    /**
     * @param array<string, mixed> $parameters `parts` (paths of the archive parts, in order), `root` (the site's directory),
     *                                         `database` (directory for the SQL files), `protected` (paths relative to the
     *                                         root that are never written: the tool's own files; a directory protects everything
     *                                         below it)
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
        $entries = 0;
        $total = 0;
        foreach ($parts as $path) {
            try {
                $reader = ZipReader::open($path);
                $entries += $reader->count();
                foreach ($reader->entries() as $entry) {
                    if (!$entry->isDirectory && (str_starts_with($entry->name, 'files/') || str_starts_with($entry->name, 'db/'))) {
                        $total += $entry->size;
                    }
                }
            } catch (ZipException $e) {
                throw new ExtractException('Cannot read the archive part: ' . $e->getMessage(), 0, $e);
            }
        }

        return [
            'parts' => $parts,
            'root' => rtrim((string) ($parameters['root'] ?? ''), '/\\'),
            'database' => rtrim((string) ($parameters['database'] ?? ''), '/\\'),
            'protected' => array_values(array_map(static fn (mixed $p): string => trim((string) $p, '/'), (array) ($parameters['protected'] ?? []))),
            'part' => 0,
            'index' => 0,
            'position' => 0,
            'current' => null,
            'files' => 0,
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

        $work = 0;
        while ($run->part < count($parts) && ($work === 0 || !$budget->exhausted())) {
            try {
                $reader = ZipReader::open($parts[$run->part]);
                $finishedPart = true;
                foreach ($reader->entries($run->index, $run->position) as $entry) {
                    if ($work > 0 && $budget->exhausted()) {
                        $finishedPart = false;

                        break;
                    }
                    $this->handle($reader, $entry, $run, $budget);
                    ++$work;
                    if ($run->current !== null) {
                        $finishedPart = false;   // a stored entry was stopped in the middle

                        break;
                    }
                    ++$run->index;
                    $run->position = $entry->nextRecord;
                }
            } catch (ZipException $e) {
                throw new ExtractException($run->describe('Cannot extract') . ': ' . $e->getMessage() . ' The target is in a partial state.', 0, $e);
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

        $this->file($reader, $entry, $run, $target, $budget);
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
    private function file(ZipReader $reader, Entry $entry, ExtractRun $run, string $target, Budget $budget): void
    {
        if (is_link($target) || is_dir($target)) {
            throw new ExtractException($run->describe('Cannot write') . ': the target is ' . (is_link($target) ? 'a symbolic link' : 'a directory') . ', not a file. The target is in a partial state.');
        }
        $stored = $entry->method === ZipFormat::METHOD_STORE;
        $from = $stored ? (int) ($run->current ?? 0) : 0;
        if (file_exists($target) && !is_writable($target)) {
            @chmod($target, 0644);
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
        $mode = $entry->mode !== null && ($entry->mode & 0170000) === 0100000 ? $entry->mode & 0777 : 0644;
        if (!@chmod($target, $mode)) {
            $run->warn("Cannot set the permissions of /{$run->relative($target)}");
        }
        if ($entry->mtime > 0 && !@touch($target, $entry->mtime)) {
            $run->warn("Cannot set the modification time of /{$run->relative($target)}");
        }
        ++$run->files;
    }

    private function crc(string $path): int
    {
        return (int) hexdec((string) hash_file('crc32b', $path));
    }
}
