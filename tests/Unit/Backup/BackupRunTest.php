<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Backup;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Archive\ZipReader;
use ThreadSafe\Backup\BackupName;
use ThreadSafe\Backup\BackupRun;
use ThreadSafe\Backup\BackupState;
use ThreadSafe\Backup\BackupStatus;
use ThreadSafe\Backup\Manifest;
use ThreadSafe\Core\Exception\GuardFailed;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Engine\Executor;
use ThreadSafe\Engine\Run;
use ThreadSafe\Engine\RunStatus;
use ThreadSafe\Engine\RunStore;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Profile\SiteProfile;
use ThreadSafe\Storage\LocalStorage;
use ThreadSafe\Storage\SpaceCheck;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Tests\Support\UnzipOracle;

/**
 * A whole backup of a site without a database ("any website" type): scan, pack, finish - in one tick, tick by tick, split into
 * parts and resumed after a process died. The archive is checked by the own reader and, where it exists, by `unzip`.
 */
final class BackupRunTest extends TestCase
{
    use TempDirectory;

    private string $site;

    private string $storage;

    private ProfileStore $profiles;

    private RunStore $runs;

    protected function setUp(): void
    {
        $base = $this->tempDirectory();
        $this->site = $base . '/site';
        $this->storage = $base . '/storage';
        $this->profiles = new ProfileStore($base . '/profiles');
        $this->runs = new RunStore($base . '/runs');
        $this->makeSite();
        $this->profiles->save(SiteProfile::fromArray(['id' => 'blog', 'name' => 'Blog', 'type' => 'generic', 'path' => $this->site, 'storage' => $this->storage]));
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    private function makeSite(): void
    {
        mkdir($this->site . '/assets/img', 0777, true);
        mkdir($this->site . '/empty', 0777, true);
        mkdir($this->site . '/cache', 0777, true);
        file_put_contents($this->site . '/index.php', "<?php echo 'hello';\n");
        file_put_contents($this->site . '/zero.txt', '');
        file_put_contents($this->site . '/ünï cödé.txt', 'unicode name');
        file_put_contents($this->site . '/assets/big.bin', random_bytes(700_000));          // spans three blocks, incompressible
        file_put_contents($this->site . '/assets/exact.bin', str_repeat('x', 262144 * 2));   // an exact multiple of the block size
        file_put_contents($this->site . '/assets/img/photo.jpg', random_bytes(5000));         // stored, not compressed
        file_put_contents($this->site . '/cache/tmp.txt', 'left out by a filter');
        if (DIRECTORY_SEPARATOR === '/') {
            @symlink($this->site . '/index.php', $this->site . '/link.php');
        }
        $this->profiles = new ProfileStore(dirname($this->site) . '/profiles');
    }

    private function task(?int $scanBatch = null, ?SpaceCheck $space = null): BackupRun
    {
        return new BackupRun($this->profiles, PluginRegistry::load(), dirname($this->site) . '/data', null, $scanBatch ?? 500, $space);
    }

    /** @return array<string, mixed> */
    private function finishedResult(Run $run): array
    {
        self::assertSame(RunStatus::Finished, $run->status);
        $result = $run->state['result'] ?? null;
        self::assertIsArray($result, 'A finished run has a result.');

        return $result;
    }

    /**
     * @return array<string, array{int, int}> entry name => [CRC32, size] of every entry that is not metadata
     */
    private function contents(string $archive): array
    {
        $reader = ZipReader::open($archive);
        $entries = [];
        foreach ($reader->entries() as $entry) {
            if ($entry->name !== 'threadsafe-part.json' && $entry->name !== 'threadsafe.json') {
                $entries[$entry->name] = [$entry->crc32, $entry->size];
            }
        }
        ksort($entries);

        return $entries;
    }

    public function testBackupInOneTick(): void
    {
        $task = $this->task();
        $executor = new Executor($this->runs, $task, static fn (): Budget => Budget::unlimited());
        $run = $executor->execute($executor->start(['profile' => 'blog', 'snapshot' => true])->id);

        $result = $this->finishedResult($run);
        self::assertSame(2, $run->ticks, 'The first tick only names the backup and prepares its work directory; an unlimited budget does everything else in one tick.');
        self::assertSame('none', $result['consistency']);
        $hasLink = is_link($this->site . '/link.php');
        self::assertSame($hasLink ? BackupStatus::OkWithWarnings->value : BackupStatus::Ok->value, $result['status'], 'The symbolic link is a warning.');

        $storage = new LocalStorage($this->storage);
        $name = BackupName::parse((string) $result['backup']);
        self::assertNotNull($name);
        self::assertSame([$name->partFile(1, false)], $result['parts']);
        $archive = $this->storage . '/' . $result['parts'][0];
        if (DIRECTORY_SEPARATOR === '/') {
            self::assertSame('0600', substr(sprintf('%o', fileperms($archive)), -4));
        }

        // the archive is a sound ZIP file with the files and the metadata in the expected places
        $reader = ZipReader::open($archive);
        self::assertTrue($reader->verify()->ok(), implode("\n", $reader->verify()->problems));
        $names = array_map(static fn ($e): string => $e->name, iterator_to_array($reader->entries(), false));
        self::assertSame('threadsafe-part.json', $names[0]);
        self::assertSame('threadsafe.json', $names[array_key_last($names)]);
        self::assertContains('files/index.php', $names);
        self::assertContains('files/ünï cödé.txt', $names);
        self::assertContains('files/empty/', $names, 'An empty directory is an entry of its own.');
        self::assertContains('files/zero.txt', $names);
        self::assertNotContains('files/link.php', $names, 'Symbolic links are skipped.');
        $photo = $reader->find('files/assets/img/photo.jpg');
        self::assertSame(0, $photo?->method, 'A JPEG is stored as it is.');
        self::assertSame(8, $reader->find('files/assets/exact.bin')?->method);
        self::assertSame(md5_file($this->site . '/assets/big.bin'), md5($reader->contents($reader->find('files/assets/big.bin') ?? throw new \LogicException('missing'), 2_000_000)));

        // the manifest inside and the sidecar agree, and the sidecar knows the part
        $manifest = Manifest::fromArchive($archive);
        $sidecar = $storage->readSidecar($name);
        self::assertSame($manifest->toArray(), $sidecar->manifest->toArray());
        self::assertSame('Other website', $manifest->application['name']);
        self::assertSame(['blog'], [$manifest->profileId]);
        self::assertSame(hash_file('sha256', $archive), $sidecar->parts[0]->sha256);
        self::assertSame(filesize($archive), $sidecar->parts[0]->size);
        self::assertSame(count($names), $sidecar->parts[0]->entries);
        self::assertSame(7, $manifest->counts['files'], 'index.php, zero.txt, the unicode name, big.bin, exact.bin, photo.jpg and cache/tmp.txt');
        self::assertSame(4, $manifest->counts['directories'], 'assets, assets/img, empty and cache');
        self::assertSame($hasLink, str_contains(implode("\n", $manifest->warnings), 'symbolic link'));
        self::assertSame([], glob($this->storage . '/.work-*') ?: [], 'The work directory is gone.');
        self::assertSame([], glob($this->storage . '/*.part') ?: []);

        if (UnzipOracle::available()) {
            [$ok, $output] = UnzipOracle::test($archive);
            self::assertTrue($ok, $output);
        }
    }

    public function testFiltersOfTheProfileKeepFilesOut(): void
    {
        $this->profiles->save(SiteProfile::fromArray(['id' => 'blog', 'type' => 'generic', 'path' => $this->site, 'storage' => $this->storage, 'excludePaths' => ['cache', '*.jpg']]));
        $executor = new Executor($this->runs, $this->task(), static fn (): Budget => Budget::unlimited());
        $result = $this->finishedResult($executor->execute($executor->start(['profile' => 'blog'])->id));

        $names = array_keys($this->contents($this->storage . '/' . $result['parts'][0]));
        self::assertNotContains('files/cache/', $names);
        self::assertNotContains('files/cache/tmp.txt', $names);
        self::assertNotContains('files/assets/img/photo.jpg', $names);
        self::assertContains('files/assets/img/', $names);
        self::assertContains('files/index.php', $names);
    }

    public function testStorageInsideTheSiteIsNotPackedIntoItself(): void
    {
        $inside = $this->site . '/var/backups';
        $this->profiles->save(SiteProfile::fromArray(['id' => 'blog', 'type' => 'generic', 'path' => $this->site, 'storage' => $inside]));
        $executor = new Executor($this->runs, $this->task(), static fn (): Budget => Budget::unlimited());
        $first = $this->finishedResult($executor->execute($executor->start(['profile' => 'blog'])->id));
        $second = $this->finishedResult($executor->execute($executor->start(['profile' => 'blog'])->id));

        foreach ([$first, $second] as $result) {
            foreach (array_keys($this->contents($inside . '/' . $result['parts'][0])) as $name) {
                self::assertStringNotContainsString('var/backups', $name, $name);
            }
        }
        self::assertContains('files/var/', array_keys($this->contents($inside . '/' . $second['parts'][0])), 'The directory around the storage is there, the storage is not.');
        self::assertTrue((new LocalStorage($inside))->isProtected());
    }

    public function testTickByTickGivesTheSameArchiveContents(): void
    {
        $whole = new Executor($this->runs, $this->task(), static fn (): Budget => Budget::unlimited());
        $reference = $this->finishedResult($whole->execute($whole->start(['profile' => 'blog'])->id));

        $ticked = new Executor($this->runs, $this->task(scanBatch: 2), static fn (): Budget => new Budget(0.0));
        $run = $ticked->execute($ticked->start(['profile' => 'blog'])->id);
        $result = $this->finishedResult($run);

        self::assertGreaterThan(20, $run->ticks, 'Every scan batch, every block and every entry is a tick of its own.');
        self::assertSame($this->contents($this->storage . '/' . $reference['parts'][0]), $this->contents($this->storage . '/' . $result['parts'][0]));
        self::assertTrue(ZipReader::open($this->storage . '/' . $result['parts'][0])->verify()->ok());
        self::assertSame($reference['files'], $result['files']);
        self::assertSame($reference['directories'], $result['directories']);
    }

    public function testASplitBackupIsAChainOfSelfContainedParts(): void
    {
        file_put_contents($this->site . '/more1.bin', random_bytes(600_000));
        file_put_contents($this->site . '/more2.bin', random_bytes(600_000));
        $executor = new Executor($this->runs, $this->task(), static fn (): Budget => Budget::unlimited());
        $result = $this->finishedResult($executor->execute($executor->start(['profile' => 'blog', 'partSize' => 1_048_576])->id));

        $parts = $result['parts'];
        self::assertGreaterThan(2, count($parts));
        self::assertMatchesRegularExpression('/-p01\.zip$/', $parts[0]);
        self::assertMatchesRegularExpression('/-p0' . count($parts) . '\.zip$/', $parts[array_key_last($parts)]);

        $all = [];
        foreach ($parts as $index => $part) {
            $reader = ZipReader::open($this->storage . '/' . $part);
            self::assertTrue($reader->verify()->ok(), $part);
            $first = iterator_to_array($reader->entries(), false)[0];
            self::assertSame('threadsafe-part.json', $first->name, 'Every part carries its marker.');
            self::assertSame($index === array_key_last($parts), $reader->find('threadsafe.json') !== null, 'Only the last part holds the manifest.');
            $all = [...$all, ...$this->contents($this->storage . '/' . $part)];
            if (UnzipOracle::available()) {
                [$ok, $output] = UnzipOracle::test($this->storage . '/' . $part);
                self::assertTrue($ok, $part . ': ' . $output);
            }
        }
        $single = new Executor($this->runs, $this->task(), static fn (): Budget => Budget::unlimited());
        $reference = $this->finishedResult($single->execute($single->start(['profile' => 'blog'])->id));
        self::assertSame($this->contents($this->storage . '/' . $reference['parts'][0]), $all, 'Together the parts hold what the single archive holds.');

        $manifest = Manifest::fromArchive($this->storage . '/' . $parts[array_key_last($parts)]);
        self::assertSame($parts, array_column($manifest->parts, 'file'));
        $sidecar = (new LocalStorage($this->storage))->readSidecar(BackupName::parse((string) $result['backup']) ?? throw new \LogicException('name'));
        foreach ($sidecar->parts as $record) {
            self::assertSame(hash_file('sha256', $this->storage . '/' . $record->file), $record->sha256, $record->file);
        }
    }

    public function testARunThatDiedIsContinuedFromItsSavedState(): void
    {
        $first = new Executor($this->runs, $this->task(scanBatch: 2), static fn (): Budget => new Budget(0.0));
        $run = $first->start(['profile' => 'blog']);
        $first->execute($run->id, 12);
        $run = $this->runs->load($run->id);
        self::assertSame(RunStatus::Running, $run->status);

        // the process died after saving a state: junk is left behind in whatever it was writing
        foreach (glob($this->storage . '/*.part') ?: [] as $part) {
            file_put_contents($part, 'JUNK AFTER THE SAVED STATE', FILE_APPEND);
        }
        foreach (glob($this->storage . '/*.cd') ?: [] as $directory) {
            file_put_contents($directory, 'JUNK', FILE_APPEND);
        }
        $list = glob($this->storage . '/.work-*/files.jsonl') ?: [];
        foreach ($list as $file) {
            file_put_contents($file, '["junk', FILE_APPEND);
        }

        $second = new Executor($this->runs, $this->task(scanBatch: 2), static fn (): Budget => new Budget(0.0));
        $done = $second->resume($run->id);
        $result = $this->finishedResult($done);

        $whole = new Executor($this->runs, $this->task(), static fn (): Budget => Budget::unlimited());
        $reference = $this->finishedResult($whole->execute($whole->start(['profile' => 'blog'])->id));
        self::assertSame($this->contents($this->storage . '/' . $reference['parts'][0]), $this->contents($this->storage . '/' . $result['parts'][0]));
        self::assertTrue(ZipReader::open($this->storage . '/' . $result['parts'][0])->verify()->ok());
    }

    public function testAFileThatVanishesBeforeItIsPackedIsAWarningNotAFailure(): void
    {
        $executor = new Executor($this->runs, $this->task(), static fn (): Budget => new Budget(0.0));
        $run = $executor->start(['profile' => 'blog']);
        // run until the site is listed, then take a file away
        for ($i = 0; $i < 50; ++$i) {
            $run = $executor->execute($run->id, 1);
            if (($run->state['phase'] ?? '') === BackupState::PACK) {
                break;
            }
        }
        self::assertSame(BackupState::PACK, $run->state['phase']);
        unlink($this->site . '/index.php');
        $result = $this->finishedResult($executor->execute($run->id));

        $manifest = Manifest::fromArchive($this->storage . '/' . $result['parts'][0]);
        self::assertStringContainsString('/index.php', implode("\n", $manifest->warnings));
        self::assertSame(BackupStatus::OkWithWarnings->value, $result['status']);
    }

    public function testAProfileThatChangesDuringTheRunStopsTheRun(): void
    {
        $executor = new Executor($this->runs, $this->task(), static fn (): Budget => new Budget(0.0));
        $run = $executor->execute($executor->start(['profile' => 'blog'])->id, 1);

        $this->profiles->save(SiteProfile::fromArray(['id' => 'blog', 'type' => 'generic', 'path' => $this->site, 'storage' => $this->storage, 'note' => 'changed']));

        $this->expectException(GuardFailed::class);
        $this->expectExceptionMessage('changed while the backup was running');

        $executor->execute($run->id);
    }

    public function testChangingTheScheduleOrTheRetentionDuringTheRunDoesNotStopIt(): void
    {
        $executor = new Executor($this->runs, $this->task(), static fn (): Budget => new Budget(0.0));
        $run = $executor->execute($executor->start(['profile' => 'blog'])->id, 1);

        $this->profiles->save($this->profiles->load('blog')->with(['schedule' => ['cron' => '0 3 * * *'], 'retention' => ['keep' => 3]]));

        $finished = (new Executor($this->runs, $this->task(), static fn (): Budget => Budget::unlimited()))->execute($run->id);
        self::assertSame(RunStatus::Finished, $finished->status, 'when a backup is made, and what is kept afterwards, is none of the run\'s business');
    }

    public function testAbandonRemovesWhatTheRunLeftBehind(): void
    {
        $task = $this->task();
        $executor = new Executor($this->runs, $task, static fn (): Budget => new Budget(0.0));
        $run = $executor->execute($executor->start(['profile' => 'blog'])->id, 15);
        self::assertNotSame([], glob($this->storage . '/.work-*') ?: [], 'The run has made a work directory.');

        $task->abandon($this->runs->load($run->id)->state);

        self::assertSame([], glob($this->storage . '/.work-*') ?: []);
        self::assertSame([], glob($this->storage . '/*.part') ?: []);
        self::assertSame([], glob($this->storage . '/*.zip') ?: []);
    }

    public function testBadParametersAreRefused(): void
    {
        foreach ([[], ['profile' => '../x'], ['profile' => 'blog', 'partSize' => 1000], ['profile' => 'blog', 'compression' => 10]] as $parameters) {
            try {
                $this->task()->initialState($parameters);
                self::fail('Accepted ' . json_encode($parameters));
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testAMissingProfileIsRefused(): void
    {
        $executor = new Executor($this->runs, $this->task(), static fn (): Budget => Budget::unlimited());
        $run = $executor->start(['profile' => 'nope']);

        $this->expectException(GuardFailed::class);
        $this->expectExceptionMessage('There is no profile "nope"');

        $executor->execute($run->id);
    }
    public function testTooLittleFreeSpaceRefusesTheBackupUnlessForced(): void
    {
        $small = new SpaceCheck(static fn (): int => 1000);
        $executor = new Executor($this->runs, $this->task(space: $small), static fn (): Budget => Budget::unlimited());

        try {
            $executor->execute($executor->start(['profile' => 'blog'])->id);
            self::fail('The backup was not refused.');
        } catch (GuardFailed $e) {
            self::assertStringContainsString('Not enough free space', $e->getMessage());
            self::assertStringContainsString('--force', $e->getMessage());
        }

        $forced = $this->finishedResult($executor->execute($executor->start(['profile' => 'blog', 'force' => true])->id));
        $manifest = Manifest::fromArchive($this->storage . '/' . $forced['parts'][0]);
        self::assertStringContainsString('going on because of --force', implode("\n", $manifest->warnings));
        self::assertSame(BackupStatus::OkWithWarnings->value, $forced['status']);
    }

    public function testAFileThatChangesWhileTheRunLastsIsAWarning(): void
    {
        $executor = new Executor($this->runs, $this->task(), static fn (): Budget => new Budget(0.0));
        $run = $executor->start(['profile' => 'blog']);
        for ($i = 0; $i < 50; ++$i) {
            $run = $executor->execute($run->id, 1);
            if (($run->state['phase'] ?? '') === BackupState::PACK) {
                break;
            }
        }
        file_put_contents($this->site . '/index.php', "<?php echo 'much longer than it was when the site was scanned';\n");
        $result = $this->finishedResult($executor->execute($run->id));

        $manifest = Manifest::fromArchive($this->storage . '/' . $result['parts'][0]);
        self::assertStringContainsString('The file changed while it was backed up', implode("\n", $manifest->warnings));
        self::assertStringContainsString('/index.php', implode("\n", $manifest->warnings));
        self::assertSame(BackupStatus::OkWithWarnings->value, $result['status']);
    }
}
