<?php

declare(strict_types=1);

namespace ThreadSafe\Backup;

use RuntimeException;
use ThreadSafe\Archive\Compression;
use ThreadSafe\Archive\PartInfo;
use ThreadSafe\Archive\ZipWriter;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Storage\LocalStorage;

/**
 * Puts the files of the site and the dump files into the archive parts, in steps that can span PHP processes.
 *
 * Layout of the archive: every part starts with `threadsafe-part.json`; then come `files/<path>` (directories as entries of
 * their own, in the order of the scan), then `db/<file>.sql`; the last part ends with `threadsafe.json`. A new part starts
 * when `partSize` is set and the next entry would not fit; an entry is never split, so a file larger than the part size gets
 * a part of its own.
 *
 * A file is handed to the writer in blocks of {@see ZipWriter::BLOCK}; a tick may end between two blocks, and the next one
 * opens the source again at the offset the state remembers. The tail of the work is therefore always at a block boundary and
 * the archive is the same however the ticks were cut.
 *
 * **Encrypted backups** (`$plainDirectory` given): the parts are written to that directory (the work directory, closed to the web like
 * the storage) under their plain names, the manifest names the parts by their sealed names (`.zip.age`), and `pack()` returns each time a
 * part has been closed, so that the caller can seal it and delete the plain part before the next one grows: at most one plain part is on
 * the disk at a time.
 *
 * Trouble with single files is never fatal: a file that cannot be opened is skipped, one that disappears or shrinks while it
 * is read is stored as far as it was read; each case is a warning in the state.
 */
final class ArchivePacker
{
    public function __construct(
        private readonly LocalStorage $storage,
        private readonly BackupName $name,
        private readonly string $root,
        private readonly string $workDirectory,
        private readonly int $level,
        private readonly ?int $partSize,
        private readonly ?string $plainDirectory = null,
    ) {
    }

    /**
     * Packs until everything is in or the budget is used up (at least one block or one entry is handled per call).
     *
     * @param list<array{file: string, bytes: int}> $databaseFiles the dump files in the order they go into the archive
     *
     * @return bool true when all entries are packed (only the manifest is missing)
     *
     * @throws RuntimeException the archive cannot be written
     */
    public function pack(PackState $s, Budget $budget, array $databaseFiles): bool
    {
        $writer = $this->openWriter($s);
        $worked = false;
        while (true) {
            if ($worked && $budget->exhausted()) {
                $s->writer = $writer->state();

                return false;
            }
            $worked = true;

            if ($s->open !== null) {
                $this->continueEntry($s, $writer, $budget);
                continue;
            }
            if ($s->stage === PackState::END) {
                $s->writer = $writer->state();

                return true;
            }

            if ($s->stage === PackState::FILES) {
                [$items, $offset] = FileList::read($this->workDirectory . '/files.jsonl', $s->listOffset, 1);
                if ($items === []) {
                    $s->stage = PackState::DATABASE;
                    continue;
                }
                $closedBefore = count($s->closed);
                $writer = $this->beginItem($s, $writer, $items[0]);
                $s->listOffset = $offset;
                if ($this->plainDirectory !== null && count($s->closed) > $closedBefore) {
                    $s->writer = $writer->state();

                    return false;   // a part is closed: it is sealed before more is written
                }
                continue;
            }

            $file = $databaseFiles[$s->databaseIndex] ?? null;
            if ($file === null) {
                $s->stage = PackState::END;
                continue;
            }
            $path = $this->workDirectory . '/db/' . $file['file'];
            $size = @filesize($path);
            if ($size === false) {
                throw new RuntimeException("The dump file \"{$file['file']}\" is missing.");
            }
            $closedBefore = count($s->closed);
            $writer = $this->beginItem($s, $writer, new ScanItem('db/' . $file['file'], false, $size, (int) @filemtime($path), 0100600), database: true);
            ++$s->databaseIndex;
            if ($this->plainDirectory !== null && count($s->closed) > $closedBefore) {
                $s->writer = $writer->state();

                return false;
            }
        }
    }

    /**
     * Adds the manifest to the last part and closes it.
     *
     * @param Manifest $manifest everything but the list of parts, which is only known here
     *
     * @return list<PartRecord> all parts of the backup, in order
     */
    public function finish(PackState $s, Manifest $manifest): array
    {
        $writer = $this->openWriter($s);
        $last = $this->name->partFile($s->part, $this->partSize !== null);
        $listed = fn (string $file): string => $this->plainDirectory !== null ? $file . '.age' : $file;
        $parts = [...array_map(static fn (array $p): array => ['file' => $listed($p['file']), 'entries' => $p['entries']], $s->closed), ['file' => $listed($last), 'entries' => $writer->entryCount() + 1]];
        $writer->addString(Manifest::ENTRY, $manifest->withParts($parts)->toJson(), time(), 0100600);
        $this->recordClosed($s, $writer->close());
        $s->writer = null;

        return array_map(static fn (array $p): PartRecord => new PartRecord($p['file'], $p['size'], $p['sha256'], $p['entries']), $s->closed);
    }

