<?php

declare(strict_types=1);

namespace ThreadSafe\Core;

/**
 * Plan and outcome of one step.
 */
final readonly class StepReport
{
    public function __construct(
        public string $id,
        public string $title,
        public StepStatus $status,
        public Plan $plan,
        public ?StepResult $result = null,
        public ?string $error = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'status' => $this->status->value,
            'plan' => $this->plan->toArray(),
            'result' => $this->result?->toArray(),
            'error' => $this->error,
        ];
    }
}
