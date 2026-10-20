<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Integration;

use ThreadSafe\Core\FileJournal;
use ThreadSafe\Core\NullJournal;
use ThreadSafe\Migration\MigrationRequest;
use ThreadSafe\PrestaShop9\Steps\DiscardCaches;
use ThreadSafe\PrestaShop9\Steps\ReadSourceState;
use ThreadSafe\Tests\Support\ScratchShop;
use ThreadSafe\Tests\Support\TempDirectory;

final class DiscardCachesTest extends IntegrationTestCase
{
    use TempDirectory;

    private ScratchShop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = ScratchShop::create();
        $this->shop->exec("INSERT INTO ps_smarty_cache (id_smarty_cache, content) VALUES ('a', 'x'), ('b', 'y')");
        $this->shop->exec("INSERT INTO ps_smarty_lazy_cache (template_hash, content) VALUES ('h', 'z')");
    }

    protected function tearDown(): void
    {
        $this->shop->destroy();
        $this->cleanUpTempDirectories();
    }

    private function context(?FileJournal $journal = null): \ThreadSafe\Core\Context
    {
        $context = $this->shop->context(new MigrationRequest($this->shop->root), $journal ?? new NullJournal(), $journal === null);
        (new ReadSourceState())->plan($context);

        return $context;
    }

    public function testPlanCountsFilesAndRows(): void
    {
        $context = $this->context();

        $plan = (new DiscardCaches())->plan($context);

        $byTarget = [];
        foreach ($plan->changes() as $change) {
            $byTarget[$change->target] = $change->count;
        }
        // var/cache: appParameters.php, twig/a/b.php, dev/c.php (CACHEDIR.TAG and index.php are kept)
        self::assertSame(3, $byTarget['dir var/cache']);
        self::assertSame(1, $byTarget['dir cache/smarty/cache']);
        self::assertSame(1, $byTarget['dir cache/smarty/compile']);
        self::assertSame(2, $byTarget['db ps_smarty_cache']);
        self::assertSame(1, $byTarget['db ps_smarty_lazy_cache']);
        self::assertArrayNotHasKey('db ps_smarty_last_flush', $byTarget, 'Tables that do not exist are ignored.');
    }

    public function testPlanMentionsTheCopyOfTheOldSettings(): void
    {
        $plan = (new DiscardCaches())->plan($this->context());

        $messages = array_map(static fn ($n): string => $n->message, $plan->notices());
        self::assertNotEmpty(array_filter($messages, static fn (string $m): bool => str_contains($m, 'database password')));
    }

    public function testDryRunDeletesNothing(): void
    {
        $context = $this->context();

        (new DiscardCaches())->plan($context);

        self::assertFileExists($this->shop->root . '/var/cache/prod/appParameters.php');
        self::assertSame('2', $this->shop->value('SELECT COUNT(*) FROM ps_smarty_cache'));
    }

    public function testApplyEmptiesCachesButKeepsProtectionFiles(): void
    {
        $journal = new FileJournal($this->tempDirectory());
        $context = $this->context($journal);
        $step = new DiscardCaches();

        $result = $step->apply($context, $step->plan($context));

        self::assertSame(3 + 1 + 1 + 2 + 1, $result->applied);
        self::assertFileDoesNotExist($this->shop->root . '/var/cache/prod');
        self::assertFileDoesNotExist($this->shop->root . '/var/cache/dev');
        self::assertFileExists($this->shop->root . '/var/cache/CACHEDIR.TAG');
        self::assertFileExists($this->shop->root . '/var/cache/index.php');
        self::assertDirectoryExists($this->shop->root . '/var/cache');
        self::assertDirectoryExists($this->shop->root . '/cache/smarty/cache');
        self::assertFileDoesNotExist($this->shop->root . '/cache/smarty/cache/one.php');
        self::assertSame('0', $this->shop->value('SELECT COUNT(*) FROM ps_smarty_cache'));
        self::assertSame('0', $this->shop->value('SELECT COUNT(*) FROM ps_smarty_lazy_cache'));

        $notes = file_get_contents((string) $journal->path());
        self::assertIsString($notes);
        self::assertStringContainsString('cache directory emptied', $notes);
        self::assertStringContainsString('cache table emptied', $notes);
    }

    public function testSecondRunHasNothingToDo(): void
    {
        $context = $this->context(new FileJournal($this->tempDirectory()));
        $step = new DiscardCaches();
        $step->apply($context, $step->plan($context));

        self::assertFalse($step->plan($context)->hasChanges());
    }

    public function testSymbolicLinksAreRemovedButNeverFollowed(): void
    {
        $outside = $this->tempDirectory();
        file_put_contents($outside . '/precious.txt', 'keep me');
        symlink($outside, $this->shop->root . '/var/cache/link-to-outside');
        $context = $this->context(new FileJournal($this->tempDirectory()));
        $step = new DiscardCaches();

        $step->apply($context, $step->plan($context));

        self::assertFileExists($outside . '/precious.txt');
        self::assertFalse(is_link($this->shop->root . '/var/cache/link-to-outside'));
    }

    public function testRefusesACacheDirectoryThatLeadsOutOfTheShop(): void
    {
        $outside = $this->tempDirectory();
        file_put_contents($outside . '/precious.txt', 'keep me');
        $this->removeTree($this->shop->root . '/cache/smarty/cache');
        symlink($outside, $this->shop->root . '/cache/smarty/cache');
        $context = $this->context();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not inside the shop root');

        (new DiscardCaches())->plan($context);
    }

    private function removeTree(string $path): void
    {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                unlink($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
