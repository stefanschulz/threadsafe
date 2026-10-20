<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Support;

use RuntimeException;

/**
 * The plain `mariadb` command line client of the tools container, used as the independent judge of SQL dumps: whatever it
 * imports without complaint is importable by a person in an emergency.
 */
final class SqlClient
{
    /**
     * Imports a file into a database of the test stack's database server.
     *
     * @return string what the client printed on stderr (empty on success)
     *
     * @throws RuntimeException the client could not be started or the import failed
     */
    public static function import(string $database, string $file): string
    {
        $password = getenv('DB_ROOT_PASSWORD');
        $process = proc_open(
            ['mariadb', '--protocol=tcp', '-h', 'db', '-u', 'root', '--default-character-set=utf8mb4', $database],
            [0 => ['file', $file, 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['MYSQL_PWD' => $password === false || $password === '' ? 'prestashop' : $password, 'PATH' => (string) getenv('PATH')],
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start the mariadb client.');
        }
        stream_get_contents($pipes[1]);
        $error = (string) stream_get_contents($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0) {
            throw new RuntimeException("Importing $file failed: " . substr($error, 0, 500));
        }

        return $error;
    }

    private function __construct()
    {
    }
}
