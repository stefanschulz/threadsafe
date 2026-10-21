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

    /**
     * The inverse of {@see toArray()}.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['id'],
            (string) $data['title'],
            StepStatus::from((string) $data['status']),
            Plan::fromArray((array) $data['plan']),
            isset($data['result']) ? StepResult::fromArray((array) $data['result']) : null,
            isset($data['error']) ? (string) $data['error'] : null,
        );
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
