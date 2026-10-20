<?php

declare(strict_types=1);

namespace ThreadSafe\Cli;

/**
 * Definition of one command line option: a flag (`--dry-run`) or an option with a value (`--root=PATH`).
 */
final readonly class OptionSpec
{
    public function __construct(
        public string $name,
        public string $description,
        public bool $takesValue = false,
        public ?string $short = null,
        public string $valueName = 'VALUE',
        public bool $repeatable = false,
    ) {
    }

    /** "-h, --help" or "--root=PATH" for the help text. */
    public function synopsis(): string
    {
        $long = '--' . $this->name . ($this->takesValue ? '=' . $this->valueName : '');

        return $this->short !== null ? "-{$this->short}, $long" : "    $long";
    }
}
