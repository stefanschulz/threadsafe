<?php

declare(strict_types=1);

namespace ThreadSafe\Schedule;

/**
 * What the scheduler remembers about one profile (docs/PHASE5.md 3.1, `data/schedule/<profile>.json`): since when its schedule
 * counts, and what became of the latest scheduled instant it looked at. A cache in the sense of the project's rule that the file
 * system is the source of truth: when it is lost the worst case is one run too many or too few.
 */
final readonly class ScheduleState
{
    public const string PENDING = 'pending';

    public const string RUNNING = 'running';

    public const string DONE = 'done';

    public const string GAVE_UP = 'gaveUp';

    public const string SKIPPED = 'skipped';

    private const array STATUSES = [self::PENDING, self::RUNNING, self::DONE, self::GAVE_UP, self::SKIPPED];

    /**
     * @param int      $since    Unix time from which scheduled instants count (when the schedule was set, or first seen)
     * @param int|null $instant  Unix time of the scheduled instant the rest refers to
     * @param int      $attempts how many times a run for that instant was started
     * @param int|null $retryAt  Unix time before which a failed run is not tried again
     * @param string|null $runId the backup run that was started for the instant (while it is going), so that a run that was
     *                           interrupted and is continued later can report its end to the right instant
     */
    public function __construct(
        public int $since,
        public ?int $instant = null,
        public string $status = self::PENDING,
        public int $attempts = 0,
        public ?int $retryAt = null,
        public ?string $runId = null,
    ) {
    }

    /** Whether the instant has been dealt with, so that it must not be run again. */
    public function handled(int $instant): bool
    {
        return $this->instant === $instant && $this->status !== self::PENDING;
    }

    /**
     * @return array{since: int, instant: int|null, status: string, attempts: int, retryAt: int|null, runId: string|null}
     */
    public function toArray(): array
    {
        return ['since' => $this->since, 'instant' => $this->instant, 'status' => $this->status, 'attempts' => $this->attempts, 'retryAt' => $this->retryAt, 'runId' => $this->runId];
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws ScheduleException the data is not schedule state
     */
    public static function fromArray(array $data): self
    {
        $since = $data['since'] ?? null;
        $instant = $data['instant'] ?? null;
        $status = $data['status'] ?? self::PENDING;
        $attempts = $data['attempts'] ?? 0;
        $retryAt = $data['retryAt'] ?? null;
        $runId = $data['runId'] ?? null;
        if (!is_int($since) || !($instant === null || is_int($instant)) || !is_string($status) || !in_array($status, self::STATUSES, true) || !is_int($attempts) || $attempts < 0 || !($retryAt === null || is_int($retryAt)) || !($runId === null || is_string($runId))) {
            throw new ScheduleException('The schedule state is not valid.');
        }

        return new self($since, $instant, $status, $attempts, $retryAt, $runId);
    }
}
