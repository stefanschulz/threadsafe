<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Backup;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Archive\ZipReader;
use ThreadSafe\Backup\BackupName;
use ThreadSafe\Backup\BackupRun;
use ThreadSafe\Backup\BackupState;
use ThreadSafe\Backup\Manifest;
use ThreadSafe\Crypto\AgeDecryptStream;
use ThreadSafe\Crypto\AgeKeys;
use ThreadSafe\Crypto\AgeReader;
use ThreadSafe\Crypto\CryptoException;
use ThreadSafe\Crypto\Identity;
use ThreadSafe\Crypto\PassphraseIdentity;
use ThreadSafe\Crypto\X25519Identity;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Engine\Executor;
use ThreadSafe\Engine\Run;
use ThreadSafe\Engine\RunStatus;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Tests\Support\BackupFixture;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * An encrypted backup: each part sealed as soon as it is closed, at most one plain part on the disk, a sidecar file that says nothing
 * about the site, and parts that open with the key (or the passphrase) and with nothing else.
 */
final class EncryptedBackupTest extends TestCase
{
    use TempDirectory;

    private BackupFixture $fixture;

    /** @var array{secret: string, public: string} */
    private array $pair;

    protected function setUp(): void
    {
        $this->fixture = new BackupFixture($this->tempDirectory());
        $this->pair = AgeKeys::generate();
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
        gc_collect_cycles();
        gc_mem_caches();
    }

    private function encryptFor(string ...$recipients): void
    {
        $profile = $this->fixture->profiles->load('blog');
        $this->fixture->profiles->save($profile->with(['encryption' => ['recipients' => array_values($recipients)]]));
    }

