<?php

declare(strict_types=1);

namespace ThreadSafe\Update;

use ThreadSafe\Core\Version;

/**
 * Brings the data directory to the format of this release (docs/PHASE7.md 3.6). Called at the start of the web interface and of the
 * command: data of an older format is migrated step by step (the format is written after each step, so a cut-off goes on where it was),
 * data of the same format needs nothing, and data of a **newer** format is refused, because a program must not write files it does not
 * understand. A data directory without `version.json` is format 1, what every earlier phase wrote.
 */
final class Migrator
{
    /**
     * @param list<Migration> $migrations
     */
    public function __construct(private readonly array $migrations = [], private readonly int $current = Version::DATA_FORMAT, private readonly string $program = Version::CURRENT)
    {
    }

    /**
     * @return list<string> the descriptions of the migrations that ran
     *
     * @throws UpdateException the data is newer than this program, or a migration failed
     */
    public function ensure(string $data): array
    {
        if (!is_dir($data)) {
            return [];
        }
        $stored = DataFormat::read($data);
        $format = $stored['dataFormat'] ?? 1;
        if ($format > $this->current) {
            throw new UpdateException("The files in \"$data\" were made by a newer ThreadSafe (data format $format; this program, version {$this->program}, writes $this->current). Use the newer version again, or restore the data directory from a backup.");
        }

        $migrations = $this->migrations;
        usort($migrations, static fn (Migration $a, Migration $b): int => $a->to() <=> $b->to());
        $ran = [];
        foreach ($migrations as $migration) {
            if ($migration->to() <= $format || $migration->to() > $this->current) {
                continue;
            }
            $migration->run($data);
            $format = $migration->to();
            DataFormat::write($data, $format, $this->program);
            $ran[] = $migration->describe();
        }
        if ($stored === null || $stored['dataFormat'] !== $this->current || $stored['program'] !== $this->program) {
            try {
                DataFormat::write($data, $this->current, $this->program);
            } catch (\RuntimeException) {
                // the label of the program is a convenience; a data directory that cannot be written fails where it matters
            }
        }

        return $ran;
    }
}
