<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Integration;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use ThreadSafe\Backup\Dumper;
use ThreadSafe\Database\Connection;
use ThreadSafe\Database\DatabaseSettings;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Restore\ImportException;
use ThreadSafe\Restore\Importer;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Tests\Support\TortureDatabase;

/**
 * The importer against a real server: dumps of the Dumper go into an empty database through the plain PHP statement loop
 * (no `mysql` client), in one tick and tick by tick, with both PHP interfaces, with another table prefix, after a crash in
 * the middle of a file, and with a broken dump.
 */
final class ImporterTest extends IntegrationTestCase
{
    use TempDirectory;

    private PDO $admin;

    private string $source = '';

    private string $copy = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = new PDO('mysql:host=db;charset=utf8mb4', 'root', self::password(), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $suffix = bin2hex(random_bytes(4));
        $this->source = "threadsafe_imp_src_$suffix";
        $this->copy = "threadsafe_imp_copy_$suffix";
    }

    protected function tearDown(): void
    {
        if ($this->source !== '') {
            $this->admin->exec("DROP DATABASE IF EXISTS `{$this->source}`");
        }
        $this->admin->exec("DROP DATABASE IF EXISTS `{$this->copy}`");
        $this->cleanUpTempDirectories();
    }

    /** @return iterable<string, array{string, bool}> driver, whether the whole import runs in one tick */
    public static function runs(): iterable
    {
        yield 'pdo, one tick' => ['pdo', true];
        yield 'mysqli, one tick' => ['mysqli', true];
        yield 'pdo, a tick per statement' => ['pdo', false];
        yield 'mysqli, a tick per statement' => ['mysqli', false];
    }

    #[DataProvider('runs')]
    public function testADumpComesBackAsTheSameDatabase(string $driver, bool $oneTick): void
    {
        TortureDatabase::create($this->admin, $this->source);
        $directory = $this->dump($this->source, ['structureOnly' => ['log'], 'statementBytes' => 3000, 'bigCellBytes' => 1000, 'pieceBytes' => 700, 'maxPacket' => 8000]);
        $this->admin->exec("CREATE DATABASE `{$this->copy}` CHARACTER SET utf8mb4");

        $state = $this->import($directory, $driver, $oneTick, []);

        if ($oneTick) {
            self::assertSame(1, $state['ticks']);
        } else {
            self::assertGreaterThan(50, $state['ticks'], 'a tick per statement');
        }
        self::assertSame(0, $state['restarts']);
        self::assertSame($state['total'], $state['bytes']);
        self::assertFileDoesNotExist($directory . '/' . Importer::MARKER);

        foreach (['binkey', 'blobs', 'docs', 'items', 'nopk', 'types'] as $table) {
            self::assertSame($this->checksum($this->source, $table), $this->checksum($this->copy, $table), "CHECKSUM TABLE $table");
        }
        self::assertSame(0, (int) $this->value("SELECT COUNT(*) FROM `{$this->copy}`.log"), 'a structure-only table has no rows');
        self::assertSame(
            $this->value("SELECT GROUP_CONCAT(a ORDER BY a) FROM `{$this->source}`.a_dependent_view"),
            $this->value("SELECT GROUP_CONCAT(a ORDER BY a) FROM `{$this->copy}`.a_dependent_view"),
            'the view that uses another view',
        );
        $this->admin->exec("USE `{$this->copy}`");
        $this->admin->exec("INSERT INTO log (n, note) VALUES (1, 'x')");
        self::assertSame('[x]', $this->value('SELECT note FROM log'), 'the trigger exists and was not fired by the import');
    }

    public function testTheShopOfTheTestStackComesBackWithAnotherPrefix(): void
    {
        $this->source = '';   // the source is only read
        $directory = $this->dump('prestashop', ['structureOnly' => ['log', 'connections', 'connections_source', 'connections_page', 'guest', 'pagenotfound', 'page_viewed']], 'ps_');
        $this->admin->exec("CREATE DATABASE `{$this->copy}` CHARACTER SET utf8mb4");

        $state = $this->import($directory, 'pdo', true, ['prefixFrom' => 'ps_', 'prefixTo' => 'xy_']);

        self::assertSame('xy_', $state['prefixTo']);
        $tables = $this->column("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '{$this->copy}' ORDER BY 1");
        self::assertGreaterThan(250, count($tables));
        self::assertSame([], array_values(array_filter($tables, static fn (mixed $t): bool => !str_starts_with((string) $t, 'xy_'))), 'every table has the new prefix');
        $different = [];
        foreach ($tables as $table) {
            $original = 'ps_' . substr((string) $table, 3);
            if (in_array($original, ['ps_log', 'ps_connections', 'ps_connections_source', 'ps_connections_page', 'ps_guest', 'ps_pagenotfound', 'ps_page_viewed'], true)) {
                continue;
            }
            if ($this->checksum('prestashop', $original) !== $this->checksum($this->copy, (string) $table)) {
                $different[] = $table;
            }
        }
        self::assertSame([], $different, 'every table has the rows of the original');
    }

