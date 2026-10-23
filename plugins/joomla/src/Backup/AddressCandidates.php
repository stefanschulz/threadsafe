<?php

declare(strict_types=1);

namespace ThreadSafe\Joomla\Backup;

use ThreadSafe\Database\Connection;

/**
 * The addresses that a Joomla site's content points to: the hosts of the absolute URLs in the columns where a site's owner writes them
 * (articles, modules, menu items, template styles, the parameters of extensions), counted. Joomla stores the address of its own site
 * nowhere as one value (`live_site` is mostly empty), so this is where the "old domain" of a restore can be proposed from
 * (docs/PHASE6.md 3.4). The count of a host is the number of times it occurs, in plain `https://host/` and in the JSON form
 * `https:\/\/host\/`.
 *
 * It reads a bounded sample of each table (the rows that have an absolute URL, at most `$rowsPerTable`, the first `$bytesPerCell` bytes of
 * each cell), one row at a time, so it costs the same on a small and on a huge site.
 */
final class AddressCandidates
{
    /** table (without prefix) => text columns */
    private const array COLUMNS = [
        'content' => ['introtext', 'fulltext'],
        'modules' => ['content', 'params'],
        'menu' => ['link', 'params'],
        'template_styles' => ['params'],
        'extensions' => ['params'],
    ];

    /** Hosts that are in every Joomla database or page and are not the address of a site: standards, the project's own sites. */
    private const array IGNORED = ['www.w3.org', 'w3.org', 'schema.org', 'www.schema.org', 'joomla.org', 'xmlns.com', 'purl.org'];

    public function __construct(private readonly int $rowsPerTable = 500, private readonly int $bytesPerCell = 20000, private readonly int $limit = 10)
    {
    }

    /**
     * @return list<array{host: string, count: int}> the most frequent first
     */
    public function find(Connection $db): array
    {
        $counts = [];
        foreach (self::COLUMNS as $table => $columns) {
            $name = $db->table($table);
            if (!$db->tableExists($name)) {
                continue;
            }
            foreach ($columns as $column) {
                $quoted = $db->quote($column);
                $sql = 'SELECT LEFT(' . $quoted . ', ' . $this->bytesPerCell . ') AS value FROM ' . $db->quote($name)
                    . ' WHERE ' . $quoted . " LIKE '%http%' LIMIT " . $this->rowsPerTable;
                foreach ($db->stream($sql) as $row) {
                    foreach (self::hosts((string) ($row['value'] ?? '')) as $host => $times) {
                        $counts[$host] = ($counts[$host] ?? 0) + $times;
                    }
                }
            }
        }
        arsort($counts);
        $candidates = [];
        foreach (array_slice($counts, 0, $this->limit, true) as $host => $count) {
            $candidates[] = ['host' => (string) $host, 'count' => $count];
        }

        return $candidates;
    }

    /**
     * The hosts of the absolute URLs in a text, with how often each occurs.
     *
     * @return array<string, int> host (lower case, with the port if there is one) => count
     */
    public static function hosts(string $text): array
    {
        if (preg_match_all('~https?:(?:\\\\/|/){2}([A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?(?::\d{1,5})?)~i', $text, $matches) < 1) {
            return [];
        }
        $counts = [];
        foreach ($matches[1] as $host) {
            $host = strtolower($host);
            if (self::ignored($host)) {
                continue;
            }
            $counts[$host] = ($counts[$host] ?? 0) + 1;
        }

        return $counts;
    }

    private static function ignored(string $host): bool
    {
        $name = (string) preg_replace('/:\d+$/', '', $host);
        foreach (self::IGNORED as $ignored) {
            if ($name === $ignored || str_ends_with($name, '.' . $ignored)) {
                return true;
            }
        }

        return false;
    }
}
