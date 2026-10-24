<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Joomla;

use PHPUnit\Framework\TestCase;
use ThreadSafe\App\ProfileService;
use ThreadSafe\Cli\Input;
use ThreadSafe\Cli\OptionSpec;
use ThreadSafe\Core\Step;
use ThreadSafe\Joomla\JoomlaType;
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

    public function testTheTypeIsRegisteredAndCanBeBackedUpAndRestored(): void
    {
        $plugins = PluginRegistry::load();

        self::assertTrue($plugins->has('joomla'));
        self::assertInstanceOf(JoomlaType::class, $plugins->get('joomla'));
        self::assertSame('Joomla', $plugins->get('joomla')->title());
        self::assertSame('joomla', $plugins->backupable('joomla')->detect($this->site())?->application === 'Joomla' ? 'joomla' : '');

        self::assertInstanceOf(JoomlaType::class, $plugins->restorable('joomla'));
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

    public function testTheStepsAreTheAdaptationInItsOrder(): void
    {
        $ids = array_map(static fn (Step $step): string => $step->id(), (new JoomlaType())->steps());

        self::assertSame(['read-source-state', 'ensure-skeleton', 'write-configuration', 'replace-content-domains', 'rewrite-htaccess', 'discard-caches', 'self-test'], $ids);
    }

    public function testTheMigrationOptionAndSettingOfTheBasePath(): void
    {
        $type = new JoomlaType();

        self::assertSame(['path'], array_map(static fn (OptionSpec $option): string => $option->name, $type->options()));
        self::assertSame(['path' => '/sub/'], $type->settings(Input::parse(['--path=/sub/'], $type->options())));
        self::assertSame([], $type->settings(Input::parse([], $type->options())));
    }
}