    public function testViewsAndTriggersStopAPrefixChangeBeforeAnythingHappens(): void
    {
        TortureDatabase::create($this->admin, $this->source);
        $directory = $this->dump($this->source, []);
        $this->admin->exec("CREATE DATABASE `{$this->copy}` CHARACTER SET utf8mb4");

        try {
            (new Importer($this->open($this->copy, 'pdo')))->initialState(['directory' => $directory, 'prefixFrom' => '', 'prefixTo' => 'xy_']);
            self::fail('The prefix change should have been refused.');
        } catch (ImportException $e) {
            self::assertStringContainsString('views or triggers', $e->getMessage());
        }
        self::assertSame([], $this->column("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '{$this->copy}'"));
    }

    public function testForeignKeysStopAPrefixChange(): void
    {
        $directory = $this->tempDirectory();
        file_put_contents($directory . '/0001_a.sql', "DROP TABLE IF EXISTS `a`;\nCREATE TABLE `a` (`id` int NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB;\n");
        file_put_contents($directory . '/0002_b.sql', "DROP TABLE IF EXISTS `b`;\nCREATE TABLE `b` (`id` int NOT NULL, CONSTRAINT `fk` FOREIGN KEY (`id`) REFERENCES `a` (`id`)) ENGINE=InnoDB;\n");
        $this->admin->exec("CREATE DATABASE `{$this->copy}` CHARACTER SET utf8mb4");

        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('foreign keys');
        (new Importer($this->open($this->copy, 'pdo')))->initialState(['directory' => $directory, 'prefixFrom' => '', 'prefixTo' => 'xy_']);
    }

    public function testACrashInTheMiddleOfAFileRestartsThatFile(): void
    {
        TortureDatabase::create($this->admin, $this->source);
        $directory = $this->dump($this->source, ['statementBytes' => 1500]);
        $this->admin->exec("CREATE DATABASE `{$this->copy}` CHARACTER SET utf8mb4");

        $importer = new Importer($this->open($this->copy, 'pdo'));
        $state = $importer->initialState(['directory' => $directory]);
        $items = array_search('0004_items.sql', $state['files'], true);
        self::assertIsInt($items);
        while ($state['file'] < $items || $state['offset'] === 0) {
            $state = (new Importer($this->open($this->copy, 'pdo')))->tick($state, new Budget(0.0))->state;
        }
        self::assertSame($items, $state['file']);
        self::assertGreaterThan(0, $state['offset']);

        // a process that ran one more statement and died before it saved its state: the work is in the database, the state is
        // the old one, and the marker file of the tick is still there
        $lost = (new Importer($this->open($this->copy, 'pdo')))->tick($state, new Budget(0.0));
        self::assertGreaterThan($state['offset'], $lost->state['offset']);
        file_put_contents($directory . '/' . Importer::MARKER, (string) $items);

        $finished = $this->resume($state);

        self::assertSame(1, $finished['restarts']);
        foreach (['binkey', 'blobs', 'docs', 'items', 'nopk', 'types'] as $table) {
            self::assertSame($this->checksum($this->source, $table), $this->checksum($this->copy, $table), "CHECKSUM TABLE $table");
        }
    }

    public function testAnErrorNamesTheFileAndThePositionButNotTheValues(): void
    {
        $directory = $this->tempDirectory();
        file_put_contents($directory . '/0001_t.sql', "DROP TABLE IF EXISTS `t`;\nCREATE TABLE `t` (`id` int NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB;\nINSERT INTO `t` (`id`) VALUES\n(7),\n(7);\n");
        $this->admin->exec("CREATE DATABASE `{$this->copy}` CHARACTER SET utf8mb4");
        $importer = new Importer($this->open($this->copy, 'pdo'));

        try {
            $importer->tick($importer->initialState(['directory' => $directory]), Budget::unlimited());
            self::fail('The import should have failed.');
        } catch (ImportException $e) {
            self::assertStringContainsString('"0001_t.sql" failed at byte', $e->getMessage());
            self::assertStringContainsString('INSERT INTO `t`', $e->getMessage());
            self::assertStringNotContainsString('(7)', $e->getMessage());
        }
        self::assertFileExists($directory . '/' . Importer::MARKER, 'after an error the file starts again on resume');
    }

