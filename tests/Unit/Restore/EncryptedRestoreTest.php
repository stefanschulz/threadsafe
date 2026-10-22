<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Restore;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Cli\Application;
use ThreadSafe\Cli\BufferedOutput;
use ThreadSafe\Cli\RestoreCommand;
use ThreadSafe\Crypto\AgeKeys;
use ThreadSafe\Crypto\CryptoException;
use ThreadSafe\Crypto\Passphrases;
use ThreadSafe\Crypto\Unlock;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Engine\Executor;
use ThreadSafe\Engine\RunStatus;
use ThreadSafe\Engine\RunStore;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Restore\BackupSource;
use ThreadSafe\Restore\RestoreException;
use ThreadSafe\Restore\RestoreRun;
use ThreadSafe\Storage\BackupCatalog;
use ThreadSafe\Tests\Support\BackupFixture;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * Restoring encrypted backups (docs/PHASE5.md 3.5, "Restoring"): the source finds the parts and the manifest through the decrypting
 * stream, the extraction reads the parts through it in ticks, the key lives in the state only until the files are unpacked, and the
 * command takes a key file or a passphrase file.
 */
final class EncryptedRestoreTest extends TestCase
{
    use TempDirectory;

    private string $base;

    private BackupFixture $fixture;

    /** @var array{secret: string, public: string} */
    private array $pair;

    protected function setUp(): void
    {
        $this->base = $this->tempDirectory();
        $this->fixture = new BackupFixture($this->base . '/fixture');
        $this->pair = AgeKeys::generate();
    }

    protected function tearDown(): void
    {
        putenv('THREADSAFE_PASSPHRASE');
        $this->cleanUpTempDirectories();
        gc_collect_cycles();
        gc_mem_caches();
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return list<string> the sealed parts
     */
    private function sealedBackup(array $parameters = []): array
    {
        $this->fixture->profiles->save($this->fixture->profiles->load('blog')->with(['encryption' => ['recipients' => [$this->pair['public']]]]));
        $parts = (new BackupCatalog($this->fixture->storage()))->archiveParts($this->fixture->backup($parameters));
        self::assertStringEndsWith('.zip.age', $parts[0]);

        return $parts;
    }

    private function keyUnlock(): Unlock
    {
        return Unlock::keys([$this->pair['secret']]);
    }

    private function keyFile(?string $secret = null): string
    {
        $pair = $secret === null ? $this->pair : ['secret' => $secret, 'public' => AgeKeys::publicOf($secret)];
        $file = $this->base . '/' . bin2hex(random_bytes(4)) . '-key.txt';
        file_put_contents($file, AgeKeys::keyFile($pair['secret'], $pair['public']));

        return $file;
    }

    /** @return array<string, string> */
    private function tree(string $directory): array
    {
        $result = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $path => $info) {
            $result[substr((string) $path, strlen($directory) + 1)] = $info->isDir() ? 'dir' : (string) md5_file((string) $path);
        }
        ksort($result);

        return $result;
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string, string}
     */
    private function cli(array $arguments): array
    {
        $application = new Application([new RestoreCommand($this->base . '/data', null, PluginRegistry::load())]);
        $output = new BufferedOutput();
        $code = $application->run(['threadsafe', 'restore', ...$arguments], $output);

        return [$code, $output->stdout(), $output->stderr()];
    }

    public function testWhatAPersonTypesBecomesAKeyOrAPassphrase(): void
    {
        $file = AgeKeys::keyFile($this->pair['secret'], $this->pair['public']);

        foreach ([$this->pair['secret'], " {$this->pair['secret']}\n", $file, str_replace("\n", "\r\n", $file)] as $text) {
            self::assertSame(['secretKeys' => [$this->pair['secret']], 'passphrase' => null], Unlock::fromText($text)->toArray());
        }
        self::assertSame(['secretKeys' => [], 'passphrase' => 'kr7m-xq3d-hp9t-w4nc-2vfe'], Unlock::fromText("kr7m-xq3d-hp9t-w4nc-2vfe\n")->toArray());
        self::assertSame('a passphrase with spaces', Unlock::fromText('a passphrase with spaces')->toArray()['passphrase']);

        $this->expectException(CryptoException::class);
        Unlock::fromText('AGE-SECRET-KEY-1NOTAKEY');
    }

    public function testTheSavedFormIsReadAgainAndABlankedOneIsNothing(): void
    {
        $unlock = $this->keyUnlock();

        self::assertEquals($unlock, Unlock::fromArray($unlock->toArray()));
        self::assertNull(Unlock::fromArray(null));
        self::assertNull(Unlock::fromArray(['secretKeys' => [], 'passphrase' => null]), 'what a finished run leaves');
        $this->expectException(CryptoException::class);
        Unlock::fromArray(['secretKeys' => 'x']);
    }

    public function testTheSourceFindsAllSealedPartsAndTheManifestWithTheKey(): void
    {
        $parts = $this->sealedBackup(['partSize' => 1048576]);
        self::assertGreaterThan(1, count($parts));

        $source = BackupSource::open($parts[0], $this->keyUnlock());

        self::assertSame($parts, $source->parts);
        self::assertSame('blog', $source->manifest->profileId);
        self::assertStringStartsWith('blog_', $source->manifest->backupId);
        $fromLast = BackupSource::open(array_reverse($parts)[0], $this->keyUnlock());
        self::assertSame($parts, $fromLast->parts, 'any part will do');
    }

