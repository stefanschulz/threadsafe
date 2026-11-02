<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Archive\ZipReader;
use ThreadSafe\Cli\Application;
use ThreadSafe\Cli\BufferedOutput;
use ThreadSafe\Cli\ToolBackupCommand;
use ThreadSafe\Crypto\AgeKeys;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Tests\Support\JoomlaSite;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * The backup tool in a shell (docs/PHASE12.md 3.7): the options become the one profile of the tool, in a data directory that is gone afterwards, and the
 * run is the one of the app.
 */
final class ToolBackupCommandTest extends TestCase
{
    use TempDirectory;

    private string $base;

    /** The site, and the directory the tool is in. */
    private string $site;

    private string $temporary;

    protected function setUp(): void
    {
        $this->base = $this->tempDirectory();
        $this->site = $this->base . '/site';
        $this->temporary = $this->base . '/tmp';
        mkdir($this->site . '/assets', 0777, true);
        mkdir($this->temporary);
        file_put_contents($this->site . '/index.php', '<?php echo 1;');
        file_put_contents($this->site . '/assets/data.txt', str_repeat("data\n", 4000));
        file_put_contents($this->site . '/threadsafe-backup.php', '<?php // the tool');
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string, string} exit code, stdout, stderr
     */
    private function cli(array $arguments, ?string $directory = null): array
    {
        $output = new BufferedOutput();
        $command = new ToolBackupCommand($directory ?? $this->site, 'threadsafe-backup.php', PluginRegistry::load(), $this->temporary);
        $code = (new Application([$command]))->run(['threadsafe-backup.php', 'backup', ...$arguments], $output);

        return [$code, $output->stdout(), $output->stderr()];
    }

    /** @return list<string> what is in the temporary directory */
    private function leftovers(): array
    {
        return array_values(array_diff(scandir($this->temporary) ?: [], ['.', '..']));
    }

    /** @return list<string> the names inside the first archive part of a directory */
    private function entries(string $directory): array
    {
        $parts = glob($directory . '/*.zip') ?: [];
        self::assertNotSame([], $parts, 'an archive is in ' . $directory);
        $names = [];
        foreach ($parts as $part) {
            foreach (ZipReader::open($part)->entries() as $entry) {
                $names[] = $entry->name;
            }
        }

        return $names;
    }

    // ------------------------------------------------------------------------------------------------- what it asks for

    public function testItWillNotMakeAnArchiveThatAnyoneCanReadBySilence(): void
    {
        [$code, , $errors] = $this->cli(['--no-db']);

        self::assertSame(1, $code);
        self::assertStringContainsString('Say who may open the backup', $errors);
        self::assertSame([], glob($this->site . '/.threadsafe-backup/storage-*/*.zip') ?: []);
    }

    public function testOnlyOneWayOfEncryptionIsAccepted(): void
    {
        $pair = AgeKeys::generate();
        file_put_contents($this->base . '/pass', 'a long passphrase of mine');

        [$code, , $errors] = $this->cli(['--no-db', '--no-encryption', '--recipient=' . $pair['public']]);
        self::assertSame(1, $code);
        self::assertStringContainsString('Give one of', $errors);

        [$code, , $errors] = $this->cli(['--no-db', '--recipient=' . $pair['public'], '--passphrase-file=' . $this->base . '/pass']);
        self::assertSame(1, $code);
        self::assertStringContainsString('Give one of', $errors);
    }

    public function testAPublicKeyThatIsNoKeyAndAWeakPassphraseAreRefused(): void
    {
        [$code, , $errors] = $this->cli(['--no-db', '--recipient=age1nonsense']);
        self::assertSame(1, $code);
        self::assertStringContainsString('not a public key', $errors);

        file_put_contents($this->base . '/weak', 'abc');
        [$code, , $errors] = $this->cli(['--no-db', '--passphrase-file=' . $this->base . '/weak']);
        self::assertSame(1, $code);
        self::assertStringContainsString('short', $errors);
    }

    public function testASiteOfNoKnownKindNeedsItsDatabaseOrTheStatementThatThereIsNone(): void
    {
        [$code, , $errors] = $this->cli(['--no-encryption']);
        self::assertSame(1, $code);
        self::assertStringContainsString('--db-name and --db-user', $errors);

        [$code, , $errors] = $this->cli(['--no-encryption', '--no-db', '--db-name=x']);
        self::assertSame(1, $code);
        self::assertStringContainsString('do not go together', $errors);

        [$code, , $errors] = $this->cli(['--no-encryption', '--db-name=x', '--db-user=y', '--db-port=many']);
        self::assertSame(1, $code);
        self::assertStringContainsString('port', $errors);

        [$code, , $errors] = $this->cli(['--no-encryption', '--db-name=x', '--db-user=y', '--db-password-file=' . $this->base . '/missing']);
        self::assertSame(1, $code);
        self::assertStringContainsString('Cannot read', $errors);
    }

