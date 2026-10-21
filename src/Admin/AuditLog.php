<?php

declare(strict_types=1);

namespace ThreadSafe\Admin;

use Closure;

/**
 * What was done through the web interface (and the commands that touch the same things), one JSON object per line in
 * `data/audit.log` (docs/PHASE4.md 3.2): logins and failures, profile and backup actions, downloads, deletions, password changes.
 *
 * It never holds a secret or a file content: values are short single-line texts, numbers or booleans, and a key that sounds like a
 * secret (password, key, token, hash, secret) is left out whatever the caller passes. The file is rotated at a size; two older
 * generations are kept (`audit.log.1`, `audit.log.2`).
 */
final class AuditLog
{
    private const int GENERATIONS = 2;

    private const string SECRET_KEYS = '/pass|secret|key|token|hash|cookie/i';

    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param Closure(): int|null $clock for tests: the time in seconds
     */
    public function __construct(private readonly string $file, private readonly int $maxBytes = 5242880, ?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * @param array<string, mixed> $context what else to say (profile id, backup name ...); only single values are written, a list or an object is dropped
     */
    public function record(string $event, string $user = '-', string $address = '', array $context = []): void
    {
        $entry = ['time' => gmdate('Y-m-d\TH:i:s\Z', ($this->clock)()), 'event' => self::text($event), 'user' => self::text($user), 'address' => preg_replace('/[^0-9a-fA-F:.]/', '?', $address)];
        foreach ($context as $name => $value) {
            if (preg_match(self::SECRET_KEYS, $name) === 1 || isset($entry[$name]) || !is_scalar($value)) {
                continue;
            }
            $entry[self::text($name, 40)] = is_string($value) ? self::text($value) : $value;
        }

        $directory = dirname($this->file);
        if (!is_dir($directory)) {
            @mkdir($directory, 0700, true);
        }
        $this->rotate();
        if (@file_put_contents($this->file, json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n", FILE_APPEND | LOCK_EX) !== false) {
            @chmod($this->file, 0600);
        }
    }

    /**
     * The newest entries, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function recent(int $count = 20): array
    {
        $entries = [];
        for ($generation = 0; $generation <= self::GENERATIONS && count($entries) < $count; ++$generation) {
            $file = $generation === 0 ? $this->file : $this->file . '.' . $generation;
            $lines = is_file($file) ? @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : false;
            foreach (array_reverse($lines === false ? [] : $lines) as $line) {
                $entry = json_decode($line, true);
                if (is_array($entry) && is_string($entry['event'] ?? null)) {
                    $entries[] = $entry;
                    if (count($entries) >= $count) {
                        break;
                    }
                }
            }
        }

        return $entries;
    }

    private function rotate(): void
    {
        clearstatcache(true, $this->file);
        if (!is_file($this->file) || (int) filesize($this->file) < $this->maxBytes) {
            return;
        }
        for ($generation = self::GENERATIONS; $generation >= 1; --$generation) {
            $from = $generation === 1 ? $this->file : $this->file . '.' . ($generation - 1);
            if (is_file($from)) {
                @rename($from, $this->file . '.' . $generation);
            }
        }
    }

    private static function text(string $value, int $limit = 200): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value) ?? '';

        // not mb_substr(): the extension is not guaranteed; the u flag keeps a multi-byte character whole
        return preg_match('/^.{0,' . $limit . '}/su', $value, $match) === 1 ? $match[0] : '';
    }
}
