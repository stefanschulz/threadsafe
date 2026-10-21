<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Support;

use ThreadSafe\Core\Context;
use ThreadSafe\Core\HasFindings;
use ThreadSafe\Core\Plan;
use ThreadSafe\Core\StepResult;

/**
 * A step that looks at "the installation" in plan() and stores what it found in the context, like ReadSourceState. What it finds
 * is whatever the shared `$world` array says at that moment, so a test can change the world after planning, as a migration
 * changes the installation. Records "plan:<id>", "export:<id>" and "import:<id>".
 */
final class FakeFindingsStep implements HasFindings
{
    /**
     * @param array{value: string} $world
     */
    public function __construct(private readonly string $id, private readonly EventLog $log, private array &$world)
    {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function title(): string
    {
        return "Step $this->id";
    }

    public function plan(Context $context): Plan
    {
        $this->log->add('plan:' . $this->id);
        $context->set('found', $this->world['value']);

        return new Plan();
    }

    public function apply(Context $context, Plan $plan): StepResult
    {
        return new StepResult(0);
    }

    public function exportFindings(Context $context): array
    {
        $this->log->add('export:' . $this->id);

        return ['value' => $context->get('found')];
    }

    public function importFindings(Context $context, array $findings): void
    {
        $this->log->add('import:' . $this->id);
        $context->set('found', $findings['value']);
    }
}
