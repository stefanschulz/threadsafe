<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Integration;

use ThreadSafe\Core\Context;
use ThreadSafe\Core\FileJournal;
use ThreadSafe\Core\NullLogger;
use ThreadSafe\Core\Step;
use ThreadSafe\Core\TickedRunner;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Generic\Steps\ReadGenericState;
use ThreadSafe\Generic\Steps\ReplaceContentDomains as GenericReplace;
use ThreadSafe\Migration\MigrationRequest;
use ThreadSafe\PrestaShop9\Steps\ReadSourceState;
use ThreadSafe\PrestaShop9\Steps\ReplaceContentDomains as PrestaShopReplace;
use ThreadSafe\Replace\DomainMapping;
use ThreadSafe\Tests\Support\ScratchShop;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * Replacing the domain in the database content in ticks: with a budget that is used up after every chunk of three rows, in a new
 * "process" (new context, new connection, state through JSON) for every tick, the result must be the same as in one go - also
 * after a tick whose state was lost.
 */
final class ResumableContentTest extends IntegrationTestCase
{
    use TempDirectory;

    /** @var list<ScratchShop> */
    private array $shops = [];

    protected function tearDown(): void
    {
        foreach ($this->shops as $shop) {
            $shop->destroy();
        }
        $this->cleanUpTempDirectories();
    }

    private function shop(): ScratchShop
    {
        $shop = ScratchShop::create('old.test');
        $this->shops[] = $shop;
        for ($i = 1; $i <= 40; ++$i) {
            $shop->exec('INSERT INTO ps_note (text) VALUES (?)', [$i % 4 === 0 ? "no domain in note $i" : "see <a href=\"http://old.test/page$i\">page</a> and https://www.old.test/x$i"]);
        }
        for ($i = 1; $i <= 12; ++$i) {
            $shop->exec('INSERT INTO ps_cms_lang (id_cms, id_lang, content, meta_title) VALUES (?, ?, ?, ?)', [intdiv($i, 3), $i % 3, serialize(['url' => "http://old.test/cms$i", 'n' => $i]), "title $i http://old.test/"]);
        }
        $shop->exec('INSERT INTO ps_nokey (text) VALUES (?)', ['http://old.test/no-key']);

        return $shop;
    }

    /** @return list<Step> */
    private function genericSteps(): array
    {
        return [new ReadGenericState(), new GenericReplace()];
    }

    /** @return list<Step> */
    private function prestaShopSteps(): array
    {
        return [new ReadSourceState(), new PrestaShopReplace()];
    }

    private function request(ScratchShop $shop): MigrationRequest
    {
        return new MigrationRequest($shop->root, [new DomainMapping('old.test', 'new.example')], database: $shop->settings, chunkSize: 3, selfTest: false);
    }

    private function context(ScratchShop $shop, ?FileJournal $journal = null): Context
    {
        return new Context($this->request($shop), $journal ?? new FileJournal($this->tempDirectory()), new NullLogger(), false);
    }

    /** What the content tables hold, as one string. */
    private function content(ScratchShop $shop): string
    {
        return md5((string) json_encode([
            $shop->rows('SELECT * FROM ps_note ORDER BY id_note'),
            $shop->rows('SELECT * FROM ps_cms_lang ORDER BY id_cms, id_lang'),
            $shop->rows('SELECT * FROM ps_nokey'),
        ]));
    }

    /** The content of a shop after the replacement in one go. */
    private function expected(): string
    {
        $shop = $this->shop();
        $context = $this->context($shop);
        (new ReadSourceState())->plan($context);
        $step = new PrestaShopReplace();
        $step->apply($context, $step->plan($context));

        return $this->content($shop);
    }

