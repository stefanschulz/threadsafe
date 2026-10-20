<?php

declare(strict_types=1);

namespace ThreadSafe\Core;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Journal written as JSON Lines (one JSON object per line, flushed immediately), so that it survives a crash and
 * can be read with any tool. Directory mode 0700, file mode 0600. Overwritten files are copied to `files/`.
 *
 * Line types: header, step, db, file, note. Values that are not valid UTF-8 are stored as {"base64": "…"}.
 */
final class FileJournal implements Journal
{
    private const int FORMAT_VERSION = 1;

    /** @var resource|null */
    private $handle;

    private string $path;

    private string $directory;

    private int $backups = 0;

    private ?string $currentStep = null;

    public function __construct(string $directory, ?DateTimeImmutable $now = null)
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));

        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException("Cannot create the journal directory \"$directory\".");
        }
        $this->directory = rtrim($directory, '/\\');

        // The random suffix keeps two journals created in the same second apart; 'x' never overwrites.
        $this->path = $this->directory . '/threadsafe-journal-' . $now->format('Ymd-His') . '-' . bin2hex(random_bytes(2)) . '.jsonl';
        $handle = fopen($this->path, 'xb');
        if ($handle === false) {
            throw new RuntimeException("Cannot create the journal file \"{$this->path}\".");
        }
        chmod($this->path, 0600);
        $this->handle = $handle;

        $this->write(['type' => 'header', 'format' => self::FORMAT_VERSION, 'tool' => 'threadsafe', 'started' => $now->format(DATE_ATOM)]);
    }

    public function __destruct()
    {
        $this->close();
    }

    public function close(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }
    }

    public function path(): string
    {
        return $this->path;
    }

    public function step(string $stepId): void
    {
        $this->currentStep = $stepId;
        $this->write(['type' => 'step', 'step' => $stepId]);
    }

    public function database(string $table, array $key, string $column, ?string $old, ?string $new): void
    {
        $this->write([
            'type' => 'db',
            'step' => $this->currentStep,
            'table' => $table,
            'key' => $key,
            'column' => $column,
            'old' => self::value($old),
            'new' => self::value($new),
        ]);
    }

    public function file(string $path, string $action, ?string $backup = null): void
    {
        $this->write(['type' => 'file', 'step' => $this->currentStep, 'path' => $path, 'action' => $action, 'backup' => $backup]);
    }

    public function backupFile(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }

        $directory = $this->directory . '/files';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException("Cannot create \"$directory\".");
        }
        $name = sprintf('%04d-%s.orig', ++$this->backups, preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($path)));
        $target = $directory . '/' . $name;
        if (!copy($path, $target)) {
            throw new RuntimeException("Cannot copy \"$path\" into the journal.");
        }
        chmod($target, 0600);

        return 'files/' . $name;
    }

    public function note(string $message, array $context = []): void
    {
        $this->write(['type' => 'note', 'step' => $this->currentStep, 'message' => $message, 'context' => $context]);
    }

    /** @return string|array{base64: string}|null */
    private static function value(?string $value): string|array|null
    {
        if ($value === null) {
            return null;
        }

        return mb_check_encoding($value, 'UTF-8') ? $value : ['base64' => base64_encode($value)];
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function write(array $entry): void
    {
        if ($this->handle === null) {
            throw new RuntimeException('The journal is closed.');
        }
        $entry = ['t' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z')] + $entry;
        $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        if (fwrite($this->handle, $line . "\n") === false || !fflush($this->handle)) {
            throw new RuntimeException("Cannot write to the journal \"{$this->path}\".");
        }
    }
}
