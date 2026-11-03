<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Integration;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use ThreadSafe\Backup\Dumper;
use ThreadSafe\Database\Connection;
use ThreadSafe\Database\DatabaseSettings;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Restore\Importer;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * A database whose contents are hostile (docs/PHASE12.md section 8, the review of the import): cells and names that look like the end of a statement,
 * a `DELIMITER` command, a comment, an executable comment, an escaped quote, a NUL byte. The dump of such a database must come back as the same
 * database and do nothing else: the importer runs every statement of a dump as it is, as the restore's database user, so a cell that the dumper or
 * the splitter treats wrongly would be a statement of the attacker's choice (a table dropped, another database dropped, a table made).
 */
final class HostileDumpTest extends IntegrationTestCase
{
    use TempDirectory;

    /** What a cell or a name may say, to a dumper or a splitter that is not careful. */
    private const array PAYLOADS = [
        "'; DROP TABLE victim; --",
        "\\'; DROP TABLE victim; --",
        "\\\\'; DROP TABLE victim; --",
        "'); CREATE TABLE pwned (x INT); -- ",
        "\"; DROP DATABASE %CANARY%; --",
        "x'\nDELIMITER //\nDROP DATABASE %CANARY%//\nDELIMITER ;\n-- ",
        "\nDELIMITER //\nDROP DATABASE %CANARY%//\n",
        '/*! DROP TABLE victim */',
        '*/ DROP TABLE victim; /*',
        "-- \nDROP TABLE victim;",
        "--\nDROP TABLE victim;",
        "#\nDROP TABLE victim;",
        '` ; DROP TABLE victim; `',
        "\0'; DROP TABLE victim; --",
        "\x1a'; DROP TABLE victim; --",
        "\r\n; DROP TABLE victim;\r\n",
        "ä😀'; DROP TABLE victim; -- ü",
        "100% sure? :name %s {0} \$1 \${x}",
        ";",
        ';;;;',
        "'",
        "''",
        '\\',
        '\\\\',
        "\\\n",
        '',
    ];

    private PDO $admin;

    private string $source = '';

    private string $copy = '';

    private string $canary = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = new PDO('mysql:host=db;charset=utf8mb4', 'root', self::password(), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $suffix = bin2hex(random_bytes(4));
        $this->source = "threadsafe_host_src_$suffix";
        $this->copy = "threadsafe_host_copy_$suffix";
        $this->canary = "threadsafe_host_canary_$suffix";
    }

    protected function tearDown(): void
    {
        foreach ([$this->source, $this->copy, $this->canary] as $database) {
            if ($database !== '') {
                $this->admin->exec("DROP DATABASE IF EXISTS `$database`");
            }
        }
        $this->cleanUpTempDirectories();
    }

    /** @return iterable<string, array{string, bool}> */
    public static function runs(): iterable
    {
        yield 'pdo, one tick' => ['pdo', true];
        yield 'mysqli, one tick' => ['mysqli', true];
        yield 'pdo, a tick per statement' => ['pdo', false];
        yield 'mysqli, a tick per statement' => ['mysqli', false];
    }

    #[DataProvider('runs')]
    public function testAHostileDatabaseComesBackAsItWasAndNothingElseHappens(string $driver, bool $oneTick): void
    {
        $this->createSource();
        $tablesBefore = $this->tables($this->source);

        $directory = $this->tempDirectory();
        $dumper = new Dumper(Connection::open(new DatabaseSettings('db', 3306, $this->source, 'root', self::password(), '', 'pdo')));
        $outcome = $dumper->tick($dumper->initialState(['directory' => $directory, 'snapshot' => true, 'statementBytes' => 300, 'bigCellBytes' => 200, 'pieceBytes' => 100, 'maxPacket' => 4000]), Budget::unlimited());
        self::assertTrue($outcome->finished);
        $this->admin->exec("CREATE DATABASE `{$this->copy}` CHARACTER SET utf8mb4");

        $this->import($directory, $driver, $oneTick);

        self::assertSame($tablesBefore, $this->tables($this->copy), 'the same tables, and none that a cell made');
        foreach (['evil', 'we`ird; DROP TABLE victim; --', 'victim'] as $table) {
            self::assertSame($this->checksum($this->source, $table), $this->checksum($this->copy, $table), "CHECKSUM TABLE $table");
        }
        self::assertSame(count(self::PAYLOADS) + 2, (int) $this->value("SELECT COUNT(*) FROM `{$this->copy}`.evil"));
        self::assertSame(3, (int) $this->value("SELECT COUNT(*) FROM `{$this->copy}`.victim"), 'the table that the payloads name is intact');
        self::assertSame(2, (int) $this->value("SELECT COUNT(*) FROM `{$this->canary}`.canary"), 'the other database is intact');
        self::assertSame(0, (int) $this->value("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '{$this->copy}' AND TABLE_NAME = 'pwned'"));

        $this->admin->exec("USE `{$this->copy}`");
        $this->admin->exec("INSERT INTO evil (id, v, b) VALUES (9999, 'new', NULL)");
        self::assertSame("new;\nDELIMITER //\nDROP DATABASE x// -- ", $this->value('SELECT v FROM evil WHERE id = 9999'), 'the trigger came back with its text whole, and does only what it says');
    }

