<?php

declare(strict_types=1);

namespace ThreadSafe\Restore;

use Closure;
use ThreadSafe\Archive\ZipException;
use ThreadSafe\Backup\Manifest;
use ThreadSafe\Crypto\CryptoException;
use ThreadSafe\Database\Connection;
use ThreadSafe\Database\ConnectionFailed;
use ThreadSafe\Database\DatabaseError;
use ThreadSafe\Database\DatabaseSettings;
use ThreadSafe\Database\ServerInfo;
use ThreadSafe\Plugin\Restorable;
use ThreadSafe\Storage\LocalStorage;
use ThreadSafe\Storage\SpaceCheck;

/**
 * Everything that can be checked before the restore writes a single byte (docs/PHASE3.md 3.3, 3.8): the requirements of the
 * host, whether the target directory and the database are empty, whether the free space is enough, whether the database server
 * can take the dump, and what the website type has to say. Reads, never writes.
 *
 * An error in the result stops the restore; warnings and infos are for the person.
 */
final class Preflight
{
    /** Names that may be in the target directory at the start without being in the way (with `allowLeftovers`). */
    private const array PLACEHOLDERS = ['.htaccess', 'index.html', 'index.htm', 'robots.txt', 'favicon.ico', 'cgi-bin', '.well-known'];

    /** The free space the restore wants beyond the bare size of files and dump. */
    private const float SPACE_MARGIN = 1.1;

    /** @var Closure(string): (int|float|false) */
    private readonly Closure $freeSpace;

    /**
     * @param (Closure(string): (int|float|false))|null $freeSpace free bytes of the file system a directory is on; default `disk_free_space`
     */
    public function __construct(?Closure $freeSpace = null)
    {
        $this->freeSpace = $freeSpace ?? static fn (string $directory): float|false => @disk_free_space($directory);
    }

    /**
     * @param string       $root           the directory the site is restored into
     * @param list<string> $ownNames       names in the root that belong to the restore itself (the tool, its work directory, the archive)
     * @param bool         $allowLeftovers let web server placeholders (.htaccess, index.html ...) in the root pass
     * @param bool         $dropTables     the person asked to delete the tables of the prefix first: they are no obstacle, but a warning that says what goes
     *
     * @return list<Check>
     */
    public function run(BackupSource $source, string $root, ?DatabaseSettings $database, bool $allowLeftovers, array $ownNames, ?Restorable $type, bool $dropTables = false): array
    {
        $manifest = $source->manifest;
        $checks = [];

        if (!function_exists('inflate_init')) {
            $checks[] = Check::error('The PHP extension zlib is not available.', 'Ask the host to enable it (it is part of nearly every PHP).');
        }
        array_push($checks, ...$this->rootChecks($root, $allowLeftovers, $ownNames));
        array_push($checks, ...$this->spaceChecks($manifest, $root));

        if ($manifest->database !== []) {
            array_push($checks, ...($database === null
                ? [Check::error('The backup holds a database, but no database settings were given.', 'Give host, name, user and password of an empty database.')]
                : $this->databaseChecks($source, $database, $dropTables)));
        } elseif ($database !== null) {
            $checks[] = Check::warning('The backup holds no database; the database settings are not used.');
        }

        foreach ($manifest->warnings as $warning) {
            $checks[] = Check::warning('The backup itself reported: ' . $warning);
        }
        foreach ($manifest->unsupportedObjects as $object) {
            $checks[] = Check::warning("The backup does not contain the {$object['type']} \"{$object['name']}\" (ThreadSafe does not back those up).", 'Create it again by hand if the site needs it.');
        }
        if ($type !== null) {
            array_push($checks, ...$type->restoreChecks($manifest));
        }

        return $checks;
    }