    public function testTheEmptyValueOfAnEnumThatDoesNotListItIsRestoredAsItWas(): void
    {
        // field test, a real PrestaShop 9: ps_product.redirect_type is an ENUM without the empty option, and 112 rows still hold the empty value
        // (index 0, the error value of an ENUM, which an upgrade left in the column). The dump has '' for them and the import must not stop on the
        // warning "Data truncated for column": the same state comes back.
        $directory = $this->tempDirectory();
        file_put_contents($directory . '/0001_t.sql', "SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';\nDROP TABLE IF EXISTS `t`;\nCREATE TABLE `t` (`id` int NOT NULL, `redirect_type` enum('404','301-product','default') NOT NULL DEFAULT 'default', PRIMARY KEY (`id`)) ENGINE=InnoDB;\n"
            . "INSERT INTO `t` (`id`, `redirect_type`) VALUES\n(1,'404'),\n(2,''),\n(3,'default');\n");
        $this->admin->exec("CREATE DATABASE `{$this->copy}` CHARACTER SET utf8mb4");
        $db = $this->open($this->copy, 'pdo');
        $importer = new Importer($db);

        $importer->tick($importer->initialState(['directory' => $directory]), Budget::unlimited());

        self::assertSame(3, (int) $db->scalar('SELECT COUNT(*) FROM `t`'));
        self::assertSame([['id' => 2, 'index' => 0, 'value' => '']], array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'index' => (int) $r['i'], 'value' => (string) $r['redirect_type']], $db->select('SELECT id, redirect_type, redirect_type + 0 AS i FROM `t` WHERE id = 2')), 'index 0, as in the source');
    }

    public function testAValueThatIsTooLongForItsColumnStillStopsTheImport(): void
    {
        $directory = $this->tempDirectory();
        file_put_contents($directory . '/0001_t.sql', "SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';\nDROP TABLE IF EXISTS `t`;\nCREATE TABLE `t` (`id` int NOT NULL, `name` varchar(3) NOT NULL, `kind` enum('a','b') NOT NULL DEFAULT 'a', PRIMARY KEY (`id`)) ENGINE=InnoDB;\n"
            . "INSERT INTO `t` (`id`, `name`, `kind`) VALUES\n(1,'too long for it','a');\n");
        $this->admin->exec("CREATE DATABASE `{$this->copy}` CHARACTER SET utf8mb4");
        $importer = new Importer($this->open($this->copy, 'pdo'));

        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('damaged data');

        $importer->tick($importer->initialState(['directory' => $directory]), Budget::unlimited());
    }

    public function testNoSqlFilesIsAnError(): void
    {
        $this->expectException(ImportException::class);
        (new Importer($this->open('prestashop', 'pdo')))->initialState(['directory' => $this->tempDirectory()]);
    }

    // ---------------------------------------------------------------------------------------------------- helpers

    /**
     * @param array<string, mixed> $parameters
     */
    private function dump(string $database, array $parameters, string $prefix = ''): string
    {
        $directory = $this->tempDirectory();
        $dumper = new Dumper(Connection::open(new DatabaseSettings('db', 3306, $database, 'root', self::password(), $prefix, 'pdo')));
        $outcome = $dumper->tick($dumper->initialState([...$parameters, 'directory' => $directory, 'snapshot' => true]), Budget::unlimited());
        self::assertTrue($outcome->finished);

        return $directory;
    }

    /**
     * Runs the import to its end; every tick in a "new process": new connection and importer, the state through JSON.
     *
     * @param array<string, mixed> $parameters
     *
     * @return array<string, mixed> the final state, plus `ticks`
     */
    private function import(string $directory, string $driver, bool $oneTick, array $parameters): array
    {
        $state = (new Importer($this->open($this->copy, $driver)))->initialState([...$parameters, 'directory' => $directory]);

        return $this->resume($state, $driver, $oneTick);
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array<string, mixed>
     */
    private function resume(array $state, string $driver = 'pdo', bool $oneTick = false): array
    {
        for ($tick = 1; $tick <= 20000; ++$tick) {
            $outcome = (new Importer($this->open($this->copy, $driver)))->tick(
                json_decode(json_encode($state, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR),
                $oneTick ? Budget::unlimited() : new Budget(0.0),
            );
            $state = $outcome->state;
            if ($outcome->finished) {
                return [...$state, 'ticks' => $tick];
            }
        }

        self::fail('The import did not finish.');
    }

    private function open(string $database, string $driver): Connection
    {
        return Connection::open(new DatabaseSettings('db', 3306, $database, 'root', self::password(), '', $driver));
    }

    private function checksum(string $database, string $table): string
    {
        $statement = $this->admin->query("CHECKSUM TABLE `$database`.`$table`");
        self::assertNotFalse($statement);
        $row = $statement->fetch(PDO::FETCH_NUM);

        return is_array($row) ? (string) $row[1] : '';
    }

    private function value(string $sql): mixed
    {
        $statement = $this->admin->query($sql);
        self::assertNotFalse($statement);

        return $statement->fetchColumn();
    }

    /** @return list<mixed> */
    private function column(string $sql): array
    {
        $statement = $this->admin->query($sql);
        self::assertNotFalse($statement);

        return array_values($statement->fetchAll(PDO::FETCH_COLUMN));
    }

    private static function password(): string
    {
        $password = getenv('DB_ROOT_PASSWORD');

        return $password === false || $password === '' ? 'prestashop' : $password;
    }
}
