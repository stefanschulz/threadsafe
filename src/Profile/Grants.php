<?php

declare(strict_types=1);

namespace ThreadSafe\Profile;

/**
 * Reads the output of `SHOW GRANTS` for the privileges that apply to one database. Only grants on all databases (`*.*`)
 * and on exactly this database count; grants on single tables or on patterns are not followed, so a user who has only those
 * is reported as "cannot tell".
 */
final class Grants
{
    /**
     * @param list<string> $grants lines such as "GRANT SELECT, SHOW VIEW ON `shop`.* TO `u`@`%`"
     *
     * @return list<string> upper-case privilege names; "ALL PRIVILEGES" stands for everything
     */
    public static function privileges(array $grants, string $database): array
    {
        $privileges = [];
        foreach ($grants as $grant) {
            if (preg_match('/^GRANT\s+(.+?)\s+ON\s+(.+?)\s+TO\s/is', $grant, $m) !== 1) {
                continue;
            }
            if (!self::appliesTo($m[2], $database)) {
                continue;
            }
            foreach (explode(',', $m[1]) as $privilege) {
                $name = strtoupper(trim(preg_replace('/\(.*\)$/', '', trim($privilege)) ?? ''));
                $privileges[] = $name === 'ALL' ? 'ALL PRIVILEGES' : $name;
            }
        }

        return array_values(array_unique($privileges));
    }

    /**
     * Which of the wanted privileges are missing.
     *
     * @param list<string> $privileges as returned by {@see privileges()}
     * @param list<string> $wanted
     *
     * @return list<string>
     */
    public static function missing(array $privileges, array $wanted): array
    {
        if (in_array('ALL PRIVILEGES', $privileges, true)) {
            return [];
        }

        return array_values(array_diff($wanted, $privileges));
    }

    private static function appliesTo(string $scope, string $database): bool
    {
        $scope = trim($scope);
        if ($scope === '*.*') {
            return true;
        }
        if (preg_match('/^(`(?:[^`]|``)+`|\'[^\']+\'|"[^"]+"|\w+)\.\*$/', $scope, $m) !== 1) {
            return false;
        }
        $name = $m[1];
        if ($name[0] === '`') {
            $name = str_replace('``', '`', substr($name, 1, -1));
        } elseif ($name[0] === "'" || $name[0] === '"') {
            $name = substr($name, 1, -1);
        }

        // MariaDB and MySQL write "_" and "%" of a database name escaped in the grant
        return $name === $database || str_replace(['\\_', '\\%'], ['_', '%'], $name) === $database;
    }

    private function __construct()
    {
    }
}
