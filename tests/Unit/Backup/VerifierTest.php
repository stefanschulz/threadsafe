<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Backup;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Backup\BackupStatus;
use ThreadSafe\Backup\Verifier;
use ThreadSafe\Storage\BackupCatalog;
use ThreadSafe\Storage\BackupEntry;
use ThreadSafe\Tests\Support\BackupFixture;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * Verification and the catalog on real backups, intact and damaged in every way the plan names.
 */
final class VerifierTest extends TestCase
{
    use TempDirectory;

    private BackupFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new BackupFixture($this->tempDirectory());
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    private function verifier(): Verifier
    {
        $storage = $this->fixture->storage();

        return new Verifier($storage);
    }

    public function testAnIntactBackupPassesBothLevels(): void
    {
        $name = $this->fixture->backup();

        foreach ([false, true] as $full) {
            $report = $this->verifier()->verify($name, $full);
            self::assertTrue($report->ok(), implode("\n", $report->problems));
            self::assertSame(BackupStatus::Ok, $report->status);
            self::assertSame(1, $report->parts);
            self::assertGreaterThan(700_000, $report->bytes);
            self::assertSame($full, $report->full);
        }
    }

    public function testAFlippedBitIsFoundByBothLevels(): void
    {
        $name = $this->fixture->backup();
        $this->fixture->damage($this->fixture->storage()->partPath($name, 1, false));

        $quick = $this->verifier()->verify($name, false);
        self::assertFalse($quick->ok());
        self::assertStringContainsString('SHA-256', $quick->problems[0]);
        self::assertSame(BackupStatus::Faulty, $quick->status);
        self::assertSame(BackupStatus::Faulty, $this->fixture->storage()->readSidecar($name)->status, 'The result is in the sidecar file.');

        $full = $this->verifier()->verify($name, true);
        self::assertFalse($full->ok());
        self::assertNotSame([], array_filter($full->problems, static fn (string $p): bool => str_contains($p, 'SHA-256')));
        self::assertNotSame([], array_filter($full->problems, static fn (string $p): bool => !str_contains($p, 'SHA-256')), 'The full check says what is wrong inside, too: ' . implode(' | ', $full->problems));
    }

    public function testATruncatedPart(): void
    {
        $name = $this->fixture->backup();
        $path = $this->fixture->storage()->partPath($name, 1, false);
        file_put_contents($path, substr((string) file_get_contents($path), 0, 100_000));

        $report = $this->verifier()->verify($name, false);

        self::assertFalse($report->ok());
        self::assertStringContainsString('100000 bytes', $report->problems[0]);
        self::assertSame(BackupStatus::Faulty, $report->status);
    }

    public function testADeletedPartOfASplitBackup(): void
    {
        $name = $this->fixture->backup(['partSize' => 1_048_576]);
        $storage = $this->fixture->storage();
        $parts = (new BackupCatalog($storage))->archiveParts($name);
        self::assertGreaterThan(1, count($parts));
        self::assertTrue($this->verifier()->verify($name)->ok(), 'The split backup is intact before.');
        unlink($parts[0]);

        $report = $this->verifier()->verify($name);

        self::assertFalse($report->ok());
        self::assertStringContainsString('is missing', $report->problems[0]);
        self::assertSame(BackupStatus::Faulty, $report->status);
        self::assertSame(BackupEntry::INCOMPLETE, (new BackupCatalog($storage))->entry($name)->condition);
    }

    public function testADeletedLastPartCannotEvenBeRebuilt(): void
    {
        $name = $this->fixture->backup(['partSize' => 1_048_576]);
        $storage = $this->fixture->storage();
        $parts = (new BackupCatalog($storage))->archiveParts($name);
        unlink((string) array_pop($parts));
        unlink($storage->sidecarPath($name));

        $entry = (new BackupCatalog($storage))->entry($name);

        self::assertSame(BackupEntry::UNKNOWN, $entry->condition);
        self::assertStringContainsString('gives no manifest', (string) $entry->problem);
        $report = $this->verifier()->verify($name);
        self::assertFalse($report->ok());
        self::assertNull($report->status);
    }

