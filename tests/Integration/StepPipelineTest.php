<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Integration;

use ThreadSafe\Core\ExitCode;
use ThreadSafe\Core\FileJournal;
use ThreadSafe\Core\NullJournal;
use ThreadSafe\Core\Runner;
use ThreadSafe\Core\StepStatus;
use ThreadSafe\Migration\MigrationRequest;
use ThreadSafe\Migration\SslMode;
use ThreadSafe\PrestaShop9\Steps\DiscardCaches;
use ThreadSafe\PrestaShop9\Steps\ReadSourceState;
use ThreadSafe\PrestaShop9\Steps\ReplaceContentDomains;
use ThreadSafe\PrestaShop9\Steps\UpdateConfiguration;
use ThreadSafe\Replace\DomainMapping;
use ThreadSafe\Tests\Support\ScratchShop;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * The four database steps together, driven by the Runner: dry run, apply, apply again.
 */
final class StepPipelineTest extends IntegrationTestCase
{
    use TempDirectory;

    private ScratchShop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = ScratchShop::create('old.test');
        $this->shop->exec('INSERT INTO ps_cms_lang (id_cms, id_lang, content) VALUES (1, 1, ?)', ['<a href="http://old.test/x">x</a>']);
        $this->shop->exec("INSERT INTO ps_smarty_cache (id_smarty_cache, content) VALUES ('k', 'cached')");
    }

    protected function tearDown(): void
    {
        $this->shop->destroy();
        $this->cleanUpTempDirectories();
    }

    private function runner(): Runner
    {
        return new Runner([new ReadSourceState(), new DiscardCaches(), new UpdateConfiguration(), new ReplaceContentDomains()]);
    }

    private function request(): MigrationRequest
    {
        return new MigrationRequest($this->shop->root, [new DomainMapping('old.test', 'new.test')], ssl: SslMode::All);
    }

    public function testDryRunReportsEverythingAndChangesNothing(): void
    {
        $context = $this->shop->context($this->request(), new NullJournal(), dryRun: true);

        $result = $this->runner()->run($context, apply: false);

        self::assertSame(ExitCode::Ok, $result->exitCode);
        self::assertSame(
            ['unchanged', 'planned', 'planned', 'planned'],
            array_map(static fn ($s): string => $s->status->value, $result->report->steps),
        );
        self::assertGreaterThan(0, $result->report->plannedChanges());
        self::assertSame(0, $result->report->appliedChanges());
        self::assertSame('old.test', $this->shop->value('SELECT domain FROM ps_shop_url'));
        self::assertSame('1', $this->shop->value('SELECT COUNT(*) FROM ps_smarty_cache'));
        self::assertFileExists($this->shop->root . '/var/cache/prod/appParameters.php');
    }

    public function testApplyMigratesAndASecondRunFindsNothingToDo(): void
    {
        $journal = new FileJournal($this->tempDirectory());
        $first = $this->runner()->run($this->shop->context($this->request(), $journal), apply: true);

        self::assertSame(ExitCode::Ok, $first->exitCode);
        self::assertSame('new.test', $this->shop->value('SELECT domain FROM ps_shop_url'));
        self::assertSame('<a href="http://new.test/x">x</a>', $this->shop->value('SELECT content FROM ps_cms_lang'));
        self::assertSame('0', $this->shop->value('SELECT COUNT(*) FROM ps_smarty_cache'));
        self::assertFileDoesNotExist($this->shop->root . '/var/cache/prod/appParameters.php');
        self::assertSame($journal->path(), $first->report->journalPath);

        $second = $this->runner()->run($this->shop->context($this->request(), new FileJournal($this->tempDirectory())), apply: true);

        self::assertSame(ExitCode::Ok, $second->exitCode);
        self::assertSame(0, $second->report->plannedChanges(), 'A repeated run must not change anything.');
        foreach ($second->report->steps as $step) {
            self::assertSame(StepStatus::Unchanged, $step->status, $step->id);
        }
    }

    public function testWrongDomainIsRefusedBeforeAnythingHappens(): void
    {
        $request = new MigrationRequest($this->shop->root, [new DomainMapping('wrong.test', 'new.test')]);

        $result = $this->runner()->run($this->shop->context($request, new FileJournal($this->tempDirectory())), apply: true);

        self::assertSame(ExitCode::GuardFailed, $result->exitCode);
        self::assertSame('old.test', $this->shop->value('SELECT domain FROM ps_shop_url'));
        self::assertFileExists($this->shop->root . '/var/cache/prod/appParameters.php');
    }

    public function testRestoreInPlaceOnlyDiscardsCaches(): void
    {
        $result = $this->runner()->run(
            $this->shop->context(new MigrationRequest($this->shop->root), new FileJournal($this->tempDirectory())),
            apply: true,
        );

        self::assertSame(ExitCode::Ok, $result->exitCode);
        $statuses = [];
        foreach ($result->report->steps as $step) {
            $statuses[$step->id] = $step->status;
        }
        self::assertSame(StepStatus::Applied, $statuses['discard-caches']);
        self::assertSame(StepStatus::Unchanged, $statuses['update-configuration']);
        self::assertSame(StepStatus::Unchanged, $statuses['replace-content-domains']);
        self::assertSame('old.test', $this->shop->value('SELECT domain FROM ps_shop_url'));
    }
}
