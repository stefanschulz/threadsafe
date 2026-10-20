<?php

declare(strict_types=1);

namespace ThreadSafe\Engine;

/**
 * A run of a task as it is stored between ticks (`data/runs/<id>.json`).
 */
final readonly class Run
{
    /**
     * @param array<string, mixed> $state
     */
    public function __construct(
        public string $id,
        public string $task,
        public RunStatus $status,
        public int $ticks,
        public int $created,
        public int $updated,
        public ?string $error,
        public ?string $progress,
        public array $state,
    ) {
    }

    /**
     * @param array<string, mixed> $state
     */
    public function with(RunStatus $status, array $state, ?string $error = null, ?string $progress = null): self
    {
        return new self($this->id, $this->task, $status, $this->ticks + 1, $this->created, time(), $error, $progress ?? $this->progress, $state);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'format' => 1,
            'id' => $this->id,
            'task' => $this->task,
            'status' => $this->status->value,
            'ticks' => $this->ticks,
            'created' => $this->created,
            'updated' => $this->updated,
            'error' => $this->error,
            'progress' => $this->progress,
            'state' => $this->state,
        ];
    }
}
