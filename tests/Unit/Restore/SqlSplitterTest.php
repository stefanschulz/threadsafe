<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Restore;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ThreadSafe\Restore\SqlSplitter;
use ThreadSafe\Restore\SqlStatement;
use ThreadSafe\Tests\Support\TempDirectory;

final class SqlSplitterTest extends TestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function files(): iterable
    {
        yield 'two statements' => ["SELECT 1;\nSELECT 2;\n", ['SELECT 1', 'SELECT 2']];
        yield 'semicolon in string' => ["INSERT INTO t VALUES ('a;b', \"c;d\");\nSELECT 1;", ["INSERT INTO t VALUES ('a;b', \"c;d\")", 'SELECT 1']];
        yield 'escaped quote' => ["INSERT INTO t VALUES ('it\\'s; here');\nX;", ["INSERT INTO t VALUES ('it\\'s; here')", 'X']];
        yield 'doubled quote' => ["INSERT INTO t VALUES ('it''s; here');\nX;", ["INSERT INTO t VALUES ('it''s; here')", 'X']];
        yield 'backslash before the end quote' => ["INSERT INTO t VALUES ('a\\\\');\nX;", ["INSERT INTO t VALUES ('a\\\\')", 'X']];
        yield 'name in backticks with semicolon' => ["CREATE TABLE `a;b` (x int);\nX;", ['CREATE TABLE `a;b` (x int)', 'X']];
        yield 'comments' => [
            "-- header; with semicolon\n# another; one\n/* block; */ SELECT 1; -- trailing; comment\nSELECT 2;",
            ["-- header; with semicolon\n# another; one\n/* block; */ SELECT 1", "-- trailing; comment\nSELECT 2"],
        ];
        yield 'executable comment' => ["/*!40101 SET NAMES utf8 */;\nX;", ['/*!40101 SET NAMES utf8 */', 'X']];
        yield 'no final delimiter' => ["SELECT 1;\nSELECT 2", ['SELECT 1', 'SELECT 2']];
        yield 'comment on the last line without a line end' => ["SELECT 1;\n-- the end", ['SELECT 1']];
        yield 'only comments' => ["-- a\n# b\n/* c */\n", []];
        yield 'empty file' => ['', []];
        yield 'delimiter command' => [
            "SELECT 1;\nDELIMITER ;;\nCREATE TRIGGER t BEFORE INSERT ON x FOR EACH ROW BEGIN SET a = 1; SET b = ';;'; END ;;\nDELIMITER ;\nSELECT 2;\n",
            ['SELECT 1', "CREATE TRIGGER t BEFORE INSERT ON x FOR EACH ROW BEGIN SET a = 1; SET b = ';;'; END", 'SELECT 2'],
        ];
        yield 'dollar delimiter' => ["DELIMITER \$\$\nCREATE PROCEDURE p() BEGIN SELECT 1; END\$\$\nDELIMITER ;\n", ['CREATE PROCEDURE p() BEGIN SELECT 1; END']];
        yield 'minus is not a comment' => ["SELECT 5-3, 4--1, 6 - -2;\nX;", ['SELECT 5-3, 4--1, 6 - -2', 'X']];
        yield 'slash is not a comment' => ["SELECT 6 / 3, 6/3;\nX;", ['SELECT 6 / 3, 6/3', 'X']];
        yield 'multi-byte text' => ["INSERT INTO t VALUES ('Größe 😀 日本;');\nX;", ["INSERT INTO t VALUES ('Größe 😀 日本;')", 'X']];
        yield 'line breaks inside a string' => ["INSERT INTO t VALUES ('a\r\n;\nb');\r\nX;", ["INSERT INTO t VALUES ('a\r\n;\nb')", 'X']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('files')]
    public function testSplitsLikeTheClient(string $text, array $expected): void
    {
        $path = $this->file($text);
        // blocks that cut every kind of token, down to a single byte
        foreach ([SqlSplitter::BLOCK, 7, 1] as $block) {
            self::assertSame($expected, $this->texts(new SqlSplitter($path, 0, ';', $block)), "block size $block");
        }
    }

    public function testAStringOfSeveralMegabytes(): void
    {
        $big = "INSERT INTO t VALUES ('" . str_repeat("abc'';\\'x", 600000) . "');";
        $splitter = new SqlSplitter($this->file($big . "\nSELECT 1;"), 0, ';', 65536);

        $first = $splitter->next();
        self::assertNotNull($first);
        self::assertSame(strlen($big) - 1, strlen($first->text));
        self::assertSame('SELECT 1', $splitter->next()?->text);
        self::assertNull($splitter->next());
    }

    public function testOffsetsAndDelimiterLetAWorkContinueAnywhere(): void
    {
        $text = "SET a = 1;\nINSERT INTO t VALUES ('x;y');\nDELIMITER ;;\nCREATE TRIGGER t BEGIN SET a = 1; END ;;\nDELIMITER ;\nSELECT 2;\n";
        $path = $this->file($text);
        $all = $this->all(new SqlSplitter($path));
        self::assertCount(4, $all);

        foreach ($all as $i => $statement) {
            // a new splitter that starts after statement $i gives the rest
            $rest = $this->texts(new SqlSplitter($path, $statement->end, $statement->delimiter, 5));
            self::assertSame(array_map(static fn (SqlStatement $s): string => $s->text, array_slice($all, $i + 1)), $rest, "after statement $i");
        }
        self::assertSame([';', ';', ';;', ';'], array_map(static fn (SqlStatement $s): string => $s->delimiter, $all));
    }

    public function testOffsetsPointRightAfterTheDelimiter(): void
    {
        $path = $this->file("A;\nBB;\n");
        $all = $this->all(new SqlSplitter($path));

        self::assertSame([2, 6], array_map(static fn (SqlStatement $s): int => $s->end, $all));
    }

    public function testAnUnterminatedStringIsAnError(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unterminated');
        $this->all(new SqlSplitter($this->file("SELECT 1;\nSELECT 'oops;\n")));
    }

    public function testAMissingFileIsAnError(): void
    {
        $this->expectException(RuntimeException::class);
        new SqlSplitter($this->tempDirectory() . '/nothing.sql');
    }

    private function file(string $text): string
    {
        $path = $this->tempDirectory() . '/case.sql';
        file_put_contents($path, $text);

        return $path;
    }

    /** @return list<SqlStatement> */
    private function all(SqlSplitter $splitter): array
    {
        $statements = [];
        while (($statement = $splitter->next()) !== null) {
            $statements[] = $statement;
        }

        return $statements;
    }

    /** @return list<string> */
    private function texts(SqlSplitter $splitter): array
    {
        return array_map(static fn (SqlStatement $s): string => $s->text, $this->all($splitter));
    }
}
