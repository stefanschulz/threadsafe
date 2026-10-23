<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Joomla;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Joomla\Backup\AddressCandidates;
use ThreadSafe\Tests\Support\FakeConnection;

/**
 * The hosts that the content of a Joomla site points to: the proposal for the "old domain" of a restore.
 */
final class AddressCandidatesTest extends TestCase
{
    public function testHostsAreCountedInPlainTextAndInJson(): void
    {
        $text = '<a href="https://www.example.org/a">a</a> <img src="http://www.example.org/i.png"> {"link":"https:\/\/www.example.org\/contact","other":"https:\/\/cdn.example.net\/x.js"}';

        self::assertSame(['www.example.org' => 3, 'cdn.example.net' => 1], AddressCandidates::hosts($text));
    }

    public function testHostsAreLowerCaseAndKeepTheirPort(): void
    {
        self::assertSame(['shop.example.org' => 2, 'localhost:8080' => 1], AddressCandidates::hosts('HTTPS://Shop.Example.ORG/a http://shop.example.org/b http://localhost:8080/x'));
    }

    public function testStandardsAndTheProjectsOwnSitesAreNotAddresses(): void
    {
        $text = 'xmlns="http://www.w3.org/2000/svg" https://schema.org/Article https://help.joomla.org/proxy https://www.joomla.org/ https://example.org/';

        self::assertSame(['example.org' => 1], AddressCandidates::hosts($text));
    }

    public function testTextWithoutAnAbsoluteUrlHasNoHosts(): void
    {
        self::assertSame([], AddressCandidates::hosts('index.php?option=com_content&view=article&id=1 //cdn.example.org/x mailto:a@example.org ftp://old.example.org/'));
    }

    public function testTheMostFrequentHostComesFirstAndTheListIsLimited(): void
    {
        $db = FakeConnection::with([
            '~information_schema~' => [['n' => '1']],
            '~LEFT\(`introtext`~' => [
                ['value' => 'https://a.example/1 https://b.example/1 https://b.example/2 https://b.example/3 https://b.example/4'],
                ['value' => 'https://c.example/1'],
                ['value' => null],
            ],
            '~LEFT\(`content`, \d+\) AS value FROM `x_modules`~' => [['value' => '{"link":"https:\/\/a.example\/9"}']],
            '~SELECT~' => [],
        ], 'x_');

        $found = (new AddressCandidates(limit: 2))->find($db);

        self::assertSame([['host' => 'b.example', 'count' => 4], ['host' => 'a.example', 'count' => 2]], $found);
    }

    public function testEachColumnIsReadBoundedByRowsAndBytesAndOnlyWhereThereIsAnAbsoluteUrl(): void
    {
        $log = [];
        $db = FakeConnection::with(['~information_schema~' => [['n' => '1']], '~SELECT~' => []], 'x_', $log);

        (new AddressCandidates(rowsPerTable: 7, bytesPerCell: 123))->find($db);

        $reads = array_values(array_filter($log, static fn (string $sql): bool => str_contains($sql, 'LEFT(')));
        self::assertCount(8, $reads, 'content 2, modules 2, menu 2, template styles 1, extensions 1');
        foreach ($reads as $sql) {
            self::assertStringContainsString(', 123) AS value FROM `x_', $sql);
            self::assertStringContainsString("LIKE '%http%' LIMIT 7", $sql);
        }
        self::assertStringContainsString('`x_content`', $reads[0]);
        self::assertStringContainsString('`fulltext`', $reads[1], 'a reserved word, quoted');
    }

    public function testATableThatDoesNotExistIsSkipped(): void
    {
        $log = [];
        $db = FakeConnection::with(['~information_schema~' => [['n' => '0']]], 'x_', $log);

        self::assertSame([], (new AddressCandidates())->find($db));
        self::assertCount(5, $log, 'only the five questions whether the tables exist');
    }
}