    public function testADirectoryTheToolIsNotInIsRefused(): void
    {
        mkdir($this->base . '/other');

        [$code, , $errors] = $this->cli(['--no-db', '--no-encryption', '--root=' . $this->base . '/other']);

        self::assertSame(1, $code);
        self::assertStringContainsString('or one above it', $errors);
    }

    public function testNoPasswordIsAnOptionAndNothingElseIsAccepted(): void
    {
        [$code, , $errors] = $this->cli(['--no-db', '--no-encryption', '--db-password=secret']);
        self::assertSame(1, $code);
        self::assertStringContainsString('Unknown option --db-password', $errors);

        [$code, , $errors] = $this->cli(['--no-db', '--no-encryption', 'surplus']);
        self::assertSame(1, $code);
        self::assertStringContainsString('Unexpected argument', $errors);
    }

    // ------------------------------------------------------------------------------------------------- a backup

    public function testABackupInTheDirectoryOfTheToolHasTheSiteAndNotTheTool(): void
    {
        [$code, $out, $errors] = $this->cli(['--no-db', '--no-encryption']);

        self::assertSame(0, $code, $errors . $out);
        $storages = glob($this->site . '/.threadsafe-backup/storage-*', GLOB_ONLYDIR) ?: [];
        self::assertCount(1, $storages, 'the archive is in the storage of the tool, with a random name');
        self::assertStringContainsString($storages[0], $out, 'it says where');
        $names = $this->entries($storages[0]);
        self::assertContains('files/index.php', $names);
        self::assertContains('files/assets/data.txt', $names);
        foreach ($names as $name) {
            self::assertStringNotContainsString('threadsafe-backup', $name);
        }
    }

    public function testTheTemporaryDataDirectoryIsGoneAfterwards(): void
    {
        $this->cli(['--no-db', '--no-encryption']);
        $afterABackup = $this->leftovers();
        $this->cli(['--no-db']);   // a failure to start
        $afterAFailure = $this->leftovers();

        self::assertSame([[], []], [$afterABackup, $afterAFailure]);
    }

    public function testTheArchiveGoesWhereTheOptionSaysAndTheSiteCanBeALevelAbove(): void
    {
        $tools = $this->site . '/tools';
        mkdir($tools);
        $storage = $this->base . '/archives';

        [$code, , $errors] = $this->cli(['--no-db', '--no-encryption', '--root=' . $this->site, '--storage=' . $storage, '--exclude=assets/', '--part-size=2M'], $tools);

        self::assertSame(0, $code, $errors);
        $names = $this->entries($storage);
        self::assertContains('files/index.php', $names);
        self::assertNotContains('files/assets/data.txt', $names, '--exclude is honoured');
        self::assertSame([], glob($tools . '/.threadsafe-backup/storage-*') ?: [], 'nothing was made next to the tool: the archive is elsewhere');
    }

    public function testAnEncryptedBackupHasOnlySealedParts(): void
    {
        $pair = AgeKeys::generate();

        [$code, $out, $errors] = $this->cli(['--no-db', '--recipient=' . $pair['public']]);

        self::assertSame(0, $code, $errors . $out);
        $storage = (glob($this->site . '/.threadsafe-backup/storage-*', GLOB_ONLYDIR) ?: [''])[0];
        self::assertNotSame([], glob($storage . '/*.zip.age') ?: []);
        self::assertSame([], glob($storage . '/*.zip') ?: [], 'no plain part is left in the storage');
    }

    public function testAPassphraseFileEncryptsAndTheReportIsWritten(): void
    {
        file_put_contents($this->base . '/pass', "a long passphrase of mine\n");

        [$code, $out, $errors] = $this->cli(['--no-db', '--passphrase-file=' . $this->base . '/pass', '--report=' . $this->base . '/report.json']);

        self::assertSame(0, $code, $errors . $out);
        $report = json_decode((string) file_get_contents($this->base . '/report.json'), true);
        self::assertIsArray($report);
        self::assertTrue($report['result']['encrypted']);
    }

    public function testASiteOfAKnownKindIsDetectedAndNeedsNoDatabaseOptions(): void
    {
        JoomlaSite::build($this->site);

        [$code, $out, $errors] = $this->cli(['--no-encryption']);

        // the database named in the test configuration is not there, so the backup is refused; what matters is that the kind came from the site
        // and that no --db option was asked for
        self::assertContains($code, [2, 3], $errors);
        self::assertStringContainsString('(joomla)', $out);
        self::assertStringNotContainsString('--db-name', $errors);
    }

    public function testTheHelpListsTheOptions(): void
    {
        $output = new BufferedOutput();
        $code = (new Application([new ToolBackupCommand($this->site, 'threadsafe-backup.php', PluginRegistry::load(), $this->temporary)]))->run(['x', 'help', 'backup'], $output);

        self::assertSame(0, $code);
        foreach (['--recipient=KEY', '--passphrase-file=FILE', '--no-encryption', '--storage=DIR', '--db-password-file=FILE'] as $option) {
            self::assertStringContainsString($option, $output->stdout());
        }
    }
}