    /**
     * @param list<string> $ownNames
     *
     * @return list<Check>
     */
    private function rootChecks(string $root, bool $allowLeftovers, array $ownNames): array
    {
        if (!file_exists($root)) {
            $existing = dirname($root);
            while (!file_exists($existing) && dirname($existing) !== $existing) {
                $existing = dirname($existing);
            }

            return is_dir($existing) && is_writable($existing)
                ? [Check::info("The directory \"$root\" will be created.")]
                : [Check::error("The directory \"$root\" does not exist and cannot be created (\"$existing\" is not writable).", 'Choose another directory or create it with the right permissions.')];
        }
        if (!is_dir($root)) {
            return [Check::error("\"$root\" is a file, not a directory.")];
        }
        if (!is_writable($root)) {
            return [Check::error("The directory \"$root\" is not writable for this program.", 'Change its permissions or run the restore as the user that owns it.')];
        }

        $own = array_flip($ownNames);
        // a person who uploads the whole backup directory brings the protection files of ThreadSafe's storage along
        foreach (LocalStorage::protectionFiles() as $name => $content) {
            if (is_file($root . '/' . $name) && @file_get_contents($root . '/' . $name) === $content) {
                $own[$name] = true;
            }
        }
        $placeholders = $allowLeftovers ? array_flip(self::PLACEHOLDERS) : [];
        $inTheWay = [];
        foreach (scandir($root) ?: [] as $name) {
            if ($name !== '.' && $name !== '..' && !isset($own[$name]) && !isset($placeholders[$name])) {
                $inTheWay[] = $name;
            }
        }
        if ($inTheWay === []) {
            return [];
        }
        sort($inTheWay);

        return [Check::error(
            sprintf('The directory "%s" is not empty: %s%s.', $root, implode(', ', array_slice($inTheWay, 0, 6)), count($inTheWay) > 6 ? sprintf(' and %d more', count($inTheWay) - 6) : ''),
            'A restore never overwrites or deletes an installation. Empty the directory by hand, choose another one, or restore elsewhere and move the result.'
            . (array_intersect($inTheWay, self::PLACEHOLDERS) !== [] && !$allowLeftovers ? ' Placeholder files of the web server (.htaccess, index.html) can be let through with --allow-leftovers.' : ''),
        )];
    }

    /**
     * @return list<Check>
     */
    private function spaceChecks(Manifest $manifest, string $root): array
    {
        $required = (int) ceil(($manifest->counts['fileBytes'] + $manifest->counts['databaseBytes']) * self::SPACE_MARGIN);
        $existing = $root;
        while (!file_exists($existing) && dirname($existing) !== $existing) {
            $existing = dirname($existing);
        }
        $free = ($this->freeSpace)($existing);
        if ($free === false) {
            return [Check::warning('The free space on the target cannot be determined; the restore needs about ' . SpaceCheck::format($required) . '.')];
        }
        if ($free < $required) {
            return [Check::error(
                sprintf('Not enough free space in "%s": %s free, about %s needed (files and the dump that is unpacked before it is imported).', $existing, SpaceCheck::format((int) $free), SpaceCheck::format($required)),
                'Free up space or choose a directory on a larger file system.',
            )];
        }

        return [];
    }

    /**
     * @return list<Check>
     */
    private function databaseChecks(BackupSource $source, DatabaseSettings $settings, bool $dropTables): array
    {
        $manifest = $source->manifest;
        try {
            $db = Connection::open($settings);
        } catch (ConnectionFailed $e) {
            return [Check::error($e->getMessage(), 'Check host, port, name, user and password; the database must exist (a restore does not create it).')];
        }
        if (($problem = $db->server->problem()) !== null) {
            return [Check::error($problem)];
        }

        $checks = [];
        try {
            // an empty database, or at least one without a table of the prefix
            $like = addcslashes($settings->prefix, '%_\\') . '%';
            $names = array_map(static fn (array $row): string => (string) $row['TABLE_NAME'], $db->select(
                'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ? ORDER BY TABLE_NAME LIMIT 6',
                [$like],
            ));
            if ($names !== [] && $dropTables) {
                $count = TableDropper::count($db, $settings->prefix);
                $checks[] = Check::warning(sprintf(
                    'ALL %d tables and views %s of the database "%s" will be DELETED before the import, with everything in them (for example %s). This cannot be undone.',
                    $count,
                    $settings->prefix === '' ? '(the database has no table prefix: everything in it)' : "whose names begin with \"{$settings->prefix}\"",
                    $settings->name,
                    implode(', ', array_slice($names, 0, 3)),
                ), 'You asked for this ("delete the existing tables first"). Make sure this is the right database and that you have a copy of what you still need.');
            } elseif ($names !== []) {
                $checks[] = Check::error(
                    sprintf('The database "%s" already has tables%s: %s.', $settings->name, $settings->prefix === '' ? '' : " with the prefix \"{$settings->prefix}\"", implode(', ', $names)),
                    'A restore never overwrites or drops tables unless you ask it to. Use an empty database or another table prefix, empty it by hand, or choose "delete the existing tables first" (the wizard: a box on the page "Where to restore"; the command: --drop-tables).',
                );
            }
            $charset = $db->scalar('SELECT DEFAULT_CHARACTER_SET_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = DATABASE()');
            if (is_string($charset) && $charset !== 'utf8mb4') {
                $checks[] = Check::warning("The default character set of the database is \"$charset\", not utf8mb4. The tables are created with their own character set, so this does not matter for them.");
            }
            $packet = (int) $db->scalar('SELECT @@max_allowed_packet');
            $largest = self::largestCell($manifest);
            if ($packet > 0 && $largest > $packet) {
                $checks[] = Check::error(
                    sprintf('The backup holds a cell of %s; the database server takes no more than %s (max_allowed_packet), so that cell would be lost.', SpaceCheck::format($largest), SpaceCheck::format($packet)),
                    'Raise max_allowed_packet of the database server (SET GLOBAL max_allowed_packet = ..., or my.cnf) above the size of the cell, then try again.',
                );
            } elseif ($packet > 0 && $packet < 4 * 1048576) {
                $checks[] = Check::warning(sprintf('max_allowed_packet of the database server is only %s; statements of about 1 MB are normal, a cell larger than that cannot be restored.', SpaceCheck::format($packet)));
            }
        } catch (DatabaseError $e) {
            return [...$checks, Check::error('The database cannot be examined: ' . $e->getMessage())];
        }

        $sourceKind = (string) ($manifest->database['server'] ?? '');
        if ($sourceKind !== '' && $sourceKind !== $db->server->type) {
            $checks[] = Check::warning(sprintf('The backup was made on %s, this is %s. Tables and data are portable; the collations are checked next.', $sourceKind, $db->server->describe()));
        }
        foreach ((array) ($manifest->database['collations'] ?? []) as $collation) {
            $obstacle = self::collationObstacle((string) $collation, $db->server);
            if ($obstacle !== null) {
                $checks[] = Check::error($obstacle[0], $obstacle[1]);
            }
        }

        $sourcePrefix = (string) ($manifest->database['prefix'] ?? '');
        if ($sourcePrefix !== $settings->prefix) {
            $reason = $this->prefixObstacle($source);
            $checks[] = $reason !== null
                ? Check::error("The table prefix cannot be changed from \"$sourcePrefix\" to \"{$settings->prefix}\": $reason.", "Restore with the prefix \"$sourcePrefix\".")
                : Check::info("The table prefix is changed from \"$sourcePrefix\" to \"{$settings->prefix}\".");
        }

        return $checks;
    }

