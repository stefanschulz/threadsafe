<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Restore;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Restore\PrefixRewriter;

final class PrefixRewriterTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function rewrites(): iterable
    {
        yield 'drop' => ['DROP TABLE IF EXISTS `ps_product`', 'DROP TABLE IF EXISTS `xy_product`'];
        yield 'create' => ["CREATE TABLE `ps_product` (\n  `id` int(11) NOT NULL\n) ENGINE=InnoDB", "CREATE TABLE `xy_product` (\n  `id` int(11) NOT NULL\n) ENGINE=InnoDB"];
        yield 'insert' => ["INSERT INTO `ps_product` (`id`) VALUES\n(1),\n(2)", "INSERT INTO `xy_product` (`id`) VALUES\n(1),\n(2)"];
        yield 'update' => ["UPDATE `ps_cms` SET `c` = CONCAT(`c`, 'x') WHERE `id` = 1", "UPDATE `xy_cms` SET `c` = CONCAT(`c`, 'x') WHERE `id` = 1"];
        yield 'after the comment line of a file header' => ["-- ThreadSafe dump, table `ps_a`, format 1\nDROP TABLE IF EXISTS `ps_a`", "-- ThreadSafe dump, table `ps_a`, format 1\nDROP TABLE IF EXISTS `xy_a`"];
        yield 'table without the prefix' => ['INSERT INTO `other` (`id`) VALUES (1)', 'INSERT INTO `other` (`id`) VALUES (1)'];
        yield 'a name in the data stays' => ["INSERT INTO `ps_x` (`a`) VALUES ('INSERT INTO `ps_y`')", "INSERT INTO `xy_x` (`a`) VALUES ('INSERT INTO `ps_y`')"];
        yield 'session settings stay' => ["SET NAMES utf8mb4", 'SET NAMES utf8mb4'];
        yield 'not at the start' => ["SELECT * FROM `ps_a`", 'SELECT * FROM `ps_a`'];
    }

    #[DataProvider('rewrites')]
    public function testRewrite(string $statement, string $expected): void
    {
        self::assertSame($expected, (new PrefixRewriter('ps_', 'xy_'))->rewrite($statement));
    }

    public function testEmptyPrefixesAddAndRemove(): void
    {
        self::assertSame('INSERT INTO `ps_a` VALUES (1)', (new PrefixRewriter('', 'ps_'))->rewrite('INSERT INTO `a` VALUES (1)'));
        self::assertSame('INSERT INTO `a` VALUES (1)', (new PrefixRewriter('ps_', ''))->rewrite('INSERT INTO `ps_a` VALUES (1)'));
    }

    public function testNoChangeLeavesEverythingAlone(): void
    {
        $rewriter = new PrefixRewriter('ps_', 'ps_');

        self::assertFalse($rewriter->changes());
        self::assertSame('DROP TABLE IF EXISTS `ps_a`', $rewriter->rewrite('DROP TABLE IF EXISTS `ps_a`'));
    }

    public function testAPrefixWithOtherCharactersIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PrefixRewriter('ps_', 'x`y');
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function obstacles(): iterable
    {
        yield 'plain table' => ["CREATE TABLE `ps_a` (\n  `id` int NOT NULL,\n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB", null];
        yield 'foreign key' => ["CREATE TABLE `ps_a` (\n  `id` int NOT NULL,\n  CONSTRAINT `fk` FOREIGN KEY (`id`) REFERENCES `ps_b` (`id`)\n) ENGINE=InnoDB", 'the dump contains foreign keys, which name tables'];
        yield 'keywords in names and comments are not keys' => ["CREATE TABLE `foreign key` (\n  `references` int COMMENT 'FOREIGN KEY REFERENCES x'\n)", null];
        yield 'union table' => ['CREATE TABLE `m` (`a` int) ENGINE=MRG_MyISAM UNION=(`t1`,`t2`)', 'the dump contains a table that unites other tables'];
        yield 'view' => ['CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v` AS select 1', 'the dump contains views, triggers or routines, which name tables'];
        yield 'trigger' => ['CREATE DEFINER=`root`@`localhost` TRIGGER t BEFORE INSERT ON x FOR EACH ROW SET a = 1', 'the dump contains views, triggers or routines, which name tables'];
        yield 'insert' => ["INSERT INTO `a` (`x`) VALUES ('FOREIGN KEY')", null];
    }

    #[DataProvider('obstacles')]
    public function testObstacle(string $statement, ?string $reason): void
    {
        self::assertSame($reason, PrefixRewriter::obstacle($statement));
    }

    public function testDescribeNeverShowsValues(): void
    {
        self::assertSame('INSERT INTO `ps_customer`', PrefixRewriter::describe("INSERT INTO `ps_customer` (`email`) VALUES\n('secret@example.org')"));
        self::assertSame('SET NAMES', PrefixRewriter::describe("SET NAMES 'utf8mb4'"));
        self::assertSame('a statement', PrefixRewriter::describe('12345'));
    }
}
