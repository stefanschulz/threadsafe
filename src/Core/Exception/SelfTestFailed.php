<?php

declare(strict_types=1);

namespace ThreadSafe\Core\Exception;

/**
 * The migration was applied, but the self-test found that the result does not work. Exit code 4.
 */
final class SelfTestFailed extends StepFailed
{
}
