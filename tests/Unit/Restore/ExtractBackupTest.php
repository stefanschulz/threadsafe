<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Restore;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Restore\Extractor;
use ThreadSafe\Storage\BackupCatalog;
use ThreadSafe\Tests\Support\BackupFixture;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * The extractor on real backups made by the backup engine - in one part and in several - compared with the site they were made
 * from. Needs no database: the fixture site has none.
 */
final class ExtractBackupTest extends TestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    /** @return iterable<string, array{int|null, bool}> */
    public static function runs(): iterable
    {
        yield 'one part, one tick' => [null, false];
        yield 'one part, a tick per entry' => [null, true];
        yield 'parts of 1 MiB, a tick per entry' => [1_048_576, true];
    }

    #[DataProvider('runs')]
    public function testTheBackupComesBackAsTheSite(?int $partSize, bool $tickPerEntry): void
    {
        $fixture = new BackupFixture($this->tempDirectory());
        $name = $fixture->backup($partSize === null ? [] : ['partSize' => $partSize]);
        $parts = (new BackupCatalog($fixture->storage()))->archiveParts($name);
        if ($partSize !== null) {
            self::assertGreaterThan(1, count($parts), 'the backup is split');
        }
        $root = $this->tempDirectory() . '/restored';
        $database = $this->tempDirectory() . '/db';

        $extractor = new Extractor();
        $state = $extractor->initialState(['parts' => $parts, 'root' => $root, 'database' => $database]);
        $ticks = 0;
        do {
            $outcome = (new Extractor())->tick(
                json_decode(json_encode($state, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR),
                $tickPerEntry ? new Budget(0.0) : Budget::unlimited(),
            );
            $state = $outcome->state;
            ++$ticks;
        } while (!$outcome->finished && $ticks < 5000);

        self::assertTrue($outcome->finished);
        if ($tickPerEntry) {
            self::assertGreaterThan(3, $ticks);
        }
        self::assertSame($this->tree($fixture->site), $this->tree($root), 'the same files with the same content');
        self::assertSame([], $state['warnings']);
        self::assertSame($state['total'], $state['bytes']);
    }

    /**
     * @return array<string, string> relative path => hash of the content (directories: 'dir')
     */
    private function tree(string $directory): array
    {
        $result = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $path => $info) {
            $relative = substr((string) $path, strlen($directory) + 1);
            $result[$relative] = $info->isDir() ? 'dir' : (string) md5_file((string) $path);
        }
        ksort($result);

        return $result;
    }
}
