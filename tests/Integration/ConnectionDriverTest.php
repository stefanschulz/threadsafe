<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use ThreadSafe\Database\Connection;
use ThreadSafe\Database\ConnectionFailed;
use ThreadSafe\Database\DatabaseError;
use ThreadSafe\Database\DatabaseSettings;
use ThreadSafe\Database\SchemaInspector;
use ThreadSafe\Tests\Support\ScratchShop;

/**
 * Both PHP interfaces to the database behave the same: every test runs with `pdo` and with `mysqli` against the
 * database server of the test stack (MariaDB or MySQL, whichever the stack runs on).
 */
final class ConnectionDriverTest extends IntegrationTestCase
{
    private ScratchShop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = ScratchShop::create();
        $this->shop->pdo()->exec('CREATE TABLE ps_t (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, label VARCHAR(100) NULL, number INT NULL, data LONGBLOB NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    protected function tearDown(): void
    {
        $this->shop->destroy();
    }

    /** @return iterable<string, array{string}> */
    public static function drivers(): iterable
    {
        yield 'pdo' => ['pdo'];
        yield 'mysqli' => ['mysqli'];
    }

    private function open(string $driver, ?string $password = null): Connection
    {
        $s = $this->shop->settings;

        return Connection::open(new DatabaseSettings($s->host, $s->port, $s->name, $s->user, $password ?? $s->password, $s->prefix, $driver));
    }

    #[DataProvider('drivers')]
    public function testConnectsAndReportsTheServer(string $driver): void
    {
        $db = $this->open($driver);

        self::assertSame($driver, $db->driverName());
        self::assertTrue($db->server->isSupported(), $db->server->describe());
        self::assertContains($db->server->type, ['mariadb', 'mysql']);
        self::assertSame($db->server->raw, $db->serverVersion());
    }

    public function testAutoPrefersPdo(): void
    {
        self::assertSame('pdo', $this->open('auto')->driverName());
    }

    #[DataProvider('drivers')]
    public function testSelectReturnsTypedRows(string $driver): void
    {
        $db = $this->open($driver);

        self::assertSame([['a' => 1, 'b' => 'x', 'c' => null]], $db->select("SELECT 1 AS a, 'x' AS b, NULL AS c"));
        self::assertSame([], $db->select('SELECT * FROM ps_t'));
        self::assertNull($db->scalar('SELECT label FROM ps_t'));
        self::assertSame(7, $db->scalar('SELECT 7'));
        self::assertSame('0', (string) $db->scalar('SELECT COUNT(*) FROM ps_t'));
    }

    #[DataProvider('drivers')]
    public function testParametersNullUtf8AndBinary(string $driver): void
    {
        $db = $this->open($driver);
        $text = "Größe ✓ 日本語 😀 'quote' \"double\" back\\slash";
        $binary = "\x00\x01\xff\xfe\r\n\x00";

        self::assertSame(1, $db->execute('INSERT INTO ps_t (label, number, data) VALUES (?, ?, ?)', [$text, 42, $binary]));
        self::assertSame(1, $db->execute('INSERT INTO ps_t (label, number, data) VALUES (?, ?, ?)', [null, null, null]));

        $rows = $db->select('SELECT label, number, data FROM ps_t ORDER BY id');
        self::assertSame($text, $rows[0]['label']);
        self::assertEquals(42, $rows[0]['number']);
        self::assertSame($binary, $rows[0]['data']);
        self::assertSame([null, null, null], array_values($rows[1]));
        self::assertSame('1', (string) $db->scalar('SELECT COUNT(*) FROM ps_t WHERE label = ?', [$text]));
        self::assertSame('1', (string) $db->scalar('SELECT COUNT(*) FROM ps_t WHERE number <=> ?', [null]));
        self::assertSame('0', (string) $db->scalar('SELECT COUNT(*) FROM ps_t WHERE number = ?', [null]), 'NULL never equals a value.');
    }

    #[DataProvider('drivers')]
    public function testExecuteCountsChangedRowsAndLastInsertId(string $driver): void
    {
        $db = $this->open($driver);
        $db->execute("INSERT INTO ps_t (label) VALUES ('a'), ('b'), ('c')");
        $firstId = $db->lastInsertId();

        self::assertGreaterThan(0, $firstId);
        self::assertSame(2, $db->execute("UPDATE ps_t SET number = 1 WHERE label IN ('a', 'b')"));
        self::assertSame(0, $db->execute("UPDATE ps_t SET number = 1 WHERE label IN ('a', 'b')"), 'Rows that already have the value are not counted.');
        self::assertSame(3, $db->execute('DELETE FROM ps_t'));
        $db->execute("INSERT INTO ps_t (label) VALUES ('d')");
        self::assertGreaterThan($firstId, $db->lastInsertId());
    }

    #[DataProvider('drivers')]
    public function testTransactionCommitsAndRollsBack(string $driver): void
    {
        $db = $this->open($driver);

        $result = $db->transaction(function () use ($db): string {
            $db->execute("INSERT INTO ps_t (label) VALUES ('kept')");

            return 'result';
        });
        self::assertSame('result', $result);

        $caught = null;
        try {
            $db->transaction(function () use ($db): void {
                $db->execute("INSERT INTO ps_t (label) VALUES ('lost')");
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException $e) {
            $caught = $e;
        }
        self::assertSame('boom', $caught->getMessage(), 'The exception comes out of transaction().');

        self::assertSame(['kept'], array_column($db->select('SELECT label FROM ps_t ORDER BY id'), 'label'));
        // After a rollback the connection is usable and not stuck in a transaction.
        $db->execute("INSERT INTO ps_t (label) VALUES ('after')");
        self::assertSame('2', (string) $db->scalar('SELECT COUNT(*) FROM ps_t'));
    }

    #[DataProvider('drivers')]
    public function testErrorsAreDatabaseErrors(string $driver): void
    {
        $db = $this->open($driver);

        try {
            $db->execute('SELEKT 1');
            self::fail('A syntax error must be an error.');
        } catch (DatabaseError $e) {
            self::assertNotSame('', $e->getMessage());
        }
        try {
            $db->select('SELECT * FROM ps_does_not_exist');
            self::fail('A missing table must be an error.');
        } catch (DatabaseError $e) {
            self::assertStringContainsString('ps_does_not_exist', $e->getMessage());
        }

        $db->execute("INSERT INTO ps_t (label) VALUES ('still works')");
        self::assertSame('still works', $db->scalar('SELECT label FROM ps_t'));
    }

    #[DataProvider('drivers')]
    public function testQuotingAndTableExists(string $driver): void
    {
        $db = $this->open($driver);
        $nasty = "it's a \"test\" with \\ and %_ \n newline";

        self::assertSame($nasty, $db->scalar('SELECT ' . $db->quotedString($nasty)));
        self::assertSame('`weird``name`', $db->quote('weird`name'));
        self::assertTrue($db->tableExists('ps_t'));
        self::assertFalse($db->tableExists('ps_nope'));
        self::assertSame('t', $db->unprefixed('ps_t'));
        self::assertSame('ps_t', $db->table('t'));
    }

    #[DataProvider('drivers')]
    public function testSchemaInspectorWorksWithEveryDriver(string $driver): void
    {
        $tables = (new SchemaInspector($this->open($driver)))->tables();

        self::assertArrayHasKey('ps_t', $tables);
        self::assertSame(['id'], $tables['ps_t']->primaryKey);
        self::assertEqualsCanonicalizing(['label'], $tables['ps_t']->textColumns, 'LONGBLOB is not a text column.');
        self::assertArrayHasKey('ps_configuration', $tables);
        self::assertContains('value', $tables['ps_configuration']->textColumns);
    }

    #[DataProvider('drivers')]
    public function testWrongPasswordIsAConnectionFailure(string $driver): void
    {
        $this->expectException(ConnectionFailed::class);
        $this->expectExceptionMessage('Cannot connect to database');

        $this->open($driver, 'definitely-not-the-password');
    }
}
