<?php

declare(strict_types=1);

namespace ThreadSafe\Core;

/**
 * What one call of {@see ResumableStep::applyTick()} returns.
 */
final readonly class StepTick
{
    /**
     * @param array<string, mixed> $state    plain data for the next call
     * @param StepResult|null      $result   set when $finished
     * @param Plan|null            $plan     when $finished: the plan as it turned out (with the changes that were really made), for the report; null keeps the brief plan
     * @param string|null          $progress a short line for the progress display, e.g. "table ps_cms_lang (12 of 299)"
     */
    public function __construct(
        public array $state,
        public bool $finished,
        public ?StepResult $result = null,
        public ?Plan $plan = null,
        public ?string $progress = null,
    ) {
    }
}
