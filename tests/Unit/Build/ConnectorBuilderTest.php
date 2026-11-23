<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Build;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use ThreadSafe\Archive\ZipReader;
use ThreadSafe\Build\ConnectorBuilder;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * The ZIP of the WordPress plugin (docs/PHASE13.md 9.12): the files of the plugin with the version in the header of the main file, the built tool next to
 * them, one top directory named as WordPress expects, and the same bytes for the same input.
 */
final class ConnectorBuilderTest extends TestCase
{
    use TempDirectory;

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = $this->tempDirectory();
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    private function tool(): string
    {
        $file = $this->directory . '/built/threadsafe-backup.php';
        @mkdir(dirname($file));
        file_put_contents($file, '<?php // the tool');

        return $file;
    }

    /**
     * @return array<string, string> name => content
     */
    private function entries(string $zipPath): array
    {
        $zip = ZipReader::open($zipPath);
        $entries = [];
        foreach ($zip->entries() as $entry) {
            $entries[$entry->name] = $zip->contents($entry);
        }

        return $entries;
    }

    public function testThePackageHoldsThePluginAndTheToolUnderOneDirectory(): void
    {
        $zip = $this->directory . '/out/' . ConnectorBuilder::packageName('1.1.0');

        $names = (new ConnectorBuilder(dirname(__DIR__, 3)))->write($zip, $this->tool(), '1.1.0', 1_700_000_000);

        $entries = $this->entries($zip);
        self::assertSame($names, array_keys($entries));
        foreach (array_keys($entries) as $name) {
            self::assertStringStartsWith('threadsafe-connector/', $name);
        }
        foreach (['threadsafe-connector.php', 'agent.php', 'uninstall.php', 'includes/plugin.php', 'includes/boot.php', 'threadsafe-backup.php'] as $file) {
            self::assertArrayHasKey('threadsafe-connector/' . $file, $entries);
        }
        self::assertSame('<?php // the tool', $entries['threadsafe-connector/threadsafe-backup.php']);
        self::assertMatchesRegularExpression('/^ \* Version:\s+1\.1\.0\s*$/m', $entries['threadsafe-connector/threadsafe-connector.php']);
        self::assertStringNotContainsString('0.0.0-dev', $entries['threadsafe-connector/threadsafe-connector.php']);
    }

    public function testTwoBuildsOfOneInputAreTheSameBytes(): void
    {
        $builder = new ConnectorBuilder(dirname(__DIR__, 3));
        $tool = $this->tool();
        $builder->write($this->directory . '/a.zip', $tool, '1.1.0-rc.1', 1_700_000_000);
        $builder->write($this->directory . '/b.zip', $tool, '1.1.0-rc.1', 1_700_000_000);

        self::assertSame(hash_file('sha256', $this->directory . '/a.zip'), hash_file('sha256', $this->directory . '/b.zip'));
    }

    public function testAVersionThatIsNotOneIsRefused(): void
    {
        $this->expectException(RuntimeException::class);

        ConnectorBuilder::stampVersion(" * Version:           0.0.0-dev\n", 'latest');
    }

    public function testAHeaderWithoutExactlyOneVersionLineIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exactly one');

        ConnectorBuilder::stampVersion("<?php\n/** no version here */\n", '1.0.0');
    }

    public function testThePackageOfTheModuleHoldsItsFilesAndTheToolUnderTheDirectoryOfPrestaShop(): void
    {
        $name = ConnectorBuilder::packageName('1.3.0', 'prestashop');
        self::assertSame('threadsafe-connector-prestashop-1.3.0.zip', $name);
        $zip = $this->directory . '/out/' . $name;

        $builder = new ConnectorBuilder(dirname(__DIR__, 3), 'prestashop');
        $names = $builder->write($zip, $this->tool(), '1.3.0', 1_700_000_000);

        self::assertSame('threadsafeconnector', $builder->directory());
        $entries = $this->entries($zip);
        self::assertSame($names, array_keys($entries));
        foreach (array_keys($entries) as $entry) {
            self::assertStringStartsWith('threadsafeconnector/', $entry);
        }
        foreach (['threadsafeconnector.php', 'agent.php', 'index.php', '.htaccess', 'includes/page.php', 'threadsafe-backup.php'] as $file) {
            self::assertArrayHasKey('threadsafeconnector/' . $file, $entries);
        }
        self::assertSame('<?php // the tool', $entries['threadsafeconnector/threadsafe-backup.php']);
        self::assertStringContainsString("\$this->version = '1.3.0';", $entries['threadsafeconnector/threadsafeconnector.php']);
        self::assertStringNotContainsString('0.0.0-dev', $entries['threadsafeconnector/threadsafeconnector.php']);
    }

    public function testTheLineOfTheVersionOfTheModuleIsFoundOnceOrRefused(): void
    {
        self::assertSame("\$this->version = '2.0.0-rc.1';", ConnectorBuilder::stampVersion("\$this->version = '0.0.0-dev';", '2.0.0-rc.1', 'prestashop'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exactly one');

        ConnectorBuilder::stampVersion("<?php // no version\n", '2.0.0', 'prestashop');
    }

    public function testAKindThatDoesNotExistIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no satellite of the kind');

        new ConnectorBuilder(dirname(__DIR__, 3), 'joomla');
    }

    public function testAMissingToolIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is missing');

        (new ConnectorBuilder(dirname(__DIR__, 3)))->write($this->directory . '/x.zip', $this->directory . '/none.php', '1.1.0', 1);
    }
}
