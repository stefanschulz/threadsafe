<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Profile;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Profile\Grants;

final class GrantsTest extends TestCase
{
    /** @return iterable<string, array{list<string>, string, list<string>}> */
    public static function cases(): iterable
    {
        yield 'everything on everything' => [["GRANT ALL PRIVILEGES ON *.* TO `root`@`%` WITH GRANT OPTION"], 'shop', ['ALL PRIVILEGES']];
        yield 'MySQL ALL' => [['GRANT ALL ON *.* TO `u`@`%`'], 'shop', ['ALL PRIVILEGES']];
        yield 'list on the database' => [['GRANT SELECT, SHOW VIEW, TRIGGER ON `shop`.* TO `u`@`localhost`'], 'shop', ['SELECT', 'SHOW VIEW', 'TRIGGER']];
        yield 'usage only' => [['GRANT USAGE ON *.* TO `u`@`%` IDENTIFIED BY PASSWORD \'*ABC\'', 'GRANT SELECT ON `shop`.* TO `u`@`%`'], 'shop', ['USAGE', 'SELECT']];
        yield 'other database' => [['GRANT ALL PRIVILEGES ON `other`.* TO `u`@`%`'], 'shop', []];
        yield 'underscore escaped' => [['GRANT SELECT ON `my\_shop`.* TO `u`@`%`'], 'my_shop', ['SELECT']];
        yield 'single table' => [['GRANT SELECT ON `shop`.`ps_product` TO `u`@`%`'], 'shop', []];
        yield 'column privileges are named without the columns' => [['GRANT SELECT (id), UPDATE ON *.* TO `u`@`%`'], 'shop', ['SELECT', 'UPDATE']];
        yield 'both levels' => [['GRANT SELECT ON *.* TO `u`@`%`', 'GRANT TRIGGER ON `shop`.* TO `u`@`%`'], 'shop', ['SELECT', 'TRIGGER']];
        yield 'lower case' => [['grant select on `shop`.* to `u`@`%`'], 'shop', ['SELECT']];
        yield 'not a grant' => [['something else'], 'shop', []];
    }

    /**
     * @param list<string> $grants
     * @param list<string> $expected
     */
    #[DataProvider('cases')]
    public function testPrivileges(array $grants, string $database, array $expected): void
    {
        self::assertSame($expected, Grants::privileges($grants, $database));
    }

    public function testMissing(): void
    {
        self::assertSame([], Grants::missing(['ALL PRIVILEGES'], ['SELECT', 'TRIGGER']));
        self::assertSame(['TRIGGER'], Grants::missing(['SELECT', 'SHOW VIEW'], ['SELECT', 'SHOW VIEW', 'TRIGGER']));
        self::assertSame(['SELECT'], Grants::missing([], ['SELECT']));
    }
}