    public function testTicksOfThreeRowsGiveTheResultOfOneGo(): void
    {
        $expected = $this->expected();
        $shop = $this->shop();
        self::assertNotSame($expected, $this->content($shop), 'the shop starts with the old domain');

        $state = [];
        $resumedInTheMiddle = false;
        for ($tick = 1; $tick <= 500; ++$tick) {
            $context = $this->context($shop);                  // a new process: nothing from the earlier tick is left
            (new ReadSourceState())->plan($context);
            $outcome = (new PrestaShopReplace())->applyTick($context, new Budget(0.0), json_decode(json_encode($state, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
            $state = $outcome->state;
            $resumedInTheMiddle = $resumedInTheMiddle || ($state['after'] ?? null) !== null;
            if ($outcome->finished) {
                break;
            }
        }

        self::assertTrue($outcome->finished);
        self::assertGreaterThan(10, $tick);
        self::assertTrue($resumedInTheMiddle, 'a table was left in the middle and continued after its last key');
        self::assertSame($expected, $this->content($shop));
        self::assertSame(40 - 10 + 12, $outcome->result?->applied, 'rows changed: 30 notes with a domain, 12 cms rows');
        $targets = array_map(static fn ($c): string => $c->target, $outcome->plan?->changes() ?? []);
        self::assertContains('db ps_note.text', $targets, 'the final plan lists what was really changed');
        self::assertContains('db ps_cms_lang.content', $targets);
    }

    public function testATickWhoseStateWasLostIsHarmless(): void
    {
        $expected = $this->expected();
        $shop = $this->shop();

        $state = [];
        $saved = null;
        $lost = false;
        for ($tick = 1; $tick <= 500; ++$tick) {
            $context = $this->context($shop);
            (new ReadSourceState())->plan($context);
            $outcome = (new PrestaShopReplace())->applyTick($context, new Budget(0.0), $state);
            $state = $outcome->state;
            if ($tick === 6) {
                $saved = $state;                                // this state is saved ...
            }
            if ($tick === 8 && !$lost) {
                $state = $saved ?? [];                          // ... and the process dies after tick 8: its work stays, its state is lost
                $lost = true;
                continue;
            }
            if ($outcome->finished) {
                break;
            }
        }

        self::assertTrue($lost);
        self::assertSame($expected, $this->content($shop));
    }

    public function testTheTickedRunnerRunsTheGenericPipelineInManyProcessesWithOneJournal(): void
    {
        $expected = $this->expected();
        $shop = $this->shop();
        $journalDirectory = $this->tempDirectory() . '/journal';
        $steps = $this->genericSteps(...);

        $state = (new TickedRunner($steps()))->initialState();
        for ($tick = 1; $tick <= 500; ++$tick) {
            $runner = new TickedRunner($steps());
            $journal = new FileJournal($journalDirectory, null, is_string($state['journal']) ? $state['journal'] : null);
            $outcome = $runner->tick(
                json_decode(json_encode($state, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR),
                new Context($this->request($shop), $journal, new NullLogger(), false),
                new Budget(0.0),
            );
            $state = $outcome->state;
            $journal->close();
            if ($outcome->finished) {
                break;
            }
        }

        self::assertTrue($outcome->finished, (string) $outcome->progress);
        self::assertGreaterThan(10, $tick);
        self::assertSame($expected, $this->content($shop));
        self::assertNull($state['failure']);

        $lines = file((string) $state['journal'], FILE_IGNORE_NEW_LINES) ?: [];
        $types = array_count_values(array_map(static fn (string $l): string => (string) (json_decode($l, true)['type'] ?? ''), $lines));
        self::assertSame(1, $types['header'], 'one journal for the whole run');
        self::assertSame(30 + 2 * 12, $types['db'], 'every changed cell is in it: 30 notes, content and title of 12 cms rows');
        self::assertCount(1, glob($journalDirectory . '/*.jsonl') ?: []);
    }

    public function testTheFindingsOfTheShopSurviveWhileTheShopChanges(): void
    {
        $expected = $this->expected();
        $shop = $this->shop();
        $steps = $this->prestaShopSteps(...);

        $runner = new TickedRunner($steps());
        $journalDirectory = $this->tempDirectory() . '/journal';
        $journal = new FileJournal($journalDirectory);
        $outcome = $runner->tick($runner->initialState(), new Context($this->request($shop), $journal, new NullLogger(), false), new Budget(0.0));
        $journal->close();
        self::assertFalse($outcome->finished);
        self::assertArrayHasKey('read-source-state', $outcome->state['findings']);
        self::assertSame('old.test', $outcome->state['findings']['read-source-state']['shopUrls'][0]['domain']);

        // what a migration does between the ticks: the shop now has the new domain
        $shop->exec("UPDATE ps_shop_url SET domain = 'new.example', domain_ssl = 'new.example'");

        $state = $outcome->state;
        for ($tick = 2; $tick <= 500; ++$tick) {
            $runner = new TickedRunner($steps());
            $journal = new FileJournal($journalDirectory, null, (string) $state['journal']);
            $outcome = $runner->tick(json_decode(json_encode($state, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR), new Context($this->request($shop), $journal, new NullLogger(), false), new Budget(0.0));
            $journal->close();
            $state = $outcome->state;
            if ($outcome->finished) {
                break;
            }
        }

        self::assertTrue($outcome->finished, (string) $outcome->progress);
        self::assertNull($state['failure']);
        self::assertSame($expected, $this->content($shop));
    }
}
