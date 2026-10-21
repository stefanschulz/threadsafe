<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Core;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ThreadSafe\Core\FileJournal;
use ThreadSafe\Tests\Support\TempDirectory;

final class FileJournalTest extends TestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lines(FileJournal $journal): array
    {
        $content = file_get_contents((string) $journal->path());
        self::assertIsString($content);
        $lines = [];
        foreach (array_filter(explode("\n", $content)) as $line) {
            $lines[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        }

        return $lines;
    }

    public function testCreatesProtectedDirectoryAndFile(): void
    {
        $directory = $this->tempDirectory() . '/journal/nested';

        $journal = new FileJournal($directory, new DateTimeImmutable('2026-10-20 12:34:56'));

        self::assertDirectoryExists($directory);
        self::assertSame(0700, fileperms($directory) & 0777);
        self::assertSame(0600, fileperms((string) $journal->path()) & 0777);
        self::assertMatchesRegularExpression('~/threadsafe-journal-20261020-123456-[0-9a-f]{4}\.jsonl$~', (string) $journal->path());
    }

    public function testFirstLineIsTheHeader(): void
    {
        $journal = new FileJournal($this->tempDirectory(), new DateTimeImmutable('2026-10-20T12:00:00+00:00'));

        $lines = $this->lines($journal);

        self::assertSame('header', $lines[0]['type']);
        self::assertSame(1, $lines[0]['format']);
        self::assertSame('threadsafe', $lines[0]['tool']);
        self::assertSame('2026-10-20T12:00:00+00:00', $lines[0]['started']);
    }

    public function testEntriesAreWrittenImmediatelyAndBelongToTheCurrentStep(): void
    {
        $journal = new FileJournal($this->tempDirectory());

        $journal->step('update-config');
        $journal->database('ps_configuration', ['id_configuration' => 4], 'value', 'old.example', 'new.example');
        $journal->file('/shop/.htaccess', 'update', 'files/0001-.htaccess.orig');
        $journal->note('skipped table', ['table' => 'ps_connections']);

        // not closed yet: a crash here must not lose anything
        $lines = $this->lines($journal);

        self::assertSame(['header', 'step', 'db', 'file', 'note'], array_column($lines, 'type'));
        self::assertSame('update-config', $lines[2]['step']);
        self::assertSame(['id_configuration' => 4], $lines[2]['key']);
        self::assertSame('old.example', $lines[2]['old']);
        self::assertSame('new.example', $lines[2]['new']);
        self::assertSame('files/0001-.htaccess.orig', $lines[3]['backup']);
        self::assertSame(['table' => 'ps_connections'], $lines[4]['context']);
        self::assertArrayHasKey('t', $lines[2]);
    }

    public function testValuesThatAreNotUtf8AreStoredAsBase64(): void
    {
        $journal = new FileJournal($this->tempDirectory());
        $binary = "\xff\xfe\x00binary";

        $journal->database('t', ['id' => 1], 'c', $binary, null);

        $entry = $this->lines($journal)[1];
        self::assertSame(['base64' => base64_encode($binary)], $entry['old']);
        self::assertNull($entry['new']);
    }

    public function testBackupFileCopiesTheOriginalIntoTheJournal(): void
    {
        $directory = $this->tempDirectory();
        $original = $directory . '/parameters.php';
        file_put_contents($original, "<?php return ['secret'];");
        $journal = new FileJournal($directory . '/journal');

        $first = $journal->backupFile($original);
        $second = $journal->backupFile($original);

        self::assertSame('files/0001-parameters.php.orig', $first);
        self::assertSame('files/0002-parameters.php.orig', $second);
        self::assertSame("<?php return ['secret'];", file_get_contents($directory . '/journal/' . $first));
        self::assertSame(0600, fileperms($directory . '/journal/' . $first) & 0777);
        self::assertSame(0700, fileperms($directory . '/journal/files') & 0777);
    }

    public function testBackupOfAMissingFileReturnsNull(): void
    {
        $journal = new FileJournal($this->tempDirectory());

        self::assertNull($journal->backupFile('/does/not/exist'));
    }

    public function testBackupNamesAreSanitised(): void
    {
        $directory = $this->tempDirectory();
        file_put_contents($directory . '/we ird;name.txt', 'x');
        $journal = new FileJournal($directory . '/journal');

        self::assertSame('files/0001-we_ird_name.txt.orig', $journal->backupFile($directory . '/we ird;name.txt'));
    }

    public function testTwoJournalsCreatedInTheSameSecondDoNotCollide(): void
    {
        $directory = $this->tempDirectory();
        $now = new DateTimeImmutable('2026-10-20 12:00:00');

        $a = new FileJournal($directory, $now);
        $b = new FileJournal($directory, $now);

        self::assertNotSame($a->path(), $b->path());
    }

    public function testWritingToAClosedJournalFails(): void
    {
        $journal = new FileJournal($this->tempDirectory());
        $journal->close();

        $this->expectException(RuntimeException::class);

        $journal->note('too late');
    }

    public function testAnotherProcessContinuesTheSameJournalAndItsBackupNumbers(): void
    {
        $directory = $this->tempDirectory() . '/journal';
        $file = $this->tempDirectory() . '/config.php';
        file_put_contents($file, 'original');

        $first = new FileJournal($directory);
        $first->step('one');
        $first->database('ps_x', ['id' => 1], 'text', 'old', 'new');
        $first->file($file, 'update', $first->backupFile($file));
        $path = $first->path();
        $first->close();

        $second = new FileJournal($directory, null, $path);
        $second->step('two');
        $copy = $second->backupFile($file);
        $second->close();

        self::assertSame($path, $second->path());
        self::assertSame('files/0002-config.php.orig', $copy, 'the numbering goes on, no copy is overwritten');
        self::assertCount(1, glob($directory . '/*.jsonl') ?: []);
        $types = array_map(static fn (string $l): string => (string) (json_decode($l, true)['type'] ?? ''), file($path, FILE_IGNORE_NEW_LINES) ?: []);
        self::assertSame(['header', 'step', 'db', 'file', 'note', 'step'], $types);
    }

    public function testOnlyAJournalOfTheSameDirectoryCanBeContinued(): void
    {
        $this->expectException(RuntimeException::class);
        new FileJournal($this->tempDirectory() . '/journal', null, $this->tempDirectory() . '/elsewhere.jsonl');
    }
}
