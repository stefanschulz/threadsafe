<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Backup;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Backup\Column;
use ThreadSafe\Backup\ColumnKind;
use ThreadSafe\Backup\SqlWriter;

final class SqlWriterTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function strings(): iterable
    {
        yield 'plain' => ['abc', "'abc'"];
        yield 'empty' => ['', "''"];
        yield 'single quote' => ["it's", "'it\\'s'"];
        yield 'double quote stays' => ['say "x"', "'say \"x\"'"];
        yield 'backslash' => ['a\\b', "'a\\\\b'"];
        yield 'backslash before quote' => ["\\'", "'\\\\\\''"];
        yield 'control characters' => ["a\0b\nc\rd\x1ae", "'a\\0b\\nc\\rd\\Ze'"];
        yield 'tab is kept' => ["a\tb", "'a\tb'"];
        yield 'unicode is kept' => ['Größe 😀 日本', "'Größe 😀 日本'"];
        yield 'like wildcards are kept' => ['50%_off', "'50%_off'"];
    }

    #[DataProvider('strings')]
    public function testQuote(string $value, string $expected): void
    {
        self::assertSame($expected, SqlWriter::quote($value));
    }

    public function testIdentifierAndHex(): void
    {
        self::assertSame('`a`', SqlWriter::identifier('a'));
        self::assertSame('`we``ird`', SqlWriter::identifier('we`ird'));
        self::assertSame("''", SqlWriter::hex(''));
        self::assertSame('0x00FF10', SqlWriter::hex("\x00\xff\x10"));
    }

    /** @return iterable<string, array{mixed, string, string, bool}> value, column type, expected literal, value is hex */
    public static function literals(): iterable
    {
        yield 'NULL for every kind' => [null, 'int', 'NULL', false];
        yield 'NULL text' => [null, 'varchar', 'NULL', false];
        yield 'int' => [-128, 'tinyint', '-128', false];
        yield 'unsigned bigint arrives as string' => ['18446744073709551615', 'bigint', '18446744073709551615', false];
        yield 'year' => [2026, 'year', '2026', false];
        yield 'float' => [0.1, 'float', '0.1', false];
        yield 'double digits' => [0.30000001192092896, 'double', '0.30000001192092896', false];
        yield 'float largest' => [3.4028234663852886e38, 'float', '3.4028234663852886E+38', false];
        yield 'double smallest' => [4.9e-324, 'double', '5.0E-324', false];
        yield 'negative zero' => [-0.0, 'double', '-0.0', false];
        yield 'whole float keeps the point' => [3.0, 'double', '3.0', false];
        yield 'decimal stays a string' => ['12345678901234567890.123456789012345678', 'decimal', "'12345678901234567890.123456789012345678'", false];
        yield 'zero date' => ['0000-00-00', 'date', "'0000-00-00'", false];
        yield 'datetime' => ['2026-10-20 12:34:56.123456', 'datetime', "'2026-10-20 12:34:56.123456'", false];
        yield 'binary bytes' => ["\x00\x01\xff", 'blob', '0x0001FF', false];
        yield 'binary empty' => ['', 'varbinary', "''", false];
        yield 'binary from HEX()' => ['AABB', 'binary', '0xAABB', true];
        yield 'binary empty from HEX()' => ['', 'binary', "''", true];
        yield 'bit from HEX()' => ['0000000000000001', 'bit', '0x0000000000000001', true];
        yield 'json' => ['{"a":[1,"\\"x"]}', 'json', "'{\"a\":[1,\"\\\\\"x\"]}'", false];
        yield 'enum' => ['ü', 'enum', "'ü'", false];
    }

    #[DataProvider('literals')]
    public function testLiteral(mixed $value, string $type, string $expected, bool $isHex): void
    {
        self::assertSame($expected, SqlWriter::literal($value, new Column('c', $type), $isHex));
    }

    public function testAFloatThatIsNotFiniteIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SqlWriter::literal(INF, new Column('c', 'double'));
    }

    /** The shortest form PHP prints must read back as the same double: that is what keeps the value exact. */
    public function testFloatsReadBackExactly(): void
    {
        mt_srand(7);
        for ($i = 0; $i < 2000; ++$i) {
            $bits = random_int(0, PHP_INT_MAX) ^ (random_int(0, 1) << 62);
            $unpacked = unpack('d', pack('q', $bits));
            self::assertIsArray($unpacked);
            $value = (float) $unpacked[1];
            if (!is_finite($value)) {
                continue;
            }
            $text = SqlWriter::literal($value, new Column('c', 'double'));
            self::assertSame($value, (float) $text, $text);
        }
    }

    public function testColumnKinds(): void
    {
        self::assertSame(ColumnKind::Integer, ColumnKind::of('BIGINT'));
        self::assertSame(ColumnKind::Float, ColumnKind::of('double'));
        self::assertSame(ColumnKind::Binary, ColumnKind::of('bit'));
        self::assertSame(ColumnKind::Binary, ColumnKind::of('point'));
        self::assertSame(ColumnKind::Text, ColumnKind::of('decimal'));
        self::assertSame(ColumnKind::Text, ColumnKind::of('json'));
        self::assertSame(ColumnKind::Text, ColumnKind::of('uuid'));
    }

    public function testLargeColumns(): void
    {
        self::assertTrue((new Column('c', 'longblob'))->large);
        self::assertTrue((new Column('c', 'mediumtext'))->isSplit());
        self::assertFalse((new Column('c', 'longtext', splittable: false))->isSplit(), 'A column that must stay valid JSON is never cut.');
        self::assertFalse((new Column('c', 'blob'))->large);
        self::assertTrue((new Column('c', 'bit'))->hexInQuery());
        self::assertFalse((new Column('c', 'longblob'))->hexInQuery(), 'HEX() of a huge cell can silently become NULL.');
        self::assertFalse((new Column('c', 'varchar'))->hexInQuery());
    }

    public function testHeaderAndTableStart(): void
    {
        $start = SqlWriter::tableStart('ps_product', 'CREATE TABLE `ps_product` (`id` int)');

        self::assertStringStartsWith("-- ThreadSafe dump, table `ps_product`, format 1\nSET NAMES utf8mb4;\n", $start);
        self::assertStringContainsString("SET time_zone = '+00:00';", $start);
        self::assertStringContainsString("SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';", $start);
        self::assertStringEndsWith("DROP TABLE IF EXISTS `ps_product`;\nCREATE TABLE `ps_product` (`id` int);\n", $start);
        self::assertStringNotContainsString("\n\n", SqlWriter::header("a\nb"));
        self::assertStringContainsString('a?b', SqlWriter::header("a\nb"));
    }

    public function testInsertHead(): void
    {
        self::assertSame(
            "INSERT INTO `t` (`a`, `b``c`) VALUES\n",
            SqlWriter::insertHead('t', [new Column('a', 'int'), new Column('b`c', 'int')]),
        );
    }

    public function testAfterKeyForOneColumn(): void
    {
        self::assertSame(['`id` > ?', 1], SqlWriter::afterKey([new Column('id', 'int')]));
    }

    public function testAfterKeyForSeveralColumns(): void
    {
        $key = [new Column('a', 'int'), new Column('b', 'int'), new Column('c', 'int')];

        self::assertSame(['`a` > ? OR (`a` = ? AND `b` > ?) OR (`a` = ? AND `b` = ? AND `c` > ?)', 6], SqlWriter::afterKey($key));
    }

    public function testAppendPiece(): void
    {
        $data = new Column('data', 'longblob');
        $stamp = new Column('date_upd', 'timestamp', onUpdate: true);
        $id = new Column('id', 'int');
        $lang = new Column('lang', 'char');

        self::assertSame(
            "UPDATE `t` SET `data` = CONCAT(`data`, 0xAB), `date_upd` = `date_upd` WHERE `id` = 5 AND `lang` = 'en';\n",
            SqlWriter::appendPiece('t', $data, '0xAB', [$id, $lang], ['5', "'en'"], [$stamp]),
        );
    }

    /** @return iterable<string, array{int, string, string}> */
    public static function fileNames(): iterable
    {
        yield 'plain' => [7, 'product', '0007_product.sql'];
        yield 'dot and hyphen stay' => [12, 'a.b-c_d', '0012_a.b-c_d.sql'];
        yield 'odd characters' => [1, 'a b/c\\d`e', '0001_a_b_c_d_e.sql'];
        yield 'unicode' => [3, 'größe', '0003_gr__e.sql'];
        yield 'leading dots' => [4, '..hidden', '0004_hidden.sql'];
        yield 'only dots' => [5, '...', '0005__.sql'];
        yield 'many tables' => [12345, 'x', '12345_x.sql'];
        yield 'long name is cut' => [1, str_repeat('n', 300), '0001_' . str_repeat('n', 100) . '.sql'];
    }

    #[DataProvider('fileNames')]
    public function testTableFileName(int $number, string $table, string $expected): void
    {
        self::assertSame($expected, SqlWriter::tableFileName($number, $table));
    }
}
