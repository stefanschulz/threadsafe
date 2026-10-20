<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Backup;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ThreadSafe\Backup\FileList;
use ThreadSafe\Backup\Filters;
use ThreadSafe\Backup\ScanItem;
use ThreadSafe\Backup\Scanner;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Tests\Support\TempDirectory;

final class ScannerTest extends TestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    private function site(): string
    {
        return $this->tempDirectory();
    }

    private function put(string $root, string $relative, string $content = 'x'): void
    {
        $path = $root . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $content);
    }

    /**
     * Scans everything, in batches of $batch items, sending the state through JSON between the calls as the engine does.
     *
     * @return array{list<ScanItem>, list<string>}
     */
    private function scan(string $root, Filters $filters, int $batch = 1000): array
    {
        $scanner = new Scanner($root, $filters);
        $state = $scanner->start();
        $items = [];
        $warnings = [];
        do {
            $result = $scanner->next($state, Budget::unlimited(), $batch);
            array_push($items, ...$result->items);
            array_push($warnings, ...$result->warnings);
            $state = json_decode((string) json_encode($result->state), true);
            self::assertIsArray($state);
        } while (!$result->done);

        return [$items, $warnings];
    }

    /**
     * @param list<ScanItem> $items
     *
     * @return list<string>
     */
    private static function paths(array $items): array
    {
        return array_map(static fn (ScanItem $i): string => $i->path . ($i->isDirectory ? '/' : ''), $items);
    }

    public function testSortedDepthFirstWithDirectoryEntriesBeforeTheirContents(): void
    {
        $root = $this->site();
        $this->put($root, 'b.txt');
        $this->put($root, 'a/z.txt');
        $this->put($root, 'a/y/deep.txt');
        $this->put($root, 'a.txt');
        mkdir($root . '/empty');

        [$items, $warnings] = $this->scan($root, new Filters());

        self::assertSame(['a/', 'a/y/', 'a/y/deep.txt', 'a/z.txt', 'a.txt', 'b.txt', 'empty/'], self::paths($items));
        self::assertSame([], $warnings);
    }

    public function testItemsCarrySizeMtimeAndMode(): void
    {
        $root = $this->site();
        $this->put($root, 'dir/f.txt', 'hello world');
        touch($root . '/dir/f.txt', 1_700_000_000);
        chmod($root . '/dir/f.txt', 0640);
        chmod($root . '/dir', 0750);

        [$items] = $this->scan($root, new Filters());

        self::assertTrue($items[0]->isDirectory);
        self::assertSame(0, $items[0]->size);
        self::assertSame(040750, $items[0]->mode);
        self::assertSame(11, $items[1]->size);
        self::assertSame(1_700_000_000, $items[1]->mtime);
        self::assertSame(0100640, $items[1]->mode);
    }

    public function testExcludedTreesAreNotEntered(): void
    {
        $root = $this->site();
        $this->put($root, 'var/cache/a/b/c.php');
        $this->put($root, 'var/logs/x.log');
        $this->put($root, 'var/keep.txt');
        $this->put($root, 'modules/m/.git/HEAD');
        $this->put($root, 'modules/m/main.php');
        $this->put($root, 'error.log');

        [$items, $warnings] = $this->scan($root, new Filters(['var/cache', '*.log', '**/.git']));

        self::assertSame(['modules/', 'modules/m/', 'modules/m/main.php', 'var/', 'var/keep.txt', 'var/logs/'], self::paths($items));
        self::assertSame([], $warnings, 'Excluded means no entry and no warning.');
    }

    public function testStorageDirectoryInsideTheSiteCanBeExcludedExactly(): void
    {
        $root = $this->site();
        $this->put($root, 'backups/shop/old.zip');
        $this->put($root, 'index.php');

        $relative = Filters::relativeInside($root, $root . '/backups/shop');
        [$items] = $this->scan($root, new Filters([], [(string) $relative]));

        self::assertSame(['backups/', 'index.php'], self::paths($items));
    }

    public function testSymbolicLinksAreSkippedWithAWarning(): void
    {
        $root = $this->site();
        $this->put($root, 'real/f.txt');
        symlink($root . '/real', $root . '/link-to-dir');
        symlink('/etc/hostname', $root . '/link-to-file');
        symlink($root . '/nothing', $root . '/broken-link');

        [$items, $warnings] = $this->scan($root, new Filters(['broken-link']));

        self::assertSame(['real/', 'real/f.txt'], self::paths($items));
        self::assertCount(2, $warnings, 'The excluded link is not mentioned.');
        self::assertStringContainsString('Skipped the symbolic link /link-to-dir -> ' . $root . '/real', $warnings[0]);
        self::assertStringContainsString('/link-to-file -> /etc/hostname', $warnings[1]);
    }

    public function testSpecialFilesAreSkippedWithAWarning(): void
    {
        if (!function_exists('posix_mkfifo')) {
            self::markTestSkipped('posix_mkfifo is not available.');
        }
        $root = $this->site();
        $this->put($root, 'f.txt');
        posix_mkfifo($root . '/pipe', 0600);

        [$items, $warnings] = $this->scan($root, new Filters());

        self::assertSame(['f.txt'], self::paths($items));
        self::assertSame(['Skipped, neither a file nor a directory: /pipe'], $warnings);
    }

    public function testNamesThatCannotGoIntoAnArchiveAreSkippedWithAWarning(): void
    {
        $root = $this->site();
        $this->put($root, 'ok.txt');
        $this->put($root, "latin1-\xE9.txt");
        $this->put($root, 'back\\slash.txt');
        $this->put($root, 'sub/ümläut ✓.txt');

        [$items, $warnings] = $this->scan($root, new Filters());

        self::assertSame(['ok.txt', 'sub/', 'sub/ümläut ✓.txt'], self::paths($items));
        self::assertCount(2, $warnings);
        self::assertStringContainsString('not valid UTF-8', $warnings[0] . $warnings[1]);
        self::assertStringContainsString('cannot go into an archive (name contains a backslash)', $warnings[0] . $warnings[1]);
    }

    public function testUnreadableDirectoryIsAWarningNotAFailure(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root can read everything.');
        }
        $root = $this->site();
        $this->put($root, 'a/f.txt');
        $this->put($root, 'locked/g.txt');
        chmod($root . '/locked', 0000);

        try {
            [$items, $warnings] = $this->scan($root, new Filters());
        } finally {
            chmod($root . '/locked', 0755);
        }

        self::assertSame(['a/', 'a/f.txt', 'locked/'], self::paths($items));
        self::assertSame(['Cannot read the directory: /locked'], $warnings);
    }

    public function testFilesThatVanishBetweenCallsAreNoProblem(): void
    {
        $root = $this->site();
        $this->put($root, 'a.txt');
        $this->put($root, 'b.txt');
        $this->put($root, 'c/d.txt');
        $scanner = new Scanner($root, new Filters());

        $first = $scanner->next($scanner->start(), Budget::unlimited(), 1);
        self::assertSame(['a.txt'], self::paths($first->items));
        unlink($root . '/b.txt');                // vanishes
        $this->put($root, 'bb.txt');             // appears after the position
        $this->put($root, '0-before.txt');       // appears before the position: not seen, not an error

        $items = [];
        $state = $first->state;
        do {
            $batch = $scanner->next($state, Budget::unlimited(), 2);
            array_push($items, ...$batch->items);
            $state = $batch->state;
        } while (!$batch->done);

        self::assertSame(['bb.txt', 'c/', 'c/d.txt'], self::paths($items));
    }

    public function testDirectoryThatVanishesWhileOnTheStackIsDropped(): void
    {
        $root = $this->site();
        $this->put($root, 'a/f.txt');
        $this->put($root, 'z.txt');
        $scanner = new Scanner($root, new Filters());
        $first = $scanner->next($scanner->start(), Budget::unlimited(), 1);   // entered a/ (dir entry)
        self::assertSame(['a/'], self::paths($first->items));
        unlink($root . '/a/f.txt');
        rmdir($root . '/a');

        $rest = $scanner->next($first->state, Budget::unlimited());

        self::assertSame(['z.txt'], self::paths($rest->items));
        self::assertSame([], $rest->warnings, 'A directory that is gone is not an error.');
        self::assertTrue($rest->done);
    }

    public function testBudgetStopsTheBatchButEveryCallMakesProgress(): void
    {
        $root = $this->site();
        for ($i = 0; $i < 20; ++$i) {
            $this->put($root, sprintf('f%02d.txt', $i));
        }
        $scanner = new Scanner($root, new Filters());
        $exhausted = new Budget(0.0);   // out of time before it starts
        $state = $scanner->start();
        $total = 0;
        $calls = 0;

        do {
            $batch = $scanner->next($state, $exhausted);
            $total += count($batch->items);
            $state = $batch->state;
            ++$calls;
            self::assertLessThan(100, $calls, 'Progress must be made.');
        } while (!$batch->done);

        self::assertSame(20, $total);
        self::assertGreaterThan(1, $calls);
    }

    public function testRootMustBeAReadableDirectory(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not a readable directory');

        new Scanner($this->site() . '/missing', new Filters());
    }

    public function testInvalidStateIsRefused(): void
    {
        $scanner = new Scanner($this->site(), new Filters());

        foreach ([[], ['frames' => 'x'], ['frames' => [['dir' => '../etc', 'after' => null]]], ['frames' => [['dir' => '/abs', 'after' => null]]], ['frames' => [['dir' => 5]]]] as $state) {
            try {
                $scanner->next($state, Budget::unlimited());
                self::fail('A damaged state must be refused.');
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('state is invalid', $e->getMessage());
            }
        }
    }

    /** @return iterable<string, array{int}> */
    public static function seeds(): iterable
    {
        for ($seed = 1; $seed <= 30; ++$seed) {
            yield "seed $seed" => [$seed];
        }
    }

    /**
     * However the walk is cut into calls - one item at a time, random sizes - the sequence is the one of a single call.
     */
    #[DataProvider('seeds')]
    public function testResumingGivesTheSameSequence(int $seed): void
    {
        $random = new Randomizer(new Mt19937($seed));
        $root = $this->site();
        $directories = [''];
        for ($i = 0; $i < 25; ++$i) {
            $parent = $directories[$random->getInt(0, count($directories) - 1)];
            $name = ['a', 'b', 'c', 'dd', 'e.d', 'ü', 'Z', 'z'][$random->getInt(0, 7)] . $i;
            if ($random->getInt(0, 2) === 0) {
                mkdir($root . '/' . ltrim($parent . '/' . $name, '/'));
                $directories[] = ltrim($parent . '/' . $name, '/');
            } else {
                $this->put($root, ltrim($parent . '/' . $name . '.txt', '/'), str_repeat('x', $random->getInt(0, 50)));
            }
        }

        [$whole] = $this->scan($root, new Filters(['*.d0']), 100000);
        [$cut] = $this->scan($root, new Filters(['*.d0']), $random->getInt(1, 4));

        self::assertSame(self::paths($whole), self::paths($cut));
        self::assertEquals($whole, $cut);
    }

    public function testFileListRoundTripAndResume(): void
    {
        $directory = $this->tempDirectory();
        $path = $directory . '/files.list';
        $items = [
            new ScanItem('a/b.txt', false, 12, 1_700_000_000, 0100644),
            new ScanItem("dir/with \"quotes\" and ü ✓ and \t tab", false, 0, 5, 0100600),
            new ScanItem('dir', true, 0, 7, 040755),
        ];

        $size = FileList::append($path, 0, [$items[0]]);
        $size = FileList::append($path, $size, [$items[1], $items[2]]);

        [$read, $offset] = FileList::read($path, 0, 10);
        self::assertEquals($items, $read);
        self::assertSame($size, $offset);

        // a process died after saving $size: junk after it is dropped when the state continues
        file_put_contents($path, '["half writt', FILE_APPEND);
        [$partial] = FileList::read($path, 0, 10);
        self::assertCount(3, $partial, 'A half written last line is not there yet.');
        $size = FileList::append($path, $size, [new ScanItem('later.txt', false, 1, 1, 0100644)]);
        [$all] = FileList::read($path, 0, 10);
        self::assertSame(['a/b.txt', "dir/with \"quotes\" and ü ✓ and \t tab", 'dir', 'later.txt'], array_map(static fn (ScanItem $i): string => $i->path, $all));

        // reading in pieces by offset
        [$first, $next] = FileList::read($path, 0, 2);
        [$second] = FileList::read($path, $next, 10);
        self::assertCount(2, $first);
        self::assertCount(2, $second);
        self::assertSame($size, FileList::read($path, 0, 100)[1]);
    }

    public function testFileListRefusesAShorterFileThanTheStateSays(): void
    {
        $path = $this->tempDirectory() . '/files.list';
        FileList::append($path, 0, [new ScanItem('a', false, 1, 1, 0100644)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('shorter than the saved state');

        FileList::append($path, 10000, []);
    }
}
