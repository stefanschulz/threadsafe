<?php

declare(strict_types=1);

namespace ThreadSafe\Core\Exception;

use RuntimeException;
use Throwable;

/**
 * A step could not do its work. Exit code 3. Steps applied before it stay applied.
 */
class StepFailed extends RuntimeException
{
    private string $stepId = '';

    /** Wraps any throwable that escaped from a step, remembering which step it was. */
    public static function in(string $stepId, Throwable $previous): self
    {
        $failure = new self("Step \"$stepId\" failed: " . $previous->getMessage(), 0, $previous);
        $failure->stepId = $stepId;

        return $failure;
    }

    public function stepId(): string
    {
        return $this->stepId;
    }

    public function withStepId(string $stepId): static
    {
        $this->stepId = $stepId;

        return $this;
    }
}
