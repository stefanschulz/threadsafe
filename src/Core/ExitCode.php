<?php

declare(strict_types=1);

namespace ThreadSafe\Core;

/**
 * Process exit codes of the command line tool.
 */
enum ExitCode: int
{
    /** Everything went as planned (also: a dry run that found nothing wrong). */
    case Ok = 0;

    /** Wrong command line: unknown command or option, missing or invalid argument. */
    case Usage = 1;

    /** A guard refused to run (wrong site, nothing to migrate, unsupported database server …). */
    case GuardFailed = 2;

    /** A step failed. Steps that ran before it stay applied; the report says which. */
    case StepFailed = 3;

    /** The migration was applied but the self-test found the result not working. */
    case SelfTestFailed = 4;

    /** Unexpected error inside ThreadSafe itself. */
    case Internal = 5;
}
