<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Integration;

use ThreadSafe\Core\Context;
use ThreadSafe\Core\Exception\GuardFailed;
use ThreadSafe\Core\Exception\SelfTestFailed;
use ThreadSafe\Core\FileJournal;
use ThreadSafe\Core\NoticeLevel;
use ThreadSafe\Core\NullJournal;
use ThreadSafe\Core\Step;
use ThreadSafe\Database\DatabaseSettings;
use ThreadSafe\Generic\Steps\ReadGenericState;
use ThreadSafe\Generic\Steps\ReplaceContentDomains;
use ThreadSafe\Generic\Steps\SelfTest;
use ThreadSafe\Http\HttpClient;
use ThreadSafe\Migration\MigrationRequest;
use ThreadSafe\Replace\DomainMapping;
use ThreadSafe\Tests\Support\ScratchShop;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * The steps of the generic website type against a scratch database: any tables, no application.
 */
final class GenericStepsTest extends IntegrationTestCase
{
    use TempDirectory;

    private ScratchShop $shop;

    /** @var list<string> "url|host" of every request the fake web server received */
    private array $requests = [];

    /** @var array<string, array{int, array<string, string>, string}> answers by path-and-query */
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = ScratchShop::create('old.test');
        $this->shop->exec("INSERT INTO ps_cms_lang (id_cms, id_lang, content) VALUES (1, 1, 'see http://old.test/page and http://other.test/x')");
        $this->shop->exec('INSERT INTO ps_note (text) VALUES (?)', [serialize(['url' => 'http://old.test/a', 'list' => ['https://old.test/b']])]);
        $this->shop->exec("INSERT INTO ps_connections_source (http_referer) VALUES ('http://old.test/log')");
        $this->shop->exec("INSERT INTO ps_nokey (text) VALUES ('http://old.test/nokey')");
        $this->answers = ['/' => [200, [], '<html>welcome</html>']];
    }

    protected function tearDown(): void
    {
        $this->shop->destroy();
        $this->cleanUpTempDirectories();
    }

    /**
     * @param list<string> $skip
     */
    private function request(bool $database = true, bool $mapping = true, array $skip = [], ?string $selfTestUrl = null, bool $selfTest = true, string $prefix = ''): MigrationRequest
    {
        $s = $this->shop->settings;

        return new MigrationRequest(
            $this->shop->root,
            $mapping ? [new DomainMapping('old.test', 'new.test')] : [],
            $database ? new DatabaseSettings($s->host, $s->port, $s->name, $s->user, $s->password, $prefix) : null,
            skipTables: $skip,
            selfTest: $selfTest,
            selfTestUrl: $selfTestUrl,
        );
    }

    private function context(MigrationRequest $request, bool $journal = false): Context
    {
        $context = $this->shop->context($request, $journal ? new FileJournal($this->tempDirectory()) : new NullJournal(), !$journal);
        (new ReadGenericState())->plan($context);

        return $context;
    }

    private function apply(Step $step, MigrationRequest $request): int
    {
        $context = $this->context($request, journal: true);

        return $step->apply($context, $step->plan($context))->applied;
    }

    private function client(): HttpClient
    {
        return new HttpClient(false, 5, 10, function (string $url, string $host): array {
            $this->requests[] = "$url|$host";
            $path = (string) preg_replace('~^https?://[^/]+~', '', $url);

            return $this->answers[$path] ?? [404, [], 'not found'];
        });
    }

    public function testReadsTheDatabaseOrSaysThereIsNone(): void
    {
        $with = (new ReadGenericState())->plan($this->shop->context($this->request(), new NullJournal(), true));
        self::assertStringContainsString('Database "', $with->notices()[0]->message);
        self::assertStringContainsString('(pdo)', $with->notices()[0]->message);

        $without = (new ReadGenericState())->plan($this->shop->context($this->request(database: false), new NullJournal(), true));
        self::assertStringContainsString('No database given', $without->notices()[0]->message);

        $restore = (new ReadGenericState())->plan($this->shop->context($this->request(mapping: false), new NullJournal(), true));
        self::assertStringContainsString('No domain mapping given', $restore->notices()[1]->message);
    }

    public function testWrongPasswordRefusesTheRun(): void
    {
        $s = $this->shop->settings;
        $request = new MigrationRequest($this->shop->root, [], new DatabaseSettings($s->host, $s->port, $s->name, $s->user, 'wrong', ''));

        $this->expectException(GuardFailed::class);
        $this->expectExceptionMessage('Cannot connect to database');

        (new ReadGenericState())->plan($this->shop->context($request, new NullJournal(), true));
    }

    public function testReplacesInEveryTableOfTheDatabase(): void
    {
        $step = new ReplaceContentDomains();
        $plan = $step->plan($this->context($this->request()));

        $targets = array_map(static fn ($c): string => $c->target, $plan->changes());
        self::assertContains('db ps_cms_lang.content', $targets);
        self::assertContains('db ps_note.text', $targets);
        self::assertContains('db ps_connections_source.http_referer', $targets, 'A generic site has no list of log tables: everything is rewritten.');
        self::assertStringContainsString('old.test', $this->shop->value('SELECT content FROM ps_cms_lang'), 'A plan changes nothing.');

        $this->apply($step, $this->request());

        self::assertSame('see http://new.test/page and http://other.test/x', $this->shop->value('SELECT content FROM ps_cms_lang'));
        self::assertSame(['url' => 'http://new.test/a', 'list' => ['https://new.test/b']], unserialize($this->shop->value('SELECT text FROM ps_note')));
        self::assertSame('http://new.test/log', $this->shop->value('SELECT http_referer FROM ps_connections_source'));
        self::assertSame('http://old.test/nokey', $this->shop->value('SELECT text FROM ps_nokey'), 'A table without a primary key is only reported.');
        $warnings = array_values(array_map(static fn ($n): string => $n->message, array_filter($step->plan($this->context($this->request()))->notices(), static fn ($n): bool => $n->level === NoticeLevel::Warning)));
        self::assertSame(['1 row(s) mention the domain but the table has no primary key; not changed.'], $warnings);
    }

    public function testSkippedTablesStayWhetherNamedWithOrWithoutPrefix(): void
    {
        $this->apply(new ReplaceContentDomains(), $this->request(skip: ['ps_connections_source', 'note'], prefix: 'ps_'));

        self::assertSame('http://old.test/log', $this->shop->value('SELECT http_referer FROM ps_connections_source'));
        self::assertSame(['url' => 'http://old.test/a', 'list' => ['https://old.test/b']], unserialize($this->shop->value('SELECT text FROM ps_note')));
        self::assertStringContainsString('new.test', $this->shop->value('SELECT content FROM ps_cms_lang'));
    }

    public function testSecondRunFindsNothingToDo(): void
    {
        $this->apply(new ReplaceContentDomains(), $this->request());

        self::assertFalse((new ReplaceContentDomains())->plan($this->context($this->request()))->hasChanges());
    }

    public function testWithoutDatabaseOrMappingNothingIsPlanned(): void
    {
        $noDatabase = (new ReplaceContentDomains())->plan($this->context($this->request(database: false)));
        self::assertFalse($noDatabase->hasChanges());
        self::assertSame(NoticeLevel::Warning, $noDatabase->notices()[0]->level);

        $noMapping = (new ReplaceContentDomains())->plan($this->context($this->request(mapping: false)));
        self::assertFalse($noMapping->hasChanges());
        self::assertStringContainsString('nothing to replace', $noMapping->notices()[0]->message);
    }

    // ---- self-test ---------------------------------------------------------------------------------------------

    public function testSelfTestRequestsTheHomePageAtTheNewDomain(): void
    {
        $step = new SelfTest($this->client());
        $context = $this->context($this->request());

        $result = $step->apply($context, $step->plan($context));

        self::assertSame(['http://new.test/|new.test'], $this->requests);
        self::assertSame('ok: home page (/) - HTTP 200', $result->notices[0]->message);
    }

    public function testSelfTestThroughAnExplicitAddress(): void
    {
        $step = new SelfTest($this->client());
        $context = $this->context($this->request(selfTestUrl: 'http://web:8080/'));

        $step->apply($context, $step->plan($context));

        self::assertSame(['http://web:8080/|new.test'], $this->requests);
    }

    public function testSelfTestFailures(): void
    {
        $step = new SelfTest($this->client());
        $context = $this->context($this->request());

        foreach ([
            [[500, [], 'oops'], 'HTTP 500'],
            [[200, [], '<a href="http://old.test/x">x</a>'], '1 link(s) on the page still point to the old domain'],
            [[301, ['location' => 'http://old.test/'], ''], 'redirect to another host: http://old.test/'],
        ] as [$answer, $message]) {
            $this->answers['/'] = $answer;
            try {
                $step->apply($context, $step->plan($context));
                self::fail("The self-test must fail: $message");
            } catch (SelfTestFailed $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function testSelfTestNeedsAMappingAndCanBeSwitchedOff(): void
    {
        $step = new SelfTest($this->client());

        $noMapping = $this->context($this->request(mapping: false));
        self::assertStringContainsString('no address to request', $step->plan($noMapping)->notices()[0]->message);
        $step->apply($noMapping, $step->plan($noMapping));

        $off = $this->context($this->request(selfTest: false));
        self::assertSame(NoticeLevel::Warning, $step->plan($off)->notices()[0]->level);
        $step->apply($off, $step->plan($off));

        self::assertSame([], $this->requests);
    }
}
