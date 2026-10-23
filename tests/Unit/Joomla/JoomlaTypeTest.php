<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Joomla;

use PHPUnit\Framework\TestCase;
use ThreadSafe\App\ProfileService;
use ThreadSafe\Cli\Application;
use ThreadSafe\Cli\BufferedOutput;
use ThreadSafe\Cli\MigrateCommand;
use ThreadSafe\Joomla\JoomlaType;
use ThreadSafe\Joomla\Steps\NotYetBuilt;
use ThreadSafe\Plugin\PluginError;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Profile\ProfileException;
use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * The Joomla type as the rest of the program meets it: through the plugin registry, the profile service ("Look at the site") and the
 * migrate command, which must refuse until the adaptation exists.
 */
final class JoomlaTypeTest extends TestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    private function site(string $major = '6', string $minor = '2', string $patch = '0'): string
    {
        $root = (string) realpath($this->tempDirectory());
        mkdir($root . '/libraries/src', 0777, true);
        mkdir($root . '/administrator', 0777, true);
        file_put_contents($root . '/libraries/src/Version.php', "<?php\nfinal class Version\n{\n    public const MAJOR_VERSION = $major;\n    public const MINOR_VERSION = $minor;\n    public const PATCH_VERSION = $patch;\n    public const EXTRA_VERSION = '';\n}\n");
        copy(dirname(__DIR__, 2) . '/fixtures/joomla/configuration-6.2.php', $root . '/configuration.php');

        return $root;
    }

    public function testTheTypeIsRegisteredAndCanBeBackedUpButNotRestoredYet(): void
    {
        $plugins = PluginRegistry::load();

        self::assertTrue($plugins->has('joomla'));
        self::assertInstanceOf(JoomlaType::class, $plugins->get('joomla'));
        self::assertSame('Joomla', $plugins->get('joomla')->title());
        self::assertSame('joomla', $plugins->backupable('joomla')->detect($this->site())?->application === 'Joomla' ? 'joomla' : '');

        $this->expectException(PluginError::class);
        $this->expectExceptionMessage('does not support restores');
        $plugins->restorable('joomla');
    }

    public function testTheTypeIsOfferedWhereAProfileChoosesItsType(): void
    {
        $service = new ProfileService(new ProfileStore($this->tempDirectory()), PluginRegistry::load(), $this->tempDirectory());

        self::assertSame('Joomla', $service->types()['joomla']);
    }

    public function testLookingAtASiteTellsWhatWasFoundAndWhatIsLeftOut(): void
    {
        $service = new ProfileService(new ProfileStore($this->tempDirectory()), PluginRegistry::load(), $this->tempDirectory());
        $root = $this->site();

        $detection = $service->detect('joomla', $root);

        self::assertSame('Joomla 6.2.0', $detection->site->describe());
        self::assertSame($root, $detection->root);
        self::assertSame('joomla_test', $detection->database?->name);
        self::assertSame('joom_', $detection->database->prefix);
        self::assertSame(8, $detection->excludedPaths);
        self::assertSame(10, $detection->structureOnlyTables);
    }

    public function testADirectoryThatIsNotJoomla5Or6IsRefusedWithTheTypeNamed(): void
    {
        $service = new ProfileService(new ProfileStore($this->tempDirectory()), PluginRegistry::load(), $this->tempDirectory());

        $this->expectException(ProfileException::class);
        $this->expectExceptionMessage('not an installation of the website type "joomla"');

        $service->detect('joomla', $this->site('4', '4', '10'));
    }

    public function testTheMigrationRefusesInsteadOfReportingSuccessForASiteThatNothingWasDoneTo(): void
    {
        $steps = (new JoomlaType())->steps();
        self::assertCount(1, $steps);
        self::assertInstanceOf(NotYetBuilt::class, $steps[0]);

        $output = new BufferedOutput();
        $code = (new Application([new MigrateCommand()]))->run(['threadsafe', 'migrate', 'joomla', '--root=' . $this->site(), '--from=old.test', '--to=new.test'], $output);

        self::assertSame(2, $code, 'a refused run');
        self::assertStringContainsString('not built yet', $output->stderr() . $output->stdout());
    }
}
