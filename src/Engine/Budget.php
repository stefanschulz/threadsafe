<?php

declare(strict_types=1);

namespace ThreadSafe\Engine;

use Closure;

/**
 * What one tick may use up: time and memory. A task asks {@see exhausted()} between its units of work and stops at a
 * boundary when the answer is yes. The CLI has no time limit (unlimited), a web request gets a share of
 * `max_execution_time`; memory is always watched, because a tick that dies at the limit loses its work.
 */
final class Budget
{
    private float $started;

    /** @var Closure(): float */
    private readonly Closure $clock;

    /** @var Closure(): int */
    private readonly Closure $memory;

    /**
     * @param float|null        $seconds        time for one tick, null = unlimited
     * @param int|null          $memoryLimit    bytes the process may use, null = unlimited
     * @param float             $memoryFraction share of the limit at which the budget counts as used up
     * @param Closure|null      $clock          for tests: fn (): float, seconds
     * @param Closure|null      $memory         for tests: fn (): int, bytes in use
     */
    public function __construct(
        private readonly ?float $seconds,
        private readonly ?int $memoryLimit = null,
        private readonly float $memoryFraction = 0.8,
        ?Closure $clock = null,
        ?Closure $memory = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->memory = $memory ?? static fn (): int => memory_get_usage(true);
        $this->started = ($this->clock)();
    }

    public static function unlimited(): self
    {
        return new self(null, self::parseBytes((string) ini_get('memory_limit')));
    }

    public static function seconds(float $seconds): self
    {
        return new self($seconds, self::parseBytes((string) ini_get('memory_limit')));
    }

    /**
     * The budget this process can afford: unlimited in the CLI, otherwise a share of `max_execution_time` (or a fallback
     * when PHP has no limit but a web server may).
     */
    public static function forThisProcess(float $fraction = 0.6, float $fallbackSeconds = 20.0): self
    {
        if (PHP_SAPI === 'cli') {
            return self::unlimited();
        }
        $limit = (float) ini_get('max_execution_time');

        return self::seconds($limit > 0 ? $limit * $fraction : $fallbackSeconds);
    }

    /** Starts the clock of a tick. */
    public function start(): void
    {
        $this->started = ($this->clock)();
    }

    public function elapsed(): float
    {
        return ($this->clock)() - $this->started;
    }

    public function exhausted(): bool
    {
        if ($this->seconds !== null && $this->elapsed() >= $this->seconds) {
            return true;
        }

        return $this->memoryLimit !== null && ($this->memory)() >= (int) ($this->memoryLimit * $this->memoryFraction);
    }

    /**
     * A `memory_limit` value ("128M", "1G", "512K", "1073741824") in bytes; null for "-1" (no limit).
     */
    public static function parseBytes(string $value): ?int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return null;
        }
        if (preg_match('/^(\d+)\s*([KMG]?)B?$/i', $value, $m) !== 1) {
            return null;
        }
        $factor = match (strtoupper($m[2])) {
            'K' => 1024,
            'M' => 1024 ** 2,
            'G' => 1024 ** 3,
            default => 1,
        };

        return (int) $m[1] * $factor;
    }
}
