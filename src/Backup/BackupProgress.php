<?php

declare(strict_types=1);

namespace ThreadSafe\Backup;

/**
 * How far a backup run is, read from the state it saves after every tick, for a progress bar: a percentage where the work can be
 * measured (tables done of tables to do, the file list read so far), none while the site is still being listed. It is an estimate
 * for the eyes; it never decides anything.
 */
final class BackupProgress
{
    /** Share of the bar for the dump, if the site has a database. */
    private const int DUMP_SHARE = 20;

    private const int SCAN_END = 5;

    /**
     * @param array<string, mixed> $state the state of a backup run
     *
     * @return array{phase: string, percent: int|null} the phase (`init`, `scan`, `dump`, `pack`, `finale`, `done`) and 0-100, null = cannot tell
     */
    public static function of(array $state): array
    {
        try {
            $s = BackupState::fromArray($state);
        } catch (\InvalidArgumentException) {
            return ['phase' => 'init', 'percent' => null];
        }

        $hasDatabase = $s->dump !== null || $s->phase === BackupState::DUMP;
        $packStart = $hasDatabase ? self::SCAN_END + self::DUMP_SHARE : self::SCAN_END;

        $percent = match ($s->phase) {
            BackupState::INIT, BackupState::SCAN => null,
            BackupState::DUMP => self::SCAN_END + (int) round(self::DUMP_SHARE * self::dumpRatio($s)),
            BackupState::PACK => $packStart + (int) round((97 - $packStart) * self::packRatio($s)),
            BackupState::CLOSE, BackupState::SEAL => 97,
            BackupState::FINALE => 98,
            BackupState::REMOTE => max(0, min(99, (int) ($s->remote['percent'] ?? 0))),
            default => 100,
        };

        return ['phase' => $s->phase, 'percent' => $percent];
    }

    private static function dumpRatio(BackupState $s): float
    {
        if ($s->dump === null) {
            return 0.0;
        }
        $dump = DumpState::fromArray($s->dump);
        $total = count($dump->tables);

        return $total === 0 ? 0.0 : min(1.0, count($dump->done) / $total);
    }

    private static function packRatio(BackupState $s): float
    {
        if ($s->pack === null) {
            return 0.0;
        }
        $pack = PackState::fromArray($s->pack);
        if ($pack->stage !== PackState::FILES) {
            return 1.0;
        }

        return $s->listSize <= 0 ? 0.0 : min(1.0, $pack->listOffset / $s->listSize);
    }

    private function __construct()
    {
    }
}
