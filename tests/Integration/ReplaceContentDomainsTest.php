<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Integration;

use ThreadSafe\Core\Context;
use ThreadSafe\Core\FileJournal;
use ThreadSafe\Core\NoticeLevel;
use ThreadSafe\Core\NullJournal;
use ThreadSafe\Migration\MigrationRequest;
use ThreadSafe\PrestaShop9\Steps\ReadSourceState;
use ThreadSafe\PrestaShop9\Steps\ReplaceContentDomains;
use ThreadSafe\Replace\DomainMapping;
use ThreadSafe\Tests\Support\ScratchShop;
use ThreadSafe\Tests\Support\TempDirectory;

final class ReplaceContentDomainsTest extends IntegrationTestCase
{
    use TempDirectory;

    private ScratchShop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = ScratchShop::create('old.test');

        $serialized = serialize(['url' => 'http://old.test/a', 'list' => ['https://www.old.test/b'], 'mail' => 'info@old.test']);
        $json = json_encode(['u' => 'http://old.test/c', 'n' => 1.0]);
        $this->shop->exec('INSERT INTO ps_cms_lang (id_cms, id_lang, content, meta_title) VALUES (1, 1, ?, ?), (1, 2, ?, ?), (2, 1, ?, NULL)', [
            '<a href="http://old.test/page">x</a> and bare old.test and info@old.test and http://other.test/',
            'Title with http://old.test',
            $serialized,
            'plain title',
            $json,
        ]);
        $this->shop->exec('INSERT INTO ps_note (text) VALUES (?), (?), (?)', ['http://old.test/1', 'nothing here', 'a:1:{s:3:"http://old.test";}']);
        $this->shop->exec('INSERT INTO ps_nokey (text) VALUES (?)', ['http://old.test/no-key']);
        $this->shop->exec('INSERT INTO ps_connections_source (http_referer) VALUES (?), (?)', ['http://old.test/referer', 'http://old.test/referer2']);
        $this->shop->exec('INSERT INTO ps_smarty_cache (id_smarty_cache, content) VALUES (?, ?)', ['k', 'http://old.test/cached']);
    }

    protected function tearDown(): void
    {
        $this->shop->destroy();
        $this->cleanUpTempDirectories();
    }

    /**
     * @param list<string> $skipTables
     */
    private function request(bool $replaceBare = false, int $chunkSize = 2000, array $skipTables = []): MigrationRequest
    {
        return new MigrationRequest(
            $this->shop->root,
            [new DomainMapping('old.test', 'new.example')],
            skipTables: $skipTables,
            replaceBare: $replaceBare,
            chunkSize: $chunkSize,
        );
    }

    private function context(MigrationRequest $request, ?FileJournal $journal = null): Context
    {
        $context = $this->shop->context($request, $journal ?? new NullJournal(), $journal === null);
        (new ReadSourceState())->plan($context);

        return $context;
    }

    private function applyStep(MigrationRequest $request, ?FileJournal $journal = null): int
    {
        $context = $this->context($request, $journal ?? new FileJournal($this->tempDirectory()));
        $step = new ReplaceContentDomains();

        return $step->apply($context, $step->plan($context))->applied;
    }

    /** A fingerprint of every table, to prove that a dry run changes nothing. */
    private function fingerprint(): string
    {
        $parts = [];
        foreach (['ps_cms_lang', 'ps_note', 'ps_nokey', 'ps_connections_source', 'ps_smarty_cache', 'ps_configuration', 'ps_shop_url'] as $table) {
            $parts[] = $this->shop->value("CHECKSUM TABLE $table");
            $parts[] = json_encode($this->shop->rows("SELECT * FROM $table"));
        }

        return md5(implode('|', $parts));
    }

    public function testPlanDescribesTheChangesAndTheNotes(): void
    {
        $plan = (new ReplaceContentDomains())->plan($this->context($this->request()));

        $byTarget = [];
        foreach ($plan->changes() as $change) {
            $byTarget[$change->target] = $change;
        }
        self::assertSame(3, $byTarget['db ps_cms_lang.content']->count);
        self::assertSame(1, $byTarget['db ps_cms_lang.meta_title']->count);
        self::assertSame(1, $byTarget['db ps_note.text']->count, 'The broken serialized value is not counted as a change.');
        self::assertArrayNotHasKey('db ps_connections_source.http_referer', $byTarget);
        self::assertArrayNotHasKey('db ps_smarty_cache.content', $byTarget);
        self::assertStringContainsString('old.test', (string) $byTarget['db ps_cms_lang.content']->before);
        self::assertStringContainsString('new.example', (string) $byTarget['db ps_cms_lang.content']->after);

        $messages = array_map(static fn ($n): string => $n->level->value . ': ' . $n->message . ' [' . $n->target . ']', $plan->notices());
        $joined = implode("\n", $messages);
        self::assertStringContainsString('warning: 1 structured value(s) could not be parsed', $joined);
        self::assertStringContainsString('warning: 1 row(s) mention the domain but the table has no primary key', $joined);
        self::assertStringContainsString('e-mail address(es) at the old domain left untouched', $joined);
        self::assertStringContainsString('without a URL form and are left untouched', $joined);
        self::assertStringContainsString('ps_connections_source', $joined, 'Skipped log tables are reported.');
    }

    public function testDryRunChangesNothing(): void
    {
        $before = $this->fingerprint();

        (new ReplaceContentDomains())->plan($this->context($this->request()));

        self::assertSame($before, $this->fingerprint());
    }

    public function testApplyReplacesUrlsAndKeepsEverythingElse(): void
    {
        $applied = $this->applyStep($this->request());

        // Changed rows: cms (1,1) with two cells, the serialized and the JSON cms row, and the first note.
        self::assertSame(4, $applied);
        $rows = $this->shop->rows('SELECT id_cms, id_lang, content, meta_title FROM ps_cms_lang ORDER BY id_cms, id_lang');
        self::assertSame(
            '<a href="http://new.example/page">x</a> and bare old.test and info@old.test and http://other.test/',
            $rows[0]['content'],
        );
        self::assertSame('Title with http://new.example', $rows[0]['meta_title']);

        $unserialized = unserialize((string) $rows[1]['content']);
        self::assertSame(['url' => 'http://new.example/a', 'list' => ['https://new.example/b'], 'mail' => 'info@old.test'], $unserialized);

        self::assertSame('{"u":"http:\/\/new.example\/c","n":1}', str_replace('1.0', '1', (string) $rows[2]['content']));
        self::assertTrue(json_validate((string) $rows[2]['content']));
    }

    public function testAValueThatWouldNotFitIntoItsColumnKeepsTheOldDomainInsteadOfStoppingTheRun(): void
    {
        // field test (Joomla, #__redirect_links.old_url): the new domain is longer, a value near the limit of its column would be refused (error 1406)
        $this->shop->exec('CREATE TABLE ps_redirects (id INT PRIMARY KEY, old_url VARCHAR(30) NOT NULL, note TINYTEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $fits = 'http://old.test/' . str_repeat('a', 10);       // 26 characters, 29 with the new domain
        $tooLong = 'http://old.test/' . str_repeat('b', 14);     // 30 characters, 33 with the new domain
        $this->shop->exec('INSERT INTO ps_redirects (id, old_url, note) VALUES (1, ?, ?), (2, ?, ?)', [$fits, 'http://old.test/x', $tooLong, 'http://old.test/y']);

        $this->applyStep($this->request());

        $rows = $this->shop->rows('SELECT id, old_url, note FROM ps_redirects ORDER BY id');
        self::assertSame('http://new.example/' . str_repeat('a', 10), $rows[0]['old_url']);
        self::assertSame('http://new.example/x', $rows[0]['note']);
        self::assertSame($tooLong, $rows[1]['old_url'], 'left as it was');
        self::assertSame('http://new.example/y', $rows[1]['note'], 'the other cell of the same row is changed');

        $plan = (new ReplaceContentDomains())->plan($this->context($this->request()));
        $warnings = array_map(static fn ($n): string => $n->message, array_filter($plan->notices(), static fn ($n): bool => $n->level === NoticeLevel::Warning));
        self::assertNotSame([], array_filter($warnings, static fn (string $m): bool => str_contains($m, 'too long for the column')));
    }

    public function testBrokenSerializedValueAndNoKeyTableAreLeftAlone(): void
    {
        $this->applyStep($this->request());

        self::assertSame(['http://new.example/1', 'nothing here', 'a:1:{s:3:"http://old.test";}'], array_column($this->shop->rows('SELECT text FROM ps_note ORDER BY id_note'), 'text'));
        self::assertSame('http://old.test/no-key', $this->shop->value('SELECT text FROM ps_nokey'));
    }

    public function testSkippedTablesAreNotTouched(): void
    {
        $this->applyStep($this->request());

        self::assertSame(['http://old.test/referer', 'http://old.test/referer2'], array_column($this->shop->rows('SELECT http_referer FROM ps_connections_source ORDER BY 1'), 'http_referer'));
        self::assertSame('http://old.test/cached', $this->shop->value('SELECT content FROM ps_smarty_cache'));
    }

    public function testExtraTablesCanBeSkipped(): void
    {
        $this->applyStep($this->request(skipTables: ['note']));

        self::assertSame('http://old.test/1', $this->shop->value('SELECT text FROM ps_note WHERE id_note = 1'));
    }

    public function testShopUrlAndDomainSettingsAreLeftToUpdateConfiguration(): void
    {
        $this->applyStep($this->request(replaceBare: true));

        self::assertSame('old.test', $this->shop->value('SELECT domain FROM ps_shop_url'));
        self::assertSame('old.test', $this->shop->value("SELECT value FROM ps_configuration WHERE name = 'PS_SHOP_DOMAIN'"));
    }

    public function testBareDomainsAreReplacedOnlyWhenRequested(): void
    {
        $this->applyStep($this->request(replaceBare: true));

        $content = $this->shop->value('SELECT content FROM ps_cms_lang WHERE id_cms = 1 AND id_lang = 1');
        self::assertStringContainsString('bare new.example', $content);
        self::assertStringContainsString('info@old.test', $content, 'E-mail addresses stay untouched even then.');
    }

    public function testChunkingGivesTheSameResultAsOneBigChunk(): void
    {
        for ($i = 0; $i < 25; ++$i) {
            $this->shop->exec('INSERT INTO ps_note (text) VALUES (?)', ["row $i http://old.test/$i"]);
        }

        $this->applyStep($this->request(chunkSize: 2));

        self::assertSame('0', $this->shop->value("SELECT COUNT(*) FROM ps_note WHERE text LIKE '%http://old.test/%'"));
        self::assertSame('26', $this->shop->value("SELECT COUNT(*) FROM ps_note WHERE text LIKE '%http://new.example/%'"), '25 new rows + the original one');
        self::assertSame('0', $this->shop->value("SELECT COUNT(*) FROM ps_cms_lang WHERE meta_title LIKE '%http://old.test%'"));
    }

    public function testSecondRunHasNothingToDo(): void
    {
        $this->applyStep($this->request());

        $plan = (new ReplaceContentDomains())->plan($this->context($this->request()));

        self::assertFalse($plan->hasChanges());
    }

    public function testEveryChangedCellIsJournalled(): void
    {
        $journal = new FileJournal($this->tempDirectory());

        $this->applyStep($this->request(), $journal);

        $entries = array_map(
            static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            array_filter(explode("\n", (string) file_get_contents((string) $journal->path()))),
        );
        $db = array_values(array_filter($entries, static fn (array $e): bool => $e['type'] === 'db'));
        self::assertCount(5, $db);
        $first = array_values(array_filter($db, static fn (array $e): bool => $e['column'] === 'meta_title'))[0];
        self::assertSame('Title with http://old.test', $first['old']);
        self::assertSame('Title with http://new.example', $first['new']);
        self::assertSame(['id_cms' => 1, 'id_lang' => 1], $first['key']);
    }

    public function testRestoreInPlaceHasNothingToReplace(): void
    {
        $plan = (new ReplaceContentDomains())->plan($this->context(new MigrationRequest($this->shop->root)));

        self::assertFalse($plan->hasChanges());
        self::assertSame(NoticeLevel::Info, $plan->notices()[0]->level);
    }
}
