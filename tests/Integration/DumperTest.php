<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Integration;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use ThreadSafe\Backup\DumpState;
use ThreadSafe\Backup\Dumper;
use ThreadSafe\Database\Connection;
use ThreadSafe\Database\DatabaseSettings;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Tests\Support\TortureDatabase;

/**
 * The dumper against a real server: every column type, composite and binary keys, tables without a key, huge cells,
 * invisible and generated columns, views, triggers - dumped (in one go and tick by tick), imported again with the plain
 * `mariadb` client, and compared with the source. Runs with both PHP interfaces on whichever server the stack runs.
 */
final class DumperTest extends IntegrationTestCase
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
        $this->source = "threadsafe_dump_src_$suffix";
        $this->copy = "threadsafe_dump_copy_$suffix";
    }

    protected function tearDown(): void
    {
        if ($this->source !== '') {
            $this->admin->exec("DROP DATABASE IF EXISTS `{$this->source}`");
            $this->admin->exec("DROP DATABASE IF EXISTS `{$this->copy}`");
        }
        $this->cleanUpTempDirectories();
    }

    /** @return iterable<string, array{string, bool}> driver, whether the whole dump runs in one tick */
    public static function runs(): iterable
    {
        yield 'pdo, one tick, snapshot' => ['pdo', true];
        yield 'mysqli, one tick, snapshot' => ['mysqli', true];
        yield 'pdo, many ticks' => ['pdo', false];
        yield 'mysqli, many ticks' => ['mysqli', false];
    }

    #[DataProvider('runs')]
    public function testDumpAndImportGiveTheSameDatabase(string $driver, bool $oneTick): void
    {
        $this->createSource();
        $directory = $this->tempDirectory();

        $state = $this->dump($driver, $oneTick, $directory, [
            'structureOnly' => ['log'],
            'statementBytes' => 3000,
            'bigCellBytes' => 1000,
            'pieceBytes' => 700,
            'maxPacket' => 8000,
        ]);

        self::assertSame($oneTick ? 'snapshot' : 'table', $state->consistency);
        if (!$oneTick) {
            self::assertGreaterThan(10, $state->ticks, 'With a budget that is always used up, every table and every further page is a tick of its own.');
        } else {
            self::assertSame(1, $state->ticks);
        }
        self::assertSame([['type' => 'function', 'name' => 'f_one']], $state->unsupported);
        self::assertCount(1, $state->warnings, implode("
", $state->warnings));
        self::assertStringStartsWith('Column "blobs"."notes" holds a cell of ', $state->warnings[0]);
        self::assertStringContainsString('more than max_allowed_packet', $state->warnings[0]);

        $tables = array_column($state->done, null, 'table');
        self::assertSame(['binkey', 'blobs', 'docs', 'items', 'log', 'nopk', 'types'], array_keys($tables));
        self::assertTrue($tables['log']['structureOnly']);
        self::assertSame(0, $tables['log']['rows']);
        self::assertSame(237, $tables['items']['rows']);
        self::assertSame(1500, $tables['nopk']['rows']);
        self::assertSame('0001_binkey.sql', $tables['binkey']['file']);
        foreach ($tables as $table) {
            self::assertSame(filesize($directory . '/' . $table['file']), $table['bytes'], 'The recorded size is the size of the file.');
        }
        self::assertSame(['_views.sql', '_triggers.sql'], array_column($state->objectFiles, 'file'));

        $this->assertDumpText($directory);
        $this->importAll($directory, $state);
        $this->assertSameDatabase();
        $this->assertObjectsWork();
    }

    public function testTheShopOfTheTestStackSurvivesTheRoundTrip(): void
    {
        $settings = new DatabaseSettings('db', 3306, 'prestashop', 'root', self::password(), 'ps_', 'pdo');
        $this->source = 'prestashop';   // the source is only read; tearDown drops the copy
        $this->copy = 'threadsafe_dump_copy_' . bin2hex(random_bytes(4));
        $directory = $this->tempDirectory();

        $dumper = new Dumper(Connection::open($settings));
        $state = $dumper->initialState(['directory' => $directory, 'snapshot' => true, 'structureOnly' => ['log', 'connections', 'connections_source', 'connections_page', 'guest', 'pagenotfound', 'page_viewed']]);
        $outcome = $dumper->tick($state, Budget::unlimited());
        self::assertTrue($outcome->finished);
        $result = DumpState::fromArray($outcome->state);

        self::assertGreaterThan(250, count($result->done));
        self::assertSame([], $result->warnings);
        $this->admin->exec("CREATE DATABASE `{$this->copy}` CHARACTER SET utf8mb4");
        $this->importAll($directory, $result);

        $structureOnly = array_column(array_filter($result->done, static fn (array $t): bool => $t['structureOnly']), 'table');
        self::assertContains('ps_log', $structureOnly);
        $different = [];
        foreach ($result->done as $table) {
            if ($table['structureOnly']) {
                self::assertSame(0, (int) $this->value("SELECT COUNT(*) FROM `{$this->copy}`.`{$table['table']}`"), $table['table']);
                continue;
            }
            if ($this->checksum($this->source, $table['table']) !== $this->checksum($this->copy, $table['table'])) {
                $different[] = $table['table'];
            }
        }
        self::assertSame([], $different, 'Every table of the shop must come back with the same checksum.');
        $this->source = '';   // do not drop the shop
    }

    /**
     * Cells far above the limit are read in pieces with little memory. The pieces put together give the cell again - checked
     * by reading the dump file back line by line, because a cell of 40 MB cannot be imported by a server whose
     * `max_allowed_packet` is lower (no SQL can build it), which the dump warns about.
     */
    public function testAHugeCellIsReadInPiecesWithLittleMemory(): void
    {
        $default = (int) $this->value('SELECT @@global.max_allowed_packet');
        $this->source = 'threadsafe_dump_huge_' . bin2hex(random_bytes(4));
        $this->copy = $this->source . '_copy';
        try {
            $this->admin->exec('SET GLOBAL max_allowed_packet = ' . (256 * 1048576));
            $creator = new PDO('mysql:host=db;charset=utf8mb4', 'root', self::password(), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);   // a new session has the new limit
            $creator->exec("CREATE DATABASE `{$this->source}` CHARACTER SET utf8mb4");
            $creator->exec("USE `{$this->source}`");
            $creator->exec('CREATE TABLE huge_blob (id INT NOT NULL PRIMARY KEY, data LONGBLOB NULL) ENGINE=InnoDB');
            $creator->exec("INSERT INTO huge_blob VALUES (1, RPAD(_binary '', 41943040, _binary 'abcdefghijklmnopqrstuvwxyz0123456789'))");
            $creator->exec('CREATE TABLE huge_text (id INT NOT NULL PRIMARY KEY, notes LONGTEXT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
            $creator->exec("INSERT INTO huge_text VALUES (1, REPEAT('ü😀x', 2000000))");   // 14 MB, 6 million characters
            $expected = (string) $this->value('SELECT MD5(data) FROM huge_blob', $creator);
            $expectedText = (string) $this->value('SELECT MD5(notes) FROM huge_text', $creator);
            unset($creator);
        } finally {
            $this->admin->exec("SET GLOBAL max_allowed_packet = $default");
        }

        $directory = $this->tempDirectory();
        $settings = new DatabaseSettings('db', 3306, $this->source, 'root', self::password(), '', 'pdo');
        $dumper = new Dumper(Connection::open($settings));
        $state = $dumper->initialState(['directory' => $directory]);
        gc_collect_cycles();
        $before = memory_get_usage();
        memory_reset_peak_usage();
        $outcome = $dumper->tick($state, Budget::unlimited());
        $used = memory_get_peak_usage() - $before;
        self::assertTrue($outcome->finished);
        self::assertLessThan(48 * 1048576, $used, 'A cell of 40 MB is never held whole: peak ' . round($used / 1048576) . ' MB.');

        $packet = (int) Connection::open($settings)->scalar('SELECT @@max_allowed_packet');
        $warnings = DumpState::fromArray($outcome->state)->warnings;
        self::assertSame(41943040 > $packet, $warnings !== [], 'The 40 MB cell is flagged when it is above this server\'s limit: ' . implode(' | ', $warnings));

        // put the pieces together again from the file
        $handle = fopen($directory . '/0001_huge_blob.sql', 'rb');
        self::assertNotFalse($handle);
        $hash = hash_init('md5');
        $pieces = 0;
        while (($line = fgets($handle)) !== false) {
            if (preg_match('/^\(1,0x([0-9A-F]+)\);$|^UPDATE `huge_blob` SET `data` = CONCAT\(`data`, 0x([0-9A-F]+)\) WHERE `id` = 1;$/', rtrim($line, "\n"), $m) === 1) {
                hash_update($hash, (string) hex2bin($m[2] ?? $m[1]));
                ++$pieces;
            }
        }
        fclose($handle);
        self::assertSame(20, $pieces, '40 MB in pieces of 2 MB');
        self::assertSame($expected, hash_final($hash));

        // the text cell does fit under the default limit and goes through the plain client
        $this->admin->exec("CREATE DATABASE `{$this->copy}` CHARACTER SET utf8mb4");
        $this->import($directory . '/0002_huge_text.sql');
        self::assertSame($expectedText, (string) $this->value("SELECT MD5(notes) FROM `{$this->copy}`.huge_text"));
    }

    /**
     * @param array<string, mixed> $parameters
     *
     */
    private function dump(string $driver, bool $oneTick, string $directory, array $parameters): DumpState
    {
        $settings = new DatabaseSettings('db', 3306, $this->source, 'root', self::password(), '', $driver);
        $state = (new Dumper(Connection::open($settings)))->initialState([...$parameters, 'directory' => $directory, 'snapshot' => $oneTick]);

        for ($tick = 1; $tick <= 5000; ++$tick) {
            // a new process every tick: new connection, new object, the state through JSON like the run store does
            $dumper = new Dumper(Connection::open($settings));
            $outcome = $dumper->tick(json_decode(json_encode($state, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR), $oneTick ? Budget::unlimited() : new Budget(0.0));
            $state = $outcome->state;
            if ($outcome->finished) {
                return DumpState::fromArray($state);
            }
            // what a process that died after saving its state could have left behind
            $current = $state['current'] ?? null;
            if (is_array($current) && is_string($current['file'] ?? null)) {
                file_put_contents($directory . '/' . $current['file'], "\nJUNK THAT MUST BE CUT OFF;\n", FILE_APPEND);
            }
        }

        self::fail('The dump did not finish.');
    }

    private function assertDumpText(string $directory): void
    {
        $types = (string) file_get_contents($directory . '/0007_types.sql');
        self::assertStringContainsString('`c_inv`', $types, 'An invisible column is part of the explicit column list.');
        self::assertStringNotContainsString('`c_gen`,', str_replace(' ', '', explode('VALUES', $types)[0]), 'The head of the INSERT has no generated column.');
        self::assertStringContainsString('`c_def`', $types, 'A column with an expression as its default is not a generated column.');
        self::assertStringContainsString("\nINSERT INTO `types` (", $types);

        $blobs = (string) file_get_contents($directory . '/0002_blobs.sql');
        self::assertStringContainsString('UPDATE `blobs` SET `data` = CONCAT(`data`, 0x', $blobs);
        self::assertStringContainsString('`stamp` = `stamp` WHERE `id` = ', $blobs, 'ON UPDATE columns keep their value.');
        $longest = max(array_map('strlen', explode("\n", $blobs)));
        self::assertLessThan(12000, $longest, 'No statement carries a whole large cell.');

        $docs = (string) file_get_contents($directory . '/0003_docs.sql');
        self::assertStringNotContainsString('UPDATE', $docs, 'A JSON document must stay valid in every statement: it is written whole.');

        $log = (string) file_get_contents($directory . '/0005_log.sql');
        self::assertStringContainsString('CREATE TABLE `log`', $log);
        self::assertStringNotContainsString('INSERT', $log);
        self::assertStringContainsString('DELIMITER ;;', (string) file_get_contents($directory . '/_triggers.sql'));
    }

    private function importAll(string $directory, DumpState $state): void
    {
        $this->admin->exec("CREATE DATABASE IF NOT EXISTS `{$this->copy}` CHARACTER SET utf8mb4");
        foreach ([...array_column($state->done, 'file'), ...array_column($state->objectFiles, 'file')] as $file) {
            $this->import($directory . '/' . $file);
        }
    }

    /** Imports a file with the plain command line client, as a person with an emergency would. */
    private function import(string $file): void
    {
        $process = proc_open(
            ['mariadb', '--protocol=tcp', '-h', 'db', '-u', 'root', '--default-character-set=utf8mb4', $this->copy],
            [0 => ['file', $file, 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['MYSQL_PWD' => self::password(), 'PATH' => (string) getenv('PATH')],
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start the mariadb client.');
        }
        stream_get_contents($pipes[1]);
        $error = (string) stream_get_contents($pipes[2]);
        $code = proc_close($process);
        self::assertSame(0, $code, "Importing $file failed: " . substr($error, 0, 500));
    }

    private function assertSameDatabase(): void
    {
        foreach (['binkey', 'blobs', 'docs', 'items', 'nopk', 'types'] as $table) {
            self::assertSame($this->checksum($this->source, $table), $this->checksum($this->copy, $table), "CHECKSUM TABLE $table");
            self::assertSame(
                (int) $this->value("SELECT COUNT(*) FROM `{$this->source}`.`$table`"),
                (int) $this->value("SELECT COUNT(*) FROM `{$this->copy}`.`$table`"),
                "row count of $table",
            );
        }
        self::assertSame(0, (int) $this->value("SELECT COUNT(*) FROM `{$this->copy}`.`log`"), 'A structure-only table has no rows.');

        // every column of every table with a key, compared value by value with <=> (NULL equals NULL, no rounding)
        foreach (['binkey' => ['id'], 'blobs' => ['id'], 'docs' => ['id'], 'items' => ['a', 'b'], 'types' => ['id']] as $table => $key) {
            $join = implode(' AND ', array_map(static fn (string $k): string => "s.`$k` = c.`$k`", $key));
            $columns = $this->column("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '{$this->source}' AND TABLE_NAME = '$table' ORDER BY ORDINAL_POSITION");
            self::assertNotEmpty($columns);
            foreach ($columns as $column) {
                $bad = $this->value(
                    "SELECT COUNT(*) FROM `{$this->source}`.`$table` s LEFT JOIN `{$this->copy}`.`$table` c ON $join WHERE c.`{$key[0]}` IS NULL OR NOT (s.`$column` <=> c.`$column`)",
                );
                self::assertSame(0, (int) $bad, "$table.$column differs");
            }
        }
        self::assertSame(
            $this->value("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '{$this->source}' AND TABLE_NAME = 'types'"),
            $this->value("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '{$this->copy}' AND TABLE_NAME = 'types'"),
            'The invisible and the generated columns exist in the copy.',
        );
    }

    private function assertObjectsWork(): void
    {
        $this->admin->exec("USE `{$this->copy}`");
        self::assertSame(
            $this->value("SELECT GROUP_CONCAT(a ORDER BY a) FROM `{$this->source}`.a_dependent_view"),
            $this->value('SELECT GROUP_CONCAT(a ORDER BY a) FROM a_dependent_view'),
            'The view that uses another view comes after it.',
        );
        $this->admin->exec("INSERT INTO log (n, note) VALUES (1, 'x')");
        self::assertSame('[x]', $this->value('SELECT note FROM log'), 'The trigger exists and was not fired by the import.');
        self::assertSame(2, (int) $this->value('SELECT n FROM log'));
    }

    private function checksum(string $database, string $table): string
    {
        $statement = $this->admin->query("CHECKSUM TABLE `$database`.`$table`");
        self::assertNotFalse($statement);
        $row = $statement->fetch(PDO::FETCH_NUM);

        return is_array($row) ? (string) $row[1] : '';
    }

    /** The first column of the first row. */
    private function value(string $sql, ?PDO $connection = null): mixed
    {
        $statement = ($connection ?? $this->admin)->query($sql);
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

    // -------------------------------------------------------------------------------------------- the source

    private function createSource(): void
    {
        TortureDatabase::create($this->admin, $this->source);
    }
}