    /** Deletes the unfinished part files of an abandoned run. */
    public function abort(PackState $s): void
    {
        if ($s->writer !== null) {
            try {
                ZipWriter::resume($s->writer)->abort();
            } catch (\Throwable) {
                // nothing left to delete
            }
            $s->writer = null;
        }
    }

    private function openWriter(PackState $s): ZipWriter
    {
        if ($s->writer !== null) {
            return ZipWriter::resume($s->writer);
        }

        return $this->newPart($s);
    }

    /** Starts part number `$s->part`, with its marker. */
    private function newPart(PackState $s): ZipWriter
    {
        $split = $this->partSize !== null;
        $path = $this->plainDirectory !== null ? $this->plainDirectory . '/' . $this->name->partFile($s->part, $split) : $this->storage->partPath($this->name, $s->part, $split);
        $writer = ZipWriter::create($path, max(1, $this->level));
        $writer->addString(PartMarker::ENTRY, (new PartMarker($this->name->base(), $s->part, $split))->toJson(), time(), 0100600);
        $s->writer = $writer->state();

        return $writer;
    }

    private function recordClosed(PackState $s, PartInfo $info): void
    {
        $record = PartRecord::fromPartInfo($info);
        $s->closed[] = ['file' => $record->file, 'size' => $record->size, 'sha256' => $record->sha256, 'entries' => $record->entries];
    }

    /**
     * Starts the entry of an item: a directory is complete at once; a file is opened and read by {@see continueEntry()}.
     * Rolls over to a new part first if this one is full.
     */
    private function beginItem(PackState $s, ZipWriter $writer, ScanItem $item, bool $database = false): ZipWriter
    {
        if ($this->partSize !== null && $writer->needsRollover($this->partSize, $item->size)) {
            $this->recordClosed($s, $writer->close());
            ++$s->part;
            $s->writer = null;
            $writer = $this->newPart($s);
        }

        $name = $database ? $item->path : 'files/' . $item->path;
        if ($item->isDirectory) {
            $writer->addDirectory($name, $item->mtime, $item->mode);
            ++$s->directories;

            return $writer;
        }

        $path = $database ? $this->workDirectory . '/' . $item->path : $this->root . '/' . $item->path;
        $writer->beginFile($name, $item->mtime, $item->mode, $item->size, $this->level > 0 && !Compression::shouldStore($name));
        $s->open = ['name' => $name, 'path' => $path, 'offset' => 0, 'size' => $item->size, 'database' => $database];

        return $writer;
    }

    /**
     * Hands blocks of the open file to the writer until the file is done or the budget is used up.
     */
    private function continueEntry(PackState $s, ZipWriter $writer, Budget $budget): void
    {
        $open = $s->open;
        if ($open === null) {
            return;
        }
        $handle = @fopen($open['path'], 'rb');
        if ($handle === false || ($open['offset'] > 0 && fseek($handle, $open['offset']) !== 0)) {
            if ($handle !== false) {
                fclose($handle);
            }
            if ($open['database']) {
                throw new RuntimeException("The dump file \"{$open['name']}\" cannot be read.");
            }
            $s->warnings[] = $open['offset'] === 0
                ? "Stored empty, the file cannot be read: /{$this->display($open['name'])}"
                : "Stored incomplete, the file disappeared while it was read: /{$this->display($open['name'])}";
            $this->finishEntry($s, $writer, '', $open);

            return;
        }

        try {
            while (true) {
                $block = $this->readBlock($handle);
                if (strlen($block) < ZipWriter::BLOCK) {
                    $this->finishEntry($s, $writer, $block, $open);

                    return;
                }
                $writer->writeBlock($block);
                $open['offset'] += ZipWriter::BLOCK;
                $s->open = $open;
                if ($budget->exhausted()) {
                    return;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param array{name: string, path: string, offset: int, size: int, database: bool} $open
     */
    private function finishEntry(PackState $s, ZipWriter $writer, string $tail, array $open): void
    {
        $writer->endFile($tail);
        $total = $open['offset'] + strlen($tail);
        $s->open = null;
        if ($open['database']) {
            return;
        }
        ++$s->files;
        $s->fileBytes += $total;
        if ($total !== $open['size']) {
            $s->warnings[] = "The file changed while it was backed up (read {$total} bytes, {$open['size']} when scanned): /{$this->display($open['name'])}";
        }
    }

    /**
     * A block of exactly {@see ZipWriter::BLOCK} bytes, or the rest of the file if it ends before.
     *
     * @param resource $handle
     */
    private function readBlock($handle): string
    {
        $block = '';
        while (strlen($block) < ZipWriter::BLOCK && !feof($handle)) {
            $chunk = fread($handle, ZipWriter::BLOCK - strlen($block));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $block .= $chunk;
        }

        return $block;
    }

    /** The path of an entry as the person knows it: without the `files/` prefix. */
    private function display(string $entryName): string
    {
        return str_starts_with($entryName, 'files/') ? substr($entryName, 6) : $entryName;
    }
}