    public function testADeletedSidecarIsRebuiltAndNeedsAFullCheck(): void
    {
        $name = $this->fixture->backup();
        $storage = $this->fixture->storage();
        $original = $storage->readSidecar($name);
        unlink($storage->sidecarPath($name));

        $entry = (new BackupCatalog($storage))->entry($name);
        self::assertTrue($entry->rebuilt);
        self::assertSame('unverified', $entry->label());
        $rebuilt = $storage->readSidecar($name);
        self::assertSame($original->manifest->toArray(), $rebuilt->manifest->toArray());
        self::assertSame($original->parts[0]->sha256, $rebuilt->parts[0]->sha256);

        $quick = $this->verifier()->verify($name, false);
        self::assertTrue($quick->ok());
        self::assertSame(BackupStatus::Unverified, $quick->status, 'A quick check does not clear a backup that was never checked in full.');

        $full = $this->verifier()->verify($name, true);
        self::assertTrue($full->ok());
        self::assertSame(BackupStatus::Ok, $full->status);
        self::assertSame(BackupStatus::Ok, $storage->readSidecar($name)->status);
    }

    public function testDamageAfterARebuildIsFoundByTheFullCheck(): void
    {
        $name = $this->fixture->backup();
        $storage = $this->fixture->storage();
        $this->fixture->damage($storage->partPath($name, 1, false));
        unlink($storage->sidecarPath($name));   // the recorded SHA-256 is gone: only the archive itself can tell

        $quick = $this->verifier()->verify($name, false);
        self::assertTrue($quick->ok(), 'Nothing to compare with after the rebuild.');
        $full = $this->verifier()->verify($name, true);

        self::assertFalse($full->ok());
        self::assertSame(BackupStatus::Faulty, $full->status);
    }

    public function testAPartThatTheRecordDoesNotKnow(): void
    {
        $name = $this->fixture->backup();
        $storage = $this->fixture->storage();
        copy($storage->partPath($name, 1, false), $storage->directory() . '/' . $name->base() . '-p02.zip');

        $report = $this->verifier()->verify($name, false);

        self::assertFalse($report->ok());
        self::assertStringContainsString('not in the record', $report->problems[0]);
    }

    public function testAChangedManifestInTheSidecar(): void
    {
        $name = $this->fixture->backup();
        $storage = $this->fixture->storage();
        $path = $storage->sidecarPath($name);
        $data = json_decode((string) file_get_contents($path), true);
        $data['manifest']['counts']['files'] = 99;
        file_put_contents($path, json_encode($data));

        $report = $this->verifier()->verify($name, true);

        self::assertFalse($report->ok());
        $text = implode("\n", $report->problems);
        self::assertStringContainsString('differs from the one in the sidecar', $text);
        self::assertStringContainsString('manifest says 99 files', $text);
    }

    public function testAPartThatIsNotAnArchive(): void
    {
        $name = $this->fixture->backup();
        $storage = $this->fixture->storage();
        $path = $storage->partPath($name, 1, false);
        $garbage = random_bytes(max(1, (int) filesize($path)));
        file_put_contents($path, $garbage);
        // make the record agree with the garbage, so that only the archive structure can object
        $sidecar = $storage->readSidecar($name);
        $record = new \ThreadSafe\Backup\PartRecord($sidecar->parts[0]->file, strlen($garbage), hash('sha256', $garbage), $sidecar->parts[0]->entries);
        $storage->writeSidecar($name, new \ThreadSafe\Backup\Sidecar($sidecar->manifest, [$record], $sidecar->status));

        $report = $this->verifier()->verify($name, true);

        self::assertFalse($report->ok());
        self::assertStringContainsString('not a sound archive', implode("\n", $report->problems));
    }

    public function testCatalogListsNewestFirst(): void
    {
        $first = $this->fixture->backup();
        sleep(1);
        $second = $this->fixture->backup();
        $storage = $this->fixture->storage();

        $entries = (new BackupCatalog($storage))->entries('blog');

        self::assertSame([$second->base(), $first->base()], array_map(static fn (BackupEntry $e): string => $e->name->base(), $entries));
        self::assertSame(['ok', 'ok'], array_map(static fn (BackupEntry $e): string => $e->label(), $entries));
        self::assertSame($second->base(), (new BackupCatalog($storage))->find('blog', null)->name->base());
        self::assertSame($first->base(), (new BackupCatalog($storage))->find('blog', $first->base())->name->base());
    }

    public function testFindRefusesWhatIsNotThere(): void
    {
        $storage = $this->fixture->storage();
        $catalog = new BackupCatalog($storage);

        foreach ([[null, 'There is no backup of profile'], ['blog_20200101-000000_bbbbbbbb', 'There is no backup'], ['../x', 'There is no backup'], ['shop_20200101-000000_bbbbbbbb', 'There is no backup']] as [$backup, $message]) {
            try {
                $catalog->find('blog', $backup);
                self::fail('Found ' . var_export($backup, true));
            } catch (\ThreadSafe\Storage\StorageException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }
}
