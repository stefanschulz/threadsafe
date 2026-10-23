<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Plugin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Plugin\PluginError;
use ThreadSafe\Plugin\PluginManifest;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Plugin\WebsiteType;
use ThreadSafe\Tests\Support\TempDirectory;

final class PluginRegistryTest extends TestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    /**
     * Creates a plugin directory with a manifest and an entry class in a namespace of its own (the classes of different
     * tests must not collide).
     *
     * @param array<string, mixed> $manifest overrides for plugin.json
     *
     * @return array{string, string} the plugins directory and the entry class name
     */
    private function plugin(string $id, array $manifest = [], ?string $classBody = null, ?string $plugins = null): array
    {
        $plugins ??= $this->tempDirectory() . '/plugins';
        $namespace = 'ThreadSafe\\Tests\\Tmp' . bin2hex(random_bytes(4));
        $class = $namespace . '\\DemoType';
        mkdir($plugins . '/' . $id . '/src', 0777, true);

        $data = $manifest + [
            'id' => $id,
            'title' => 'Demo',
            'version' => '1.0.0',
            'api' => 1,
            'class' => $class,
            'autoload' => [$namespace . '\\' => 'src/'],
        ];
        file_put_contents($plugins . '/' . $id . '/plugin.json', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        $body = $classBody ?? <<<'PHP'
            final class DemoType implements \ThreadSafe\Plugin\WebsiteType
            {
                public function id(): string { return '%ID%'; }
                public function title(): string { return 'Demo'; }
                public function options(): array { return []; }
                public function settings(\ThreadSafe\Cli\Input $input): array { return []; }
                public function requestBuilder(): \ThreadSafe\Migration\RequestBuilder { throw new \LogicException('not needed'); }
                public function steps(): array { return []; }
            }
            PHP;
        file_put_contents($plugins . '/' . $id . '/src/DemoType.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace $namespace;\n\n" . str_replace('%ID%', $id, $body) . "\n");

        return [$plugins, $class];
    }

    public function testTheShippedPluginsLoad(): void
    {
        $registry = PluginRegistry::load();

        self::assertSame(['generic', 'joomla', 'prestashop9'], $registry->ids());
        self::assertSame('prestashop9', $registry->get('prestashop9')->id());
        self::assertSame('PrestaShop 9', $registry->get('prestashop9')->title());
        self::assertSame('generic', $registry->get('generic')->id());
        self::assertSame(['generic', 'joomla', 'prestashop9'], array_map(static fn (WebsiteType $t): string => $t->id(), $registry->all()));
        self::assertSame($registry->get('generic'), $registry->get('generic'), 'A plugin is created once.');
    }

    public function testAPluginDirectoryWorksWithoutAnEntryInComposerJson(): void
    {
        [$plugins, $class] = $this->plugin('demo');

        $registry = PluginRegistry::load($plugins);

        self::assertSame(['demo'], $registry->ids());
        self::assertTrue($registry->has('demo'));
        self::assertSame($class, $registry->manifest('demo')->class);
        self::assertSame('demo', $registry->get('demo')->id());
        self::assertSame($class, $registry->get('demo')::class);
    }

    public function testDirectoriesWithoutAManifestAreIgnored(): void
    {
        [$plugins] = $this->plugin('demo');
        mkdir($plugins . '/not-a-plugin');

        self::assertSame(['demo'], PluginRegistry::load($plugins)->ids());
    }

    public function testUnknownPluginNamesTheAvailableOnes(): void
    {
        [$plugins] = $this->plugin('demo');
        $registry = PluginRegistry::load($plugins);

        $this->expectException(PluginError::class);
        $this->expectExceptionMessage('There is no website type "wordpress" (available: demo).');

        $registry->get('wordpress');
    }

    public function testMissingEntryClassIsExplained(): void
    {
        [$plugins] = $this->plugin('demo', ['class' => 'ThreadSafe\\Tests\\Nowhere\\Missing']);
        $registry = PluginRegistry::load($plugins);

        $this->expectException(PluginError::class);
        $this->expectExceptionMessage('the class "ThreadSafe\\Tests\\Nowhere\\Missing" does not exist');

        $registry->get('demo');
    }

    public function testEntryClassMustBeAWebsiteType(): void
    {
        [$plugins] = $this->plugin('demo', classBody: 'final class DemoType {}');
        $registry = PluginRegistry::load($plugins);

        $this->expectException(PluginError::class);
        $this->expectExceptionMessage('does not implement ' . WebsiteType::class);

        $registry->get('demo');
    }

    public function testTheTypeMustCallItselfLikeThePlugin(): void
    {
        [$plugins] = $this->plugin('demo');
        // The entry class answers "demo"; a manifest of the same class under another id must not pass.
        $manifest = json_decode((string) file_get_contents($plugins . '/demo/plugin.json'), true, 16, JSON_THROW_ON_ERROR);
        mkdir($plugins . '/other');
        file_put_contents($plugins . '/other/plugin.json', json_encode(['id' => 'other'] + $manifest));
        $registry = PluginRegistry::load($plugins);

        $this->expectException(PluginError::class);
        $this->expectExceptionMessage('its website type calls itself "demo"');

        $registry->get('other');
    }

    /**
     * @param array<string, mixed> $manifest
     */
    #[DataProvider('brokenManifests')]
    public function testBrokenManifestsAreRefusedAtLoading(array $manifest, string $messagePart): void
    {
        [$plugins] = $this->plugin('demo', $manifest);

        $this->expectException(PluginError::class);
        $this->expectExceptionMessage($messagePart);

        PluginRegistry::load($plugins);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function brokenManifests(): iterable
    {
        yield 'id is not the directory name' => [['id' => 'something_else'], '"id" must be the name of the directory ("demo")'];
        yield 'bad id' => [['id' => 'Demo-X'], '"id" must be lower-case letters'];
        yield 'wrong api' => [['api' => 2], '"api" must be 1'];
        yield 'no api' => [['api' => null], '"api" must be 1'];
        yield 'unknown key' => [['hooks' => []], 'unknown key "hooks"'];
        yield 'bad class name' => [['class' => 'not a class'], '"class" a class name'];
        yield 'autoload empty' => [['autoload' => []], '"autoload" must map a namespace'];
        yield 'autoload prefix without a trailing backslash' => [['autoload' => ['ThreadSafe\\X' => 'src/']], 'namespace ending in a backslash'];
        yield 'autoload leaves the plugin' => [['autoload' => ['ThreadSafe\\X\\' => '../src/']], 'relative directory inside the plugin'];
        yield 'autoload absolute' => [['autoload' => ['ThreadSafe\\X\\' => '/etc/']], 'relative directory inside the plugin'];
    }

    public function testManifestFileProblems(): void
    {
        $directory = $this->tempDirectory() . '/demo';
        mkdir($directory);
        try {
            PluginManifest::fromDirectory($directory);
            self::fail('A directory without plugin.json must be refused.');
        } catch (PluginError $e) {
            self::assertStringContainsString('has no plugin.json', $e->getMessage());
        }

        file_put_contents($directory . '/plugin.json', '[1, 2]');
        $this->expectException(PluginError::class);
        $this->expectExceptionMessage('not a JSON object');

        PluginManifest::fromDirectory($directory);
    }
}
