<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Joomla;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Joomla\Steps\DiscardCaches;
use ThreadSafe\Joomla\Steps\EnsureSkeleton;
use ThreadSafe\Tests\Support\FakeConnection;
use ThreadSafe\Tests\Support\JoomlaSite;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * The directories that Joomla writes to (made again after a restore, because a backup leaves them out) and what is cleared after a move.
 */
final class SkeletonAndCachesTest extends TestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    private function root(): string
    {
        $root = (string) realpath($this->tempDirectory());
        JoomlaSite::build($root);

        return $root;
    }

    public function testTheMissingDirectoriesAreMadeWithTheirPlaceholder(): void
    {
        $root = $this->root();
        $context = JoomlaSite::context($root);
        $step = new EnsureSkeleton();

        $plan = $step->plan($context);
        $result = $step->apply($context, $plan);

        self::assertSame(4, $plan->changeCount());
        self::assertSame(4, $result->applied);
        foreach (['tmp', 'cache', 'administrator/cache', 'administrator/logs'] as $directory) {
            self::assertDirectoryExists($root . '/' . $directory);
            self::assertSame("<!DOCTYPE html><title></title>\n", file_get_contents($root . '/' . $directory . '/index.html'), $directory);
        }
        self::assertFalse($step->plan($context)->hasChanges(), 'idempotent: a second plan is empty');
    }

    public function testADirectoryThatExistsIsLeftAsItIs(): void
    {
        $root = $this->root();
        mkdir($root . '/tmp');
        file_put_contents($root . '/tmp/keep.txt', 'mine');
        $context = JoomlaSite::context($root);
        $step = new EnsureSkeleton();

        $plan = $step->plan($context);
        $step->apply($context, $plan);

        self::assertSame(3, $plan->changeCount());
        self::assertSame('mine', file_get_contents($root . '/tmp/keep.txt'));
        self::assertFileDoesNotExist($root . '/tmp/index.html', 'an existing directory is not touched at all');
    }

    private function filled(string $root): void
    {
        foreach (['tmp', 'cache', 'administrator/cache', 'administrator/logs'] as $directory) {
            mkdir($root . '/' . $directory, 0777, true);
            file_put_contents($root . '/' . $directory . '/index.html', EnsureSkeleton::PLACEHOLDER);
        }
        file_put_contents($root . '/cache/page.cache', 'x');
        mkdir($root . '/cache/com_content/deeper', 0777, true);
        file_put_contents($root . '/cache/com_content/deeper/a.php', 'x');
        file_put_contents($root . '/administrator/cache/b.cache', 'x');
        file_put_contents($root . '/tmp/upload.tmp', 'x');
        file_put_contents($root . '/administrator/logs/error.php', 'x');
    }

    public function testTheCachesAreEmptiedTheirPlaceholdersStayAndTheLogsAreKept(): void
    {
        $root = $this->root();
        $this->filled($root);
        $db = FakeConnection::with(['~information_schema~' => [['n' => '1']], '~COUNT\(\*\) FROM `joom_session`~' => [['c' => '7']], '~COUNT\(\*\) FROM `joom_updates`~' => [['c' => '0']]], 'joom_', $log);
        $context = JoomlaSite::context($root, connection: $db);
        $step = new DiscardCaches();

        $plan = $step->plan($context);
        $result = $step->apply($context, $plan);

        self::assertSame(['cache' => ['index.html'], 'administrator/cache' => ['index.html'], 'tmp' => ['index.html'], 'administrator/logs' => ['error.php', 'index.html']], [
            'cache' => array_values(array_diff(scandir($root . '/cache') ?: [], ['.', '..'])),
            'administrator/cache' => array_values(array_diff(scandir($root . '/administrator/cache') ?: [], ['.', '..'])),
            'tmp' => array_values(array_diff(scandir($root . '/tmp') ?: [], ['.', '..'])),
            'administrator/logs' => array_values(array_diff(scandir($root . '/administrator/logs') ?: [], ['.', '..'])),
        ]);
        self::assertSame(2 + 1 + 1 + 7, $result->applied, 'two entries of cache, one of administrator/cache, one of tmp, seven sessions');
        self::assertContains('TRUNCATE TABLE `joom_session`', $log);
        self::assertNotContains('TRUNCATE TABLE `joom_updates`', $log, 'an empty table is not truncated');
    }

    public function testNothingToClearIsAnEmptyPlan(): void
    {
        $root = $this->root();
        $db = FakeConnection::with(['~information_schema~' => [['n' => '0']]], 'joom_');

        $plan = (new DiscardCaches())->plan(JoomlaSite::context($root, connection: $db));

        self::assertFalse($plan->hasChanges());
    }
}