    /**
     * The size of the largest cell the backup itself reported (it warns about cells above the packet limit of the server it was made
     * on, in the words of {@see \ThreadSafe\Backup\Dumper}); 0 if there is none.
     */
    /**
     * Why a server cannot hold a table of this collation, as [message, hint]; null when it can. The collations of the UCA 14.0.0
     * family (`utf8mb4_uca1400_ai_ci`, the default of MariaDB from 11.5) exist from MariaDB 11.4 on (checked on 10.11.18, 11.3.2 and
     * 11.4.13); the `0900` ones are MySQL 8's.
     *
     * @return array{string, string}|null
     */
    public static function collationObstacle(string $collation, ServerInfo $server): ?array
    {
        if ($server->isMariaDb() && str_contains($collation, '0900')) {
            return ["The backup uses the MySQL 8 collation \"$collation\", which MariaDB does not have.", 'Restore onto MySQL 8, or change the collation of the tables in the source before the backup.'];
        }
        if (!str_contains($collation, 'uca1400')) {
            return null;
        }
        if (!$server->isMariaDb()) {
            return ["The backup uses the MariaDB collation \"$collation\", which MySQL does not have.", 'Restore onto MariaDB 11.4 or newer.'];
        }
        if (version_compare($server->version, '11.4', '<')) {
            return ["The backup uses the collation \"$collation\", which MariaDB {$server->version} does not have.", 'Restore onto MariaDB 11.4 or newer, or change the collation of the tables in the source before the backup.'];
        }

        return null;
    }

    private static function largestCell(Manifest $manifest): int
    {
        $largest = 0.0;
        foreach ($manifest->warnings as $warning) {
            if (preg_match('/holds a cell of ([0-9.]+) (KB|MB|GB)/', $warning, $m) === 1) {
                $largest = max($largest, (float) $m[1] * ['KB' => 1024, 'MB' => 1048576, 'GB' => 1073741824][$m[2]]);
            }
        }

        return (int) $largest;
    }

    /** Views and triggers name tables, so the prefix cannot be rewritten without understanding SQL (docs/PHASE3.md 3.5). */
    private function prefixObstacle(BackupSource $source): ?string
    {
        try {
            foreach ($source->parts as $part) {
                foreach (Parts::open($part, $source->unlock)->entries() as $entry) {
                    if ($entry->name === 'db/_views.sql' || $entry->name === 'db/_triggers.sql') {
                        return 'the backup contains views or triggers, which name tables';
                    }
                }
            }
        } catch (ZipException | CryptoException) {
            return null;    // the extraction will say what is wrong with the part
        } finally {
            Parts::releaseAll();
        }

        return null;
    }
}
