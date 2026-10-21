<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Integration;

use PDO;
use ThreadSafe\Backup\Dumper;
use ThreadSafe\Database\Connection;
use ThreadSafe\Database\DatabaseSettings;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Restore\ImportException;
use ThreadSafe\Restore\Importer;
use ThreadSafe\Restore\RestoreRun;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Tests\Support\UploadedBackup;

/**
 * A cell of 40 MB onto a database server that takes packets of 16 MB. The dump cannot build such a value from pieces on that
 * server: the `UPDATE ... CONCAT(...)` that appends a piece gives NULL (or a cut value) and only a warning, because the dump's
 * sql_mode is not strict. A restore that finished "successfully" with damaged cells was the result before the importer looked at
 * the warnings; now the preflight refuses the restore and the importer stops it.
 */
final class LargeCellRestoreTest extends IntegrationTestCase
{
    use TempDirectory;

    private PDO $admin;

    /** @var list<string> */
    private array $databases = [];

    private int $packet = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = new PDO('mysql:host=db;charset=utf8mb4', 'root', self::password(), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->packet = (int) $this->admin->query('SELECT @@global.max_allowed_packet')->fetchColumn();
    }

    protected function tearDown(): void
    {
        $this->admin->exec('SET GLOBAL max_allowed_packet = ' . $this->packet);
        foreach ($this->databases as $database) {
            $this->admin->exec("DROP DATABASE IF EXISTS `$database`");
        }
        $this->cleanUpTempDirectories();
    }

    private static function password(): string
    {
        $password = getenv('DB_ROOT_PASSWORD');

        return $password === false || $password === '' ? 'prestashop' : $password;
    }

    /** A database with a table with two cells of 40 MB (a new session has the raised limit while the cells are made). */
    private function database(): string
    {
        $name = 'threadsafe_bigcell_' . bin2hex(random_bytes(3));
        $this->databases[] = $name;
        $this->admin->exec('SET GLOBAL max_allowed_packet = ' . (256 * 1048576));
        $creator = new PDO('mysql:host=db;charset=utf8mb4', 'root', self::password(), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $creator->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4");
        $creator->exec("USE `$name`");
        $creator->exec('CREATE TABLE t (id INT NOT NULL PRIMARY KEY, data LONGBLOB NULL, txt LONGTEXT NOT NULL) ENGINE=InnoDB');
        $creator->exec("INSERT INTO t VALUES (1, RPAD(_binary '', 41943040, _binary 'abcdefghij'), REPEAT('x', 41943040))");
        // from now on the server takes 16 MB, as MariaDB does by default (MySQL 8 takes 64 MB, which would make the cells fit)
        $this->admin->exec('SET GLOBAL max_allowed_packet = 16777216');

        return $name;
    }

    private function empty(): string
    {
        $name = 'threadsafe_bigcell_copy_' . bin2hex(random_bytes(3));
        $this->databases[] = $name;
        $this->admin->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4");

        return $name;
    }

    private function connection(string $database, string $driver = 'pdo'): Connection
    {
        return Connection::open(new DatabaseSettings('db', 3306, $database, 'root', self::password(), '', $driver));
    }

    private function dump(string $database): string
    {
        $directory = $this->tempDirectory();
        $dumper = new Dumper($this->connection($database));
        $outcome = $dumper->tick($dumper->initialState(['directory' => $directory]), Budget::unlimited());
        self::assertTrue($outcome->finished);

        return $directory;
    }

    public function testTheImportStopsInsteadOfWritingDamagedCells(): void
    {
        $directory = $this->dump($this->database());

        foreach (['pdo', 'mysqli'] as $driver) {
            $importer = new Importer($this->connection($this->empty(), $driver));
            try {
                $importer->tick($importer->initialState(['directory' => $directory]), Budget::unlimited());
                self::fail("The import with $driver should have been stopped.");
            } catch (ImportException $e) {
                self::assertStringContainsString('damaged data', $e->getMessage(), $driver);
                self::assertStringContainsString('code 1301', $e->getMessage(), $driver);
                self::assertStringContainsString('max_allowed_packet', $e->getMessage(), $driver);
                self::assertStringNotContainsString('abcdefghij', $e->getMessage(), 'no values in the message');
            }
        }
    }

    public function testWithTheLimitRaisedTheSameDumpImportsCompletely(): void
    {
        $source = $this->database();
        $directory = $this->dump($source);
        $copy = $this->empty();
        $this->admin->exec('SET GLOBAL max_allowed_packet = ' . (256 * 1048576));

        foreach (['pdo', 'mysqli'] as $driver) {
            $importer = new Importer($this->connection($copy, $driver));
            $outcome = $importer->tick($importer->initialState(['directory' => $directory]), Budget::unlimited());
            self::assertTrue($outcome->finished);
            $row = $this->connection($copy)->select('SELECT LENGTH(data) AS d, LENGTH(txt) AS t, MD5(data) AS m FROM t')[0];
            self::assertSame(41943040, (int) $row['d'], $driver);
            self::assertSame(41943040, (int) $row['t'], $driver);
            self::assertSame($this->connection($source)->scalar('SELECT MD5(data) FROM t'), $row['m'], $driver);
            $this->admin->exec("DROP TABLE `$copy`.t");
        }
    }

    public function testThePreflightRefusesTheRestoreBeforeAnythingIsWritten(): void
    {
        $base = $this->tempDirectory();
        mkdir($base . '/web');
        $parts = UploadedBackup::make($base . '/work', $base . '/web', $this->database(), self::password());
        $copy = $this->empty();
        $task = new RestoreRun(PluginRegistry::load());
        $state = $task->initialState([
            'archive' => $parts[0], 'root' => $base . '/restored', 'work' => $base . '/state',
            'settings' => ['selfTest' => false, 'database' => ['host' => 'db', 'port' => 3306, 'name' => $copy, 'user' => 'root', 'password' => self::password(), 'prefix' => '']],
        ]);

        $inspection = $task->inspect($state);

        self::assertFalse($inspection->ok());
        $message = $inspection->errorMessage();
        self::assertStringContainsString('holds a cell of 40', $message);
        self::assertStringContainsString('max_allowed_packet', $message);
        self::assertDirectoryDoesNotExist($base . '/restored');
    }
}