    private function createSource(): void
    {
        $this->admin->exec("CREATE DATABASE `{$this->canary}` CHARACTER SET utf8mb4");
        $this->admin->exec("CREATE TABLE `{$this->canary}`.canary (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB");
        $this->admin->exec("INSERT INTO `{$this->canary}`.canary VALUES (1), (2)");

        $this->admin->exec("CREATE DATABASE `{$this->source}` CHARACTER SET utf8mb4");
        $this->admin->exec("USE `{$this->source}`");
        $engine = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
        $this->admin->exec("CREATE TABLE victim (id INT NOT NULL PRIMARY KEY, secret VARCHAR(40) NOT NULL) $engine");
        $this->admin->exec("INSERT INTO victim VALUES (1, 'a'), (2, 'b'), (3, 'c')");
        $this->admin->exec("CREATE TABLE evil (id INT NOT NULL PRIMARY KEY, v LONGTEXT NULL, b LONGBLOB NULL) $engine");
        $insert = $this->admin->prepare('INSERT INTO evil (id, v, b) VALUES (?, ?, ?)');
        $payloads = array_map(fn (string $payload): string => str_replace('%CANARY%', $this->canary, $payload), self::PAYLOADS);
        foreach ($payloads as $i => $payload) {
            $insert->bindValue(1, $i + 1);
            $insert->bindValue(2, $payload);
            $insert->bindValue(3, $payload . "\xc3\x28\xff", PDO::PARAM_LOB);   // bytes that are no UTF-8
            $insert->execute();
        }
        // a cell that is longer than a statement is cut into pieces: the pieces are written as UPDATE ... CONCAT, each with a payload in it
        $insert->bindValue(1, count($payloads) + 1);
        $insert->bindValue(2, str_repeat("'; DROP TABLE victim; -- \\", 40));
        $insert->bindValue(3, str_repeat("\\'\0", 100), PDO::PARAM_LOB);
        $insert->execute();
        $insert->bindValue(1, count($payloads) + 2);
        $insert->bindValue(2, null, PDO::PARAM_NULL);
        $insert->bindValue(3, null, PDO::PARAM_NULL);
        $insert->execute();

        // names that look like SQL
        $this->admin->exec("CREATE TABLE `we``ird; DROP TABLE victim; --` (`co``l'; --` INT NOT NULL, `x\"y` VARCHAR(80) NULL DEFAULT '''; DROP TABLE victim; --' COMMENT 'a; DROP TABLE victim; -- ''b''', PRIMARY KEY (`co``l'; --`)) $engine COMMENT='; DROP TABLE victim; --'");
        $this->admin->exec("INSERT INTO `we``ird; DROP TABLE victim; --` VALUES (1, 'x'), (2, NULL)");

        // a trigger whose text has a delimiter, a comment and the word DELIMITER in it
        $this->admin->exec("CREATE TRIGGER evil_bi BEFORE INSERT ON evil FOR EACH ROW BEGIN /* DELIMITER // ; DROP TABLE victim; */ SET NEW.v = CONCAT(NEW.v, ';\nDELIMITER //\nDROP DATABASE x// -- '); END");
    }

    /** @return list<string> */
    private function tables(string $database): array
    {
        $statement = $this->admin->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$database' ORDER BY TABLE_NAME");
        self::assertNotFalse($statement);

        return array_values(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    private function import(string $directory, string $driver, bool $oneTick): void
    {
        $state = (new Importer($this->open($driver)))->initialState(['directory' => $directory]);
        for ($tick = 1; $tick <= 20000; ++$tick) {
            $outcome = (new Importer($this->open($driver)))->tick(
                json_decode(json_encode($state, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR),
                $oneTick ? Budget::unlimited() : new Budget(0.0),
            );
            $state = $outcome->state;
            if ($outcome->finished) {
                return;
            }
        }

        self::fail('The import did not finish.');
    }

    private function open(string $driver): Connection
    {
        return Connection::open(new DatabaseSettings('db', 3306, $this->copy, 'root', self::password(), '', $driver));
    }

    private function checksum(string $database, string $table): string
    {
        $statement = $this->admin->query('CHECKSUM TABLE `' . $database . '`.`' . str_replace('`', '``', $table) . '`');
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

    private static function password(): string
    {
        $password = getenv('DB_ROOT_PASSWORD');

        return $password === false || $password === '' ? 'prestashop' : $password;
    }
}
