<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Database;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Database\DatabaseSettings;
use ThreadSafe\Database\ServerInfo;

final class ServerInfoTest extends TestCase
{
    #[DataProvider('versions')]
    public function testParsesTheVersionString(string $raw, string $type, string $version, bool $supported, string $description): void
    {
        $info = ServerInfo::parse($raw);

        self::assertSame($type, $info->type);
        self::assertSame($version, $info->version);
        self::assertSame($supported, $info->isSupported());
        self::assertSame($description, $info->describe());
        self::assertSame($raw, $info->raw);
        self::assertSame($supported, $info->problem() === null);
    }

    /** @return iterable<string, array{string, string, string, bool, string}> */
    public static function versions(): iterable
    {
        yield 'MariaDB with distribution suffix' => ['10.11.18-MariaDB-ubu2204', 'mariadb', '10.11.18', true, 'MariaDB 10.11.18'];
        yield 'MariaDB with the replication prefix' => ['5.5.5-10.11.6-MariaDB', 'mariadb', '10.11.6', true, 'MariaDB 10.11.6'];
        yield 'MariaDB 11.4 with log suffix' => ['11.4.2-MariaDB-log', 'mariadb', '11.4.2', true, 'MariaDB 11.4.2'];
        yield 'oldest supported MariaDB' => ['10.2.0-MariaDB', 'mariadb', '10.2.0', true, 'MariaDB 10.2.0'];
        yield 'MariaDB too old' => ['10.1.48-MariaDB', 'mariadb', '10.1.48', false, 'MariaDB 10.1.48'];
        yield 'MySQL 8.4' => ['8.4.2', 'mysql', '8.4.2', true, 'MySQL 8.4.2'];
        yield 'MySQL 8.0 commercial' => ['8.0.36-commercial', 'mysql', '8.0.36', true, 'MySQL 8.0.36'];
        yield 'oldest supported MySQL' => ['5.7.44-log', 'mysql', '5.7.44', true, 'MySQL 5.7.44'];
        yield 'MySQL too old' => ['5.6.51', 'mysql', '5.6.51', false, 'MySQL 5.6.51'];
        yield 'a fork counts as MySQL' => ['8.0.35-27', 'mysql', '8.0.35', true, 'MySQL 8.0.35'];
        yield 'empty' => ['', 'unknown', '', false, 'an unknown database server'];
        yield 'something else' => ['PostgreSQL 16', 'unknown', '', false, '"PostgreSQL 16"'];
    }

    public function testProblemsAreExplained(): void
    {
        self::assertSame('MariaDB 10.1.48 is too old; the minimum is MariaDB 10.2.', ServerInfo::parse('10.1.48-MariaDB')->problem());
        self::assertSame('MySQL 5.6.51 is too old; the minimum is MySQL 5.7.', ServerInfo::parse('5.6.51')->problem());
        self::assertStringContainsString('only MariaDB (from 10.2) and MySQL (from 5.7) are supported', (string) ServerInfo::parse('nonsense')->problem());
    }

    public function testTypePredicates(): void
    {
        self::assertTrue(ServerInfo::parse('10.11.6-MariaDB')->isMariaDb());
        self::assertFalse(ServerInfo::parse('10.11.6-MariaDB')->isMySql());
        self::assertTrue(ServerInfo::parse('8.4.2')->isMySql());
    }

    public function testDriverSettingIsValidated(): void
    {
        self::assertSame('auto', (new DatabaseSettings('h', 3306, 'n', 'u', 'p', 'ps_'))->driver);
        self::assertSame('mysqli', (new DatabaseSettings('h', 3306, 'n', 'u', 'p', 'ps_', 'mysqli'))->driver);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('driver must be auto, pdo, mysqli');

        new DatabaseSettings('h', 3306, 'n', 'u', 'p', 'ps_', 'odbc');
    }
}
