<?php

declare(strict_types=1);

namespace ThreadSafe\Restore;

use ThreadSafe\Database\DatabaseSettings;

/**
 * What {@see RestoreRun::inspect()} found out before anything is written: the backup, the checks, and the settings as the restore
 * will use them (domain and table prefix filled in from the backup where the person gave none).
 */
final readonly class Inspection
{
    /**
     * @param array<string, mixed> $settings  request settings for the website type, with `from` and the database prefix filled in
     * @param list<Check>          $checks
     * @param list<string>         $protected paths below the root that the extraction never writes
     */
    public function __construct(
        public BackupSource $source,
        public string $typeId,
        public array $settings,
        public array $checks,
        public ?DatabaseSettings $database,
        public array $protected,
    ) {
    }

    /** @return list<Check> */
    public function errors(): array
    {
        return array_values(array_filter($this->checks, static fn (Check $c): bool => $c->isError()));
    }

    public function ok(): bool
    {
        return $this->errors() === [];
    }

    /** The errors as one message, one line each, with the way out. */
    public function errorMessage(): string
    {
        $lines = [];
        foreach ($this->errors() as $check) {
            $lines[] = '- ' . $check->message . ($check->hint !== '' ? ' ' . $check->hint : '');
        }

        return "The restore cannot start:\n" . implode("\n", $lines);
    }
}