    private function executor(?\Closure $budgets = null): Executor
    {
        return new Executor($this->fixture->runs, new BackupRun($this->fixture->profiles, PluginRegistry::load(), $this->fixture->data), $budgets ?? static fn (): Budget => Budget::unlimited());
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function backup(array $parameters = [], ?\Closure $budgets = null): Run
    {
        $executor = $this->executor($budgets);
        $run = $executor->execute($executor->start(['profile' => 'blog', ...$parameters])->id);
        self::assertSame(RunStatus::Finished, $run->status);

        return $run;
    }

    /** @return list<string> the files of the storage that belong to the backup, by name */
    private function filesOf(string $base): array
    {
        $names = array_map('basename', $this->fixture->storage()->files(BackupName::parse($base) ?? throw new \LogicException()));
        sort($names);

        return $names;
    }

    /** @param list<Identity> $identities */
    private function zipOf(string $sealed, array $identities): ZipReader
    {
        return ZipReader::open(AgeDecryptStream::url($this->fixture->storageDirectory . '/' . $sealed, $identities));
    }

    public function testEveryPartIsSealedAndTheStorageHoldsNothingInTheClear(): void
    {
        $this->encryptFor($this->pair['public']);

        $run = $this->backup();
        $result = $run->state['result'];
        $base = $result['backup'];

        self::assertSame([$base . '.json', $base . '.zip.age'], $this->filesOf($base));
        self::assertSame([$base . '.zip.age'], $result['parts']);
        self::assertTrue($result['encrypted']);
        self::assertSame([], glob($this->fixture->storageDirectory . '/*.zip') ?: [], 'no plain part');
        self::assertSame([], glob($this->fixture->storageDirectory . '/.work-*') ?: [], 'the work directory is gone');
        if (DIRECTORY_SEPARATOR === '/') {
            self::assertSame(0600, fileperms($this->fixture->storageDirectory . '/' . $base . '.zip.age') & 0777);
        }

        // the file is an age file that the key opens and that holds the ZIP part with the manifest
        $zip = $this->zipOf($base . '.zip.age', [new X25519Identity($this->pair['secret'])]);
        self::assertTrue($zip->verify()->ok());
        self::assertGreaterThan(3, $zip->count());
        $manifest = Manifest::fromJson($zip->contents($zip->find(Manifest::ENTRY) ?? throw new \LogicException(), 4 * 1048576));
        self::assertSame([['file' => $base . '.zip.age', 'entries' => $zip->count()]], $manifest->parts, 'the manifest names the sealed part');
        self::assertSame('blog', $manifest->profileId);
    }

    public function testTheSidecarFileSaysNothingAboutTheSite(): void
    {
        $this->encryptFor($this->pair['public']);
        $profile = $this->fixture->profiles->load('blog');
        $this->fixture->profiles->save($profile->with(['name' => 'Kunde X (live)', 'excludePaths' => ['var/geheim-alt']]));

        $base = $this->backup()->state['result']['backup'];
        $text = (string) file_get_contents($this->fixture->storageDirectory . '/' . $base . '.json');
        $sidecar = $this->fixture->storage()->readSidecar(BackupName::parse($base) ?? throw new \LogicException());

        self::assertStringNotContainsString('Kunde X', $text);
        self::assertStringNotContainsString('geheim-alt', $text);
        self::assertStringNotContainsString($this->fixture->site, $text, 'no path');
        self::assertSame([], $sidecar->manifest->filters['excludePaths']);
        self::assertSame([], $sidecar->manifest->database);
        self::assertSame([], $sidecar->manifest->extras);
        self::assertSame('blog', $sidecar->manifest->profileName, 'the id instead of the name');
        self::assertNotNull($sidecar->encryption);
        self::assertSame('age', $sidecar->encryption['format']);
        self::assertFalse($sidecar->encryption['passphrase']);
        self::assertSame([substr($this->pair['public'], 0, 12) . '...' . substr($this->pair['public'], -6)], $sidecar->encryption['recipients']);
        self::assertStringNotContainsString($this->pair['public'], $text, 'only the short form of the key');
        self::assertGreaterThan(0, $sidecar->manifest->counts['files'], 'but the counts are there');
        self::assertSame($sidecar->parts[0]->sha256, hash_file('sha256', $this->fixture->storageDirectory . '/' . $base . '.zip.age'), 'the sum of the sealed file');
        self::assertSame($sidecar->parts[0]->size, filesize($this->fixture->storageDirectory . '/' . $base . '.zip.age'));
    }

    public function testTheManifestInsideMatchesTheMinimalOneInTheSidecarFile(): void
    {
        $this->encryptFor($this->pair['public']);
        $base = $this->backup()->state['result']['backup'];
        $sidecar = $this->fixture->storage()->readSidecar(BackupName::parse($base) ?? throw new \LogicException());
        $zip = $this->zipOf($base . '.zip.age', [new X25519Identity($this->pair['secret'])]);

        $full = Manifest::fromJson($zip->contents($zip->find(Manifest::ENTRY) ?? throw new \LogicException(), 4 * 1048576));

        self::assertSame($sidecar->manifest->toArray(), $full->minimal()->toArray());
        self::assertSame($full->counts, $sidecar->manifest->counts);
    }

    public function testASplitBackupHasASealedPartForEveryPartAndOnlyOnePlainPartAtATime(): void
    {
        $this->encryptFor($this->pair['public']);
        $executor = $this->executor(static fn (): Budget => new Budget(0.0));
        $run = $executor->start(['profile' => 'blog', 'partSize' => 1048576]);
        $plainSeen = 0;
        $most = 0;
        $ticks = 0;
        do {
            $run = $executor->execute($run->id, 1);
            ++$ticks;
            self::assertSame([], glob($this->fixture->storageDirectory . '/*.zip') ?: [], 'a plain part never lies in the storage');
            $plain = glob($this->fixture->storageDirectory . '/.work-*/parts/*.zip') ?: [];
            $most = max($most, count($plain));
            $plainSeen += count($plain);
        } while ($run->status === RunStatus::Running && $ticks < 2000);

        self::assertSame(RunStatus::Finished, $run->status);
        self::assertLessThanOrEqual(1, $most, 'at most one plain part at a time');
        $parts = $run->state['result']['parts'];
        self::assertGreaterThan(1, count($parts), 'the site is bigger than a part');
        foreach ($parts as $index => $part) {
            self::assertStringEndsWith(sprintf('-p%02d.zip.age', $index + 1), $part);
            $zip = $this->zipOf($part, [new X25519Identity($this->pair['secret'])]);
            self::assertTrue($zip->verify()->ok(), $part);
        }
        self::assertGreaterThan(5, $ticks, 'it took many ticks');
        self::assertSame($run->state['result']['parts'], array_map(static fn ($p): string => $p->file, $this->fixture->storage()->readSidecar(BackupName::parse($run->state['result']['backup']) ?? throw new \LogicException())->parts));
    }

    public function testARunThatIsContinuedByNewProcessesMakesAGoodBackup(): void
    {
        $this->encryptFor($this->pair['public']);
        $first = $this->executor(static fn (): Budget => new Budget(0.0));
        $run = $first->start(['profile' => 'blog', 'partSize' => 1048576]);
        $ticks = 0;
        while ($run->status === RunStatus::Running && $ticks < 3000) {
            // every tick is a new "process": a new task object and a new executor, only the saved state carries over
            $run = $this->executor(static fn (): Budget => new Budget(0.0))->execute($run->id, 1);
            ++$ticks;
        }

        self::assertSame(RunStatus::Finished, $run->status);
        $key = [new X25519Identity($this->pair['secret'])];
        foreach ($run->state['result']['parts'] as $part) {
            self::assertTrue($this->zipOf($part, $key)->verify()->ok(), $part);
        }
    }

    public function testSeveralRecipientsAndOnlyTheirKeysOpenTheBackup(): void
    {
        $other = AgeKeys::generate();
        $stranger = AgeKeys::generate();
        $this->encryptFor($this->pair['public'], $other['public']);
        $part = $this->backup()->state['result']['parts'][0];

        self::assertTrue($this->zipOf($part, [new X25519Identity($this->pair['secret'])])->verify()->ok());
        self::assertTrue($this->zipOf($part, [new X25519Identity($other['secret'])])->verify()->ok());
        $handle = fopen($this->fixture->storageDirectory . '/' . $part, 'rb');
        self::assertNotFalse($handle);
        $this->expectException(CryptoException::class);
        AgeReader::open($handle, [new X25519Identity($stranger['secret'])]);
    }

    public function testAPassphraseMakesABackupForThePassphraseOnly(): void
    {
        $this->encryptFor($this->pair['public']);   // the profile has keys; the run has a passphrase, and then no key opens it
        $passphrase = 'kr7m-xq3d-hp9t-w4nc-2vfe';

        $executor = $this->executor();
        $run = $executor->start(['profile' => 'blog', 'passphrase' => $passphrase]);
        self::assertSame($passphrase, $run->state['passphrase'], 'it is in the file of the run while the run lasts');
        $run = $executor->execute($run->id);
        $part = $run->state['result']['parts'][0];

        self::assertSame('', $run->state['passphrase'], 'and gone from the state of the finished run');
        self::assertNull($run->state['seal']);
        $zip = $this->zipOf($part, [new PassphraseIdentity($passphrase, 1 << 40)]);
        self::assertTrue($zip->verify()->ok());
        $sidecar = $this->fixture->storage()->readSidecar(BackupName::parse($run->state['result']['backup']) ?? throw new \LogicException());
        self::assertNotNull($sidecar->encryption);
        self::assertTrue($sidecar->encryption['passphrase']);
        self::assertSame([], $sidecar->encryption['recipients'], 'no keys: it is for the passphrase only');
        self::assertStringNotContainsString($passphrase, (string) file_get_contents($this->fixture->storageDirectory . '/' . $run->state['result']['backup'] . '.json'));
        try {
            ZipReader::open(AgeDecryptStream::url($this->fixture->storageDirectory . '/' . $part, [new X25519Identity($this->pair['secret'])]));
            self::fail('the key of the profile must not open a backup that was made for a passphrase');
        } catch (\ThreadSafe\Archive\ZipException) {
            self::assertStringContainsString('does not open this file', (string) AgeDecryptStream::lastError());
        }
    }

    public function testAPassphraseThatIsTooShortIsRefusedBeforeAnythingIsDone(): void
    {
        $executor = $this->executor();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('short');
        $executor->start(['profile' => 'blog', 'passphrase' => 'hunter2']);
    }

    public function testAnAbandonedEncryptedRunLeavesNothingBehind(): void
    {
        $this->encryptFor($this->pair['public']);
        $executor = $this->executor(static fn (): Budget => new Budget(0.0));
        $run = $executor->start(['profile' => 'blog', 'partSize' => 1048576]);
        for ($i = 0; $i < 400 && glob($this->fixture->storageDirectory . '/*.age.part') === []; ++$i) {
            $run = $executor->execute($run->id, 1);
        }
        self::assertSame(RunStatus::Running, $run->status);
        self::assertNotSame([], glob($this->fixture->storageDirectory . '/*.age.part') ?: [], 'a part is half sealed');
        self::assertNotSame([], glob($this->fixture->storageDirectory . '/.work-*') ?: [], 'and a run is going');

        (new BackupRun($this->fixture->profiles, PluginRegistry::load(), $this->fixture->data))->abandon($run->state);

        self::assertSame([], array_values(array_diff(scandir($this->fixture->storageDirectory) ?: [], ['.', '..', '.htaccess', 'index.php', 'web.config'])), 'no sealed part, no .part file, no plain part, no work directory');
    }

    public function testABackupWithoutKeysIsExactlyWhatItWas(): void
    {
        $run = $this->backup();
        $base = $run->state['result']['backup'];

        self::assertSame([$base . '.json', $base . '.zip'], $this->filesOf($base));
        self::assertFalse($run->state['result']['encrypted']);
        self::assertNull($this->fixture->storage()->readSidecar(BackupName::parse($base) ?? throw new \LogicException())->encryption);
        self::assertSame(BackupState::DONE, $run->state['phase']);
    }

    public function testAMistypedKeyIsRefusedWhenItIsEnteredNotWhenTheBackupRuns(): void
    {
        $this->expectException(\ThreadSafe\Profile\ProfileException::class);
        $this->fixture->profiles->load('blog')->with(['encryption' => ['recipients' => [substr($this->pair['public'], 0, -1) . 'q']]]);
    }
}
