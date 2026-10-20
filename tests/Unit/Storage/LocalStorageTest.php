<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Storage;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Archive\ZipWriter;
use ThreadSafe\Backup\BackupName;
use ThreadSafe\Backup\BackupStatus;
use ThreadSafe\Backup\PartRecord;
use ThreadSafe\Backup\Sidecar;
use ThreadSafe\Storage\LocalPostproc;
use ThreadSafe\Storage\LocalStorage;
use ThreadSafe\Storage\SpaceCheck;
use ThreadSafe\Storage\StorageException;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Tests\Unit\Backup\ManifestTest;

final class LocalStorageTest extends TestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    private function storage(): LocalStorage
    {
        $storage = new LocalStorage($this->tempDirectory() . '/backups/shop');
        $storage->prepare();

        return $storage;
    }

    private function backupName(string $time = '2026-10-20 12:34:56', string $random = 'ab12cd34', string $profile = 'shop'): BackupName
    {
        return BackupName::create($profile, new DateTimeImmutable($time . ' UTC'), $random);
    }

    private function sidecar(BackupName $name, int $size = 10): Sidecar
    {
        return new Sidecar(ManifestTest::sample($name->base()), [new PartRecord($name->partFile(1, false), $size, str_repeat('a', 64), 1)], BackupStatus::Ok);
    }

    public function testPrepareCreatesTheDirectoryAndProtectionFiles(): void
    {
        $storage = $this->storage();
        $directory = $storage->directory();

        self::assertTrue($storage->isProtected());
        self::assertStringContainsString('Require all denied', (string) file_get_contents($directory . '/.htaccess'));
        self::assertStringContainsString('Deny from all', (string) file_get_contents($directory . '/.htaccess'));
        self::assertStringContainsString('403', (string) file_get_contents($directory . '/index.php'));
        self::assertStringContainsString('accessType="Deny"', (string) file_get_contents($directory . '/web.config'));
        self::assertNotFalse(simplexml_load_string((string) file_get_contents($directory . '/web.config')), 'web.config is well-formed XML.');
        if (DIRECTORY_SEPARATOR === '/') {
            self::assertSame('0700', substr(sprintf('%o', fileperms($directory)), -4));
            self::assertSame('0600', substr(sprintf('%o', fileperms($directory . '/.htaccess')), -4));
        }
    }

    public function testPrepareKeepsFilesThatExist(): void
    {
        $storage = $this->storage();
        file_put_contents($storage->directory() . '/.htaccess', 'my own rules');
        unlink($storage->directory() . '/index.php');

        self::assertFalse($storage->isProtected());
        $storage->prepare();

        self::assertTrue($storage->isProtected());
        self::assertSame('my own rules', file_get_contents($storage->directory() . '/.htaccess'));
        self::assertSame([], glob($storage->directory() . '/*.tmp') ?: [], 'No temporary file is left.');
    }

    public function testPrepareRefusesAFileOrAnUnwritableDirectory(): void
    {
        $file = $this->tempDirectory() . '/file';
        file_put_contents($file, 'x');

        $this->expectException(StorageException::class);

        (new LocalStorage($file . '/sub'))->prepare();
    }

    public function testPathsFollowTheName(): void
    {
        $storage = $this->storage();
        $name = $this->backupName();

        self::assertSame($storage->directory() . '/shop_20261020-123456_ab12cd34.zip', $storage->partPath($name, 1, false));
        self::assertSame($storage->directory() . '/shop_20261020-123456_ab12cd34-p02.zip', $storage->partPath($name, 2, true));
        self::assertSame($storage->directory() . '/shop_20261020-123456_ab12cd34.json', $storage->sidecarPath($name));
        self::assertMatchesRegularExpression('/^shop_\d{8}-\d{6}_[0-9a-f]{8}$/', $storage->newName('shop')->base());
    }

    public function testSidecarRoundTripAndMode(): void
    {
        $storage = $this->storage();
        $name = $this->backupName();
        $sidecar = $this->sidecar($name);

        $storage->writeSidecar($name, $sidecar);

        self::assertSame($sidecar->toArray(), $storage->readSidecar($name)->toArray());
        if (DIRECTORY_SEPARATOR === '/') {
            self::assertSame('0600', substr(sprintf('%o', fileperms($storage->sidecarPath($name))), -4));
        }
    }

    public function testSidecarProblemsAreStorageErrors(): void
    {
        $storage = $this->storage();
        $name = $this->backupName();

        foreach ([null, '{nope', '{"format":1}'] as $content) {
            if ($content !== null) {
                file_put_contents($storage->sidecarPath($name), $content);
            }
            try {
                $storage->readSidecar($name);
                self::fail('Read ' . var_export($content, true));
            } catch (StorageException $e) {
                self::assertStringContainsString($name->base(), $e->getMessage());
            }
        }
    }

    public function testNamesAreListedNewestFirstAndPerProfile(): void
    {
        $storage = $this->storage();
        $old = $this->backupName('2026-10-18 10:00:00', '11111111');
        $new = $this->backupName('2026-10-20 10:00:00', '22222222');
        $other = $this->backupName('2026-10-19 10:00:00', '33333333', 'blog');
        $split = $this->backupName('2026-10-19 11:00:00', '44444444');
        $storage->writeSidecar($old, $this->sidecar($old));
        $storage->writeSidecar($new, $this->sidecar($new));
        file_put_contents($storage->partPath($other, 1, false), 'zip');
        file_put_contents($storage->partPath($split, 1, true), 'zip');
        file_put_contents($storage->partPath($split, 2, true), 'zip');
        file_put_contents($storage->directory() . '/notes.txt', 'x');

        self::assertSame(
            ['shop_20261020-100000_22222222', 'shop_20261019-110000_44444444', 'blog_20261019-100000_33333333', 'shop_20261018-100000_11111111'],
            array_map(static fn (BackupName $n): string => $n->base(), $storage->names()),
            'Sidecars and archive parts both count; a split backup is listed once.',
        );
        self::assertSame(['blog_20261019-100000_33333333'], array_map(static fn (BackupName $n): string => $n->base(), $storage->names('blog')));
    }

    public function testFilesOfABackup(): void
    {
        $storage = $this->storage();
        $split = $this->backupName();
        $other = $this->backupName('2026-10-20 12:34:57', 'cd34ab12');
        foreach ([[$split, 2, true, ''], [$split, 1, true, ''], [$split, 3, true, '.part'], [$split, 3, true, '.cd'], [$other, 1, false, '']] as [$name, $index, $isSplit, $suffix]) {
            file_put_contents($storage->partPath($name, $index, $isSplit) . $suffix, 'x');
        }
        $storage->writeSidecar($split, $this->sidecar($split));
        $base = $storage->directory() . '/' . $split->base();

        self::assertSame(
            [$base . '-p01.zip', $base . '-p02.zip', $base . '-p03.zip.cd', $base . '-p03.zip.part', $base . '.json'],
            $storage->files($split),
        );
    }

    public function testRemoveBackupDeletesEverythingOfThatBackupOnly(): void
    {
        $storage = $this->storage();
        $name = $this->backupName();
        $other = $this->backupName('2026-10-20 12:34:57', 'cd34ab12');
        file_put_contents($storage->partPath($name, 1, false), 'x');
        file_put_contents($storage->partPath($name, 2, false) . '.part', 'x');
        $storage->writeSidecar($name, $this->sidecar($name));
        $work = $storage->workDirectory($name);
        mkdir($work . '/sub');
        file_put_contents($work . '/sub/0001_t.sql', 'x');
        file_put_contents($storage->partPath($other, 1, false), 'keep');

        $storage->removeBackup($name);

        self::assertSame([], $storage->files($name));
        self::assertDirectoryDoesNotExist($work);
        self::assertFileExists($storage->partPath($other, 1, false));
        self::assertTrue($storage->isProtected(), 'The protection files stay.');
    }

    public function testUnfinishedBackups(): void
    {
        $storage = $this->storage();
        $partial = $this->backupName('2026-10-20 10:00:00', '11111111');
        $working = $this->backupName('2026-10-20 11:00:00', '22222222');
        $done = $this->backupName('2026-10-20 12:00:00', '33333333');
        file_put_contents($storage->partPath($partial, 1, true) . '.part', 'x');
        $storage->workDirectory($working);
        file_put_contents($storage->partPath($done, 1, false), 'x');
        $storage->writeSidecar($done, $this->sidecar($done));
        file_put_contents($storage->partPath($done, 2, false) . '.cd', 'x');

        $names = array_map(static fn (BackupName $n): string => $n->base(), $storage->unfinished());
        sort($names);

        self::assertSame([$partial->base(), $working->base()], $names, 'A backup with a sidecar file is finished.');
    }

    public function testWorkDirectoryIsPrivate(): void
    {
        $storage = $this->storage();
        $work = $storage->workDirectory($this->backupName());

        self::assertDirectoryExists($work);
        self::assertSame($work, $storage->workDirectory($this->backupName()), 'Asking again is fine.');
        if (DIRECTORY_SEPARATOR === '/') {
            self::assertSame('0700', substr(sprintf('%o', fileperms($work)), -4));
        }
    }

    public function testLocalPostprocConfirmsParts(): void
    {
        $storage = $this->storage();
        $name = $this->backupName();
        $writer = ZipWriter::create($storage->partPath($name, 1, false));
        $writer->addString('x', 'y', 1_700_000_000);
        $info = $writer->close();
        $sidecar = new Sidecar(ManifestTest::sample($name->base()), [PartRecord::fromPartInfo($info)], BackupStatus::Ok);
        $postproc = new LocalPostproc();

        $result = $postproc->store($name, $sidecar, $storage);

        self::assertSame('local', $postproc->id());
        self::assertTrue($result->success);
        self::assertStringContainsString($storage->directory(), $result->message);
        self::assertSame('local', $result->toArray()['target']);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $result->toArray()['time']);

        file_put_contents($storage->partPath($name, 1, false), 'truncated');
        self::assertFalse($postproc->store($name, $sidecar, $storage)->success);
        unlink($storage->partPath($name, 1, false));
        self::assertFalse($postproc->store($name, $sidecar, $storage)->success);
    }

    public function testClosedArchiveIsOwnerOnlyFromTheStart(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('POSIX permissions');
        }
        $storage = $this->storage();
        $path = $storage->partPath($this->backupName(), 1, false);
        $writer = ZipWriter::create($path);
        self::assertSame('0600', substr(sprintf('%o', fileperms($path . '.part')), -4));
        self::assertSame('0600', substr(sprintf('%o', fileperms($path . '.cd')), -4));
        $writer->addString('x', 'y', 1_700_000_000);
        $writer->close();

        self::assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));
    }

    public function testSpaceRequirement(): void
    {
        self::assertSame(1200 + 500, SpaceCheck::required(1000, 99999, 500), 'Last backup times 1.2, plus the dump files.');
        self::assertSame(2000 + 1500 + 1000, SpaceCheck::required(null, 2000, 1000), 'Files plus 1.5 times the database, plus the dump files.');
        self::assertSame(0, SpaceCheck::required(0, 0, 0));
    }

    public function testSpaceCheck(): void
    {
        $directory = $this->tempDirectory();
        $check = new SpaceCheck();

        $small = $check->check($directory . '/not/yet/there', 1024);
        self::assertTrue($small->allowed);
        self::assertNull($small->warning);
        self::assertIsInt($small->free);

        $huge = $check->check($directory, PHP_INT_MAX);
        self::assertFalse($huge->allowed);
        self::assertStringContainsString('--force', $huge->refusal());

        $forced = $check->check($directory, PHP_INT_MAX, force: true);
        self::assertTrue($forced->allowed);
        self::assertStringContainsString('--force', (string) $forced->warning);
    }

    public function testSizeFormat(): void
    {
        self::assertSame('2 KB', SpaceCheck::format(2048));
        self::assertSame('5 MB', SpaceCheck::format(5 * 1048576));
        self::assertSame('1.5 GB', SpaceCheck::format((int) (1.5 * 1073741824)));
    }
}