    public function testWithoutAKeyOrWithAWrongOneTheMessageSaysWhy(): void
    {
        $parts = $this->sealedBackup();

        try {
            BackupSource::open($parts[0]);
            self::fail('an encrypted backup needs a key');
        } catch (RestoreException $e) {
            self::assertStringContainsString('is encrypted', $e->getMessage());
            self::assertStringContainsString('secret key', $e->getMessage());
        }

        $stranger = AgeKeys::generate();
        try {
            BackupSource::open($parts[0], Unlock::keys([$stranger['secret']]));
            self::fail('a stranger\'s key does not open it');
        } catch (RestoreException $e) {
            self::assertStringContainsString(basename($parts[0]), $e->getMessage());
            self::assertStringNotContainsString('Is a later part missing', $e->getMessage());
        }

        try {
            BackupSource::open($parts[0], Unlock::passphrase(Passphrases::generate()));
            self::fail('a passphrase does not open a file for keys');
        } catch (RestoreException $e) {
            self::assertSame(2, $e->exitCode->value);
        }
    }

    public function testAPlainBackupIgnoresTheKeyItIsGiven(): void
    {
        $plain = (new BackupCatalog($this->fixture->storage()))->archiveParts($this->fixture->backup())[0];

        $source = BackupSource::open($plain, $this->keyUnlock());

        self::assertNull($source->unlock);
        self::assertSame([$plain], $source->parts);
    }

    public function testASplitEncryptedBackupIsRestoredTickByTickAndTheStateKeepsNoKeyAfterTheExtraction(): void
    {
        $parts = $this->sealedBackup(['partSize' => 1048576]);
        $root = $this->base . '/restored';
        $store = new RunStore($this->base . '/runs');
        $executor = new Executor($store, new RestoreRun(PluginRegistry::load()), static fn (): Budget => new Budget(0.0));

        $run = $executor->start(['archive' => $parts[0], 'unlock' => $this->keyUnlock()->toArray(), 'root' => $root, 'work' => $this->base . '/work', 'settings' => ['selfTest' => false]]);
        $run = $executor->execute($run->id, 1);
        self::assertSame('extract', $run->state['phase']);
        self::assertSame($parts, $run->state['parts']);
        self::assertSame($this->pair['secret'], $run->state['unlock']['secretKeys'][0], 'the key is in the state of the run while the files are read');

        $ticks = 0;
        while ($run->status === RunStatus::Running && $run->state['phase'] === 'extract' && $ticks < 500) {
            $run = $executor->execute($run->id, 1);
            ++$ticks;
        }
        self::assertNull($run->state['unlock'], 'nothing after the extraction needs it');
        self::assertNull($run->state['extract']['unlock']);
        foreach (glob($this->base . '/runs/*') ?: [] as $file) {
            self::assertStringNotContainsString($this->pair['secret'], (string) file_get_contents($file));
        }

        $run = $executor->execute($run->id);
        self::assertSame(RunStatus::Finished, $run->status);
        self::assertSame($this->tree($this->fixture->site), $this->tree($root), 'the restored tree equals the site');
    }

    public function testTheCommandRestoresWithAKeyFileAndRefusesWithoutOrWithAWrongOne(): void
    {
        $parts = $this->sealedBackup();
        $root = $this->base . '/restored';

        [$code, , $stderr] = $this->cli([$parts[0], '--root=' . $root, '--no-self-test', '--yes']);
        self::assertSame(2, $code);
        self::assertStringContainsString('is encrypted', $stderr);
        self::assertDirectoryDoesNotExist($root);

        $stranger = AgeKeys::generate();
        [$code, , $stderr] = $this->cli([$parts[0], '--root=' . $root, '--no-self-test', '--yes', '--identity-file=' . $this->keyFile($stranger['secret'])]);
        self::assertSame(2, $code, $stderr);
        self::assertDirectoryDoesNotExist($root);

        [$code, $stdout, $stderr] = $this->cli([$parts[0], '--root=' . $root, '--no-self-test', '--yes', '--work-dir=' . $this->base . '/work', '--identity-file=' . $this->keyFile()]);
        self::assertSame(0, $code, $stdout . $stderr);
        self::assertSame($this->tree($this->fixture->site), $this->tree($root));
        self::assertStringNotContainsString($this->pair['secret'], $stdout . $stderr);
        self::assertSame([], (new RunStore($this->base . '/data/runs'))->all(), 'the state is deleted when the run is done');
    }

    public function testTheCommandRestoresABackupMadeForAPassphraseFromAFileOrTheEnvironment(): void
    {
        $passphrase = Passphrases::generate();
        $parts = (new BackupCatalog($this->fixture->storage()))->archiveParts($this->fixture->backup(['passphrase' => $passphrase]));
        $file = $this->base . '/pass.txt';
        file_put_contents($file, $passphrase . "\n");

        [$code, $stdout, $stderr] = $this->cli([$parts[0], '--root=' . $this->base . '/one', '--no-self-test', '--yes', '--work-dir=' . $this->base . '/work1', '--passphrase-file=' . $file]);
        self::assertSame(0, $code, $stdout . $stderr);
        self::assertSame($this->tree($this->fixture->site), $this->tree($this->base . '/one'));

        putenv('THREADSAFE_PASSPHRASE=' . $passphrase);
        [$code, $stdout, $stderr] = $this->cli([$parts[0], '--root=' . $this->base . '/two', '--no-self-test', '--yes', '--work-dir=' . $this->base . '/work2']);
        self::assertSame(0, $code, $stdout . $stderr);
        self::assertSame($this->tree($this->fixture->site), $this->tree($this->base . '/two'));
    }

    public function testBothKeyOptionsTogetherAreAUsageError(): void
    {
        $parts = $this->sealedBackup();

        [$code, , $stderr] = $this->cli([$parts[0], '--root=' . $this->base . '/r', '--identity-file=' . $this->keyFile(), '--passphrase-file=' . $this->keyFile()]);

        self::assertSame(1, $code);
        self::assertStringContainsString('not both', $stderr);
    }
}
