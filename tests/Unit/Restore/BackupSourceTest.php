<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Restore;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Core\ExitCode;
use ThreadSafe\Restore\BackupSource;
use ThreadSafe\Restore\RestoreException;
use ThreadSafe\Storage\BackupCatalog;
use ThreadSafe\Tests\Support\BackupFixture;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * Finding a backup from any one of its parts.
 */
final class BackupSourceTest extends TestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return list<string>
     */
    private function parts(BackupFixture $fixture, array $parameters = []): array
    {
        return (new BackupCatalog($fixture->storage()))->archiveParts($fixture->backup($parameters));
    }

    public function testASinglePartIsFound(): void
    {
        $fixture = new BackupFixture($this->tempDirectory());
        $parts = $this->parts($fixture);

        $source = BackupSource::open($parts[0]);

        self::assertCount(1, $source->parts);
        self::assertSame($parts[0], $source->parts[0]);
        self::assertSame('generic', $source->manifest->type);
    }

    public function testAnyPartOfASplitBackupLeadsToAllParts(): void
    {
        $fixture = new BackupFixture($this->tempDirectory());
        $parts = $this->parts($fixture, ['partSize' => 1_048_576]);
        self::assertGreaterThan(1, count($parts));

        foreach ($parts as $part) {
            $source = BackupSource::open($part);
            self::assertSame($parts, $source->parts, 'the same parts, in order, from ' . basename($part));
            self::assertSame(count($parts), count($source->manifest->parts));
        }
    }

    public function testAMissingPartIsNamed(): void
    {
        $fixture = new BackupFixture($this->tempDirectory());
        file_put_contents($fixture->site . '/assets/third.bin', random_bytes(1_200_000));
        $parts = $this->parts($fixture, ['partSize' => 1_048_576]);
        self::assertGreaterThan(2, count($parts));
        unlink($parts[1]);

        try {
            BackupSource::open($parts[0]);
            self::fail('A missing part should be refused.');
        } catch (RestoreException $e) {
            self::assertStringContainsString(basename($parts[1]), $e->getMessage());
            self::assertSame(ExitCode::GuardFailed, $e->exitCode);
        }
    }

    public function testAMissingLastPartIsFoundOutThroughTheManifest(): void
    {
        $fixture = new BackupFixture($this->tempDirectory());
        $parts = $this->parts($fixture, ['partSize' => 1_048_576]);
        unlink((string) end($parts));

        $this->expectException(RestoreException::class);
        $this->expectExceptionMessage('later part');
        BackupSource::open($parts[0]);
    }

    public function testSomethingElseIsNotABackup(): void
    {
        $directory = $this->tempDirectory();
        file_put_contents($directory . '/notes.zip', 'x');

        $this->expectException(RestoreException::class);
        $this->expectExceptionMessage('not a part of a ThreadSafe backup');
        BackupSource::open($directory . '/notes.zip');
    }

    public function testAMissingFileIsAnError(): void
    {
        $this->expectException(RestoreException::class);
        BackupSource::open($this->tempDirectory() . '/blog_20261020-123456_ab12cd34.zip');
    }
}
