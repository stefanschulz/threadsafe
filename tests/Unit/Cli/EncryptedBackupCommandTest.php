<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Cli\Application;
use ThreadSafe\Cli\BackupCommand;
use ThreadSafe\Cli\BackupsCommand;
use ThreadSafe\Cli\BufferedOutput;
use ThreadSafe\Cli\VerifyCommand;
use ThreadSafe\Crypto\AgeKeys;
use ThreadSafe\Crypto\Passphrases;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Storage\BackupCatalog;
use ThreadSafe\Tests\Support\BackupFixture;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * Encrypted backups on the command line: a backup for a passphrase, the listing, and the check with and without the key.
 */
final class EncryptedBackupCommandTest extends TestCase
{
    use TempDirectory;

    private BackupFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new BackupFixture($this->tempDirectory());
    }

    protected function tearDown(): void
    {
        putenv('THREADSAFE_PASSPHRASE');
        $this->cleanUpTempDirectories();
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string, string}
     */
    private function cli(array $arguments): array
    {
        $registry = PluginRegistry::load();
        $application = new Application([new BackupCommand($this->fixture->data, $registry), new BackupsCommand($this->fixture->data, $registry), new VerifyCommand($this->fixture->data)]);
        $output = new BufferedOutput();
        $code = $application->run(['threadsafe', ...$arguments], $output);

        return [$code, $output->stdout(), $output->stderr()];
    }

    private function secretFile(string $content): string
    {
        $file = $this->tempDirectory() . '/' . bin2hex(random_bytes(4)) . '.txt';
        file_put_contents($file, $content);

        return $file;
    }

    /** @return list<string> the files of the storage directory */
    private function storageFiles(): array
    {
        return array_values(array_diff(scandir($this->fixture->storageDirectory) ?: [], ['.', '..', '.htaccess', 'index.html', 'web.config']));
    }

    public function testABackupWithAPassphraseFileIsForThePassphraseOnlyAndTheSameFileChecksIt(): void
    {
        $other = AgeKeys::generate();
        $this->fixture->profiles->save($this->fixture->profiles->load('blog')->with(['encryption' => ['recipients' => [$other['public']]]]));
        $passphrase = Passphrases::generate();
        $file = $this->secretFile($passphrase . "\n");

        [$code, $stdout, $stderr] = $this->cli(['backup', 'blog', '--passphrase-file=' . $file]);

        self::assertSame(0, $code, $stdout . $stderr);
        self::assertStringNotContainsString($passphrase, $stdout . $stderr, 'the passphrase is never printed');
        $entries = (new BackupCatalog($this->fixture->storage()))->entries('blog');
        self::assertCount(1, $entries);
        $encryption = $entries[0]->sidecar?->encryption;
        self::assertNotNull($encryption);
        self::assertTrue($encryption['passphrase']);
        self::assertSame([], $encryption['recipients'], 'the keys of the profile are not used when a passphrase is given');
        self::assertSame([$entries[0]->name->base() . '.json', $entries[0]->name->base() . '.zip.age'], array_values(array_filter($this->storageFiles(), static fn (string $f): bool => str_starts_with($f, 'blog_'))));

        [$code, $stdout, $stderr] = $this->cli(['verify', 'blog', '--passphrase-file=' . $file]);
        self::assertSame(0, $code, $stdout . $stderr);
        self::assertStringContainsString('(full check', $stdout);

        [$code, , $stderr] = $this->cli(['verify', 'blog', '--passphrase-file=' . $this->secretFile(Passphrases::generate())]);
        self::assertSame(3, $code);
        self::assertStringContainsString('NOT intact', $stderr);

        [$code, $stdout] = $this->cli(['verify', 'blog']);
        self::assertSame(0, $code);
        self::assertStringContainsString('(quick check', $stdout, 'without the key only the quick check is possible');
        self::assertStringContainsString('Note:', $stdout);
    }

    public function testThePassphraseMayComeFromTheEnvironment(): void
    {
        putenv('THREADSAFE_PASSPHRASE=' . ($passphrase = Passphrases::generate()));

        [$code, $stdout, $stderr] = $this->cli(['backup', 'blog']);

        self::assertSame(0, $code, $stdout . $stderr);
        $entry = (new BackupCatalog($this->fixture->storage()))->entries('blog')[0];
        self::assertTrue($entry->sidecar?->encryption['passphrase'] ?? false);
        [$code, $stdout] = $this->cli(['verify', 'blog']);
        self::assertSame(0, $code);
        self::assertStringContainsString('(full check', $stdout, 'the same variable opens it for the check');
        self::assertNotSame('', $passphrase);
    }

    public function testAWeakPassphraseIsRefusedBeforeAnythingIsDone(): void
    {
        [$code, , $stderr] = $this->cli(['backup', 'blog', '--passphrase-file=' . $this->secretFile("short\n")]);

        self::assertSame(1, $code);
        self::assertStringContainsString('short', $stderr);
        self::assertSame([], $this->fixture->runs->all('backup'));
        self::assertSame([], (new BackupCatalog($this->fixture->storage()))->entries('blog'));
    }

    public function testTheListingMarksEncryptedBackupsAndTheCheckWithAKeyFileIsFull(): void
    {
        $pair = AgeKeys::generate();
        $this->fixture->profiles->save($this->fixture->profiles->load('blog')->with(['encryption' => ['recipients' => [$pair['public']]]]));
        $this->fixture->backup();

        [, $stdout] = $this->cli(['backups', 'blog']);
        self::assertMatchesRegularExpression('/ok encrypted/', $stdout);
        [, $json] = $this->cli(['backups', 'blog', '--json']);
        $data = json_decode($json, true);
        self::assertTrue($data['backups'][0]['encrypted']);

        $keyFile = $this->secretFile(AgeKeys::keyFile($pair['secret'], $pair['public']));
        [$code, $stdout, $stderr] = $this->cli(['verify', 'blog', '--identity-file=' . $keyFile]);
        self::assertSame(0, $code, $stdout . $stderr);
        self::assertStringContainsString('(full check', $stdout);

        $stranger = AgeKeys::generate();
        [$code, , $stderr] = $this->cli(['verify', 'blog', '--identity-file=' . $this->secretFile(AgeKeys::keyFile($stranger['secret'], $stranger['public']))]);
        self::assertSame(3, $code);
        self::assertStringContainsString('NOT intact', $stderr);

        [$code, , $stderr] = $this->cli(['verify', 'blog', '--identity-file=' . $keyFile, '--passphrase-file=' . $keyFile]);
        self::assertSame(1, $code);
        self::assertStringContainsString('not both', $stderr);
    }

    public function testAnUnencryptedBackupIsNotMarked(): void
    {
        $this->fixture->backup();

        [, $stdout] = $this->cli(['backups', 'blog']);
        self::assertStringNotContainsString('encrypted', $stdout);
    }
}
