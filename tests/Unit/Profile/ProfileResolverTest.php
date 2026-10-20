<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Profile;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Profile\ConnectionTest;
use ThreadSafe\Profile\ProfileException;
use ThreadSafe\Profile\ProfileResolver;
use ThreadSafe\Profile\SiteProfile;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * The profile resolver and the backup side of the two website types, with the real plugins and small fake sites.
 */
final class ProfileResolverTest extends TestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    private function resolver(?string $data = null): ProfileResolver
    {
        return new ProfileResolver(PluginRegistry::load(), $data ?? $this->tempDirectory() . '/data');
    }

    /** A directory that looks like a PrestaShop installation. */
    private function shop(string $version = '9.0.2', string $admin = 'admin-x'): string
    {
        $root = $this->tempDirectory() . '/shop';
        mkdir($root . '/src/Core', 0777, true);
        mkdir($root . '/app/config', 0777, true);
        mkdir($root . '/' . $admin . '/themes/new-theme', 0777, true);
        file_put_contents($root . '/src/Core/Version.php', "<?php\nfinal class Version\n{\n    public const VERSION = '$version';\n}\n");
        file_put_contents($root . '/app/config/parameters.php', '<?php return ' . var_export(['parameters' => [
            'database_host' => 'dbhost', 'database_port' => '3307', 'database_name' => 'shopdb', 'database_user' => 'shopuser',
            'database_password' => "p'w", 'database_prefix' => 'ps_',
        ]], true) . ';');

        return $root;
    }

    /** @param array<string, mixed> $changes */
    private function profile(string $type, string $path, array $changes = []): SiteProfile
    {
        return SiteProfile::fromArray([...SiteProfileTest::minimal(), 'type' => $type, 'path' => $path, 'storage' => $this->tempDirectory() . '/storage', ...$changes]);
    }

    public function testPrestaShopIsDetectedAndItsDatabaseReadWithoutStartingIt(): void
    {
        $resolved = $this->resolver()->resolve($this->profile('prestashop9', $this->shop()));

        self::assertSame('PrestaShop 9.0.2', $resolved->site->describe());
        self::assertSame('admin-x', $resolved->site->detail('adminDirectory'));
        self::assertSame(['dbhost', 3307, 'shopdb', 'shopuser', "p'w", 'ps_'], [
            $resolved->database?->host, $resolved->database?->port, $resolved->database?->name, $resolved->database?->user, $resolved->database?->password, $resolved->database?->prefix,
        ]);
    }

    public function testPrestaShopDefaultFiltersUseTheRealBackOfficeFolder(): void
    {
        $resolved = $this->resolver()->resolve($this->profile('prestashop9', $this->shop(admin: 'adm-7q')));

        self::assertContains('var/cache', $resolved->excludePaths);
        self::assertContains('adm-7q/autoupgrade/backup', $resolved->excludePaths);
        self::assertNotContains('{admin}/autoupgrade/backup', $resolved->excludePaths);
        self::assertContains('guest', $resolved->structureOnly);
        self::assertContains('log', $resolved->structureOnly);

        $filters = $resolved->filters();
        self::assertTrue($filters->excludes('var/cache', true));
        self::assertTrue($filters->excludes('adm-7q/autoupgrade/backup', true));
        self::assertTrue($filters->excludes('modules/x/.git', true));
        self::assertFalse($filters->excludes('modules', true));
        self::assertFalse($filters->excludes('adm-7q/index.php', false));
    }

    public function testWithoutABackOfficeFolderItsFiltersAreLeftOut(): void
    {
        $root = $this->shop();
        rename($root . '/admin-x', $root . '/other');   // no themes/new-theme is found any more: still a folder, not a back office
        rmdir($root . '/other/themes/new-theme');

        $resolved = $this->resolver()->resolve($this->profile('prestashop9', $root));

        self::assertNull($resolved->site->detail('adminDirectory'));
        self::assertSame([], array_values(array_filter($resolved->excludePaths, static fn (string $p): bool => str_contains($p, 'autoupgrade') || str_contains($p, '{'))));
    }

    public function testTheProfilesFiltersAreAddedToTheDefaults(): void
    {
        $resolved = $this->resolver()->resolve($this->profile('prestashop9', $this->shop(), ['excludePaths' => ['var/old', 'var/cache'], 'structureOnlyTables' => ['mytable']]));

        self::assertContains('var/old', $resolved->excludePaths);
        self::assertSame(1, count(array_keys($resolved->excludePaths, 'var/cache', true)), 'No duplicates.');
        self::assertContains('mytable', $resolved->structureOnly);
        self::assertContains('guest', $resolved->structureOnly);
    }

    public function testDefaultsCanBeSwitchedOff(): void
    {
        $resolved = $this->resolver()->resolve($this->profile('prestashop9', $this->shop(), ['useDefaultFilters' => false, 'excludePaths' => ['x']]));

        self::assertSame(['x'], $resolved->excludePaths);
        self::assertSame([], $resolved->structureOnly);
    }

    public function testTheProfileDatabaseWinsOverTheConfiguration(): void
    {
        $resolved = $this->resolver()->resolve($this->profile('prestashop9', $this->shop(), ['database' => ['name' => 'other', 'user' => 'o', 'password' => 'x', 'prefix' => 'xx_']]));

        self::assertSame('other', $resolved->database?->name);
    }

    public function testGenericNeedsNoDetectionAndHasNoDefaults(): void
    {
        $resolved = $this->resolver()->resolve($this->profile('generic', $this->tempDirectory()));

        self::assertSame('Any website', $resolved->site->describe());
        self::assertNull($resolved->database);
        self::assertSame([], $resolved->excludePaths);
        self::assertSame([], $resolved->structureOnly);
        self::assertSame([], $resolved->type->manifestExtras($resolved->site, null));
    }

    public function testGenericTakesTheDatabaseFromTheProfile(): void
    {
        $resolved = $this->resolver()->resolve($this->profile('generic', $this->tempDirectory(), ['database' => ['name' => 'blog', 'user' => 'u']]));

        self::assertSame('blog', $resolved->database?->name);
    }

    public function testThingsThatAreNotAPrestaShop9(): void
    {
        foreach ([
            'an empty directory' => $this->tempDirectory(),
            'PrestaShop 8' => $this->shop('8.1.5'),
            'a missing directory' => $this->tempDirectory() . '/missing',
        ] as $what => $path) {
            try {
                $this->resolver()->resolve($this->profile('prestashop9', $path));
                self::fail("Accepted $what");
            } catch (ProfileException $e) {
                self::assertMatchesRegularExpression('/not an installation of the website type|not a readable directory/', $e->getMessage(), $what);
            }
        }
    }

    public function testAShopWithoutReadableParametersIsRefusedWithTheReason(): void
    {
        $root = $this->shop();
        unlink($root . '/app/config/parameters.php');

        $this->expectException(ProfileException::class);
        $this->expectExceptionMessage('Cannot read the database settings');

        $this->resolver()->resolve($this->profile('prestashop9', $root));
    }

    public function testUnknownTypeAndATypeWithoutBackup(): void
    {
        $this->expectException(ProfileException::class);
        $this->expectExceptionMessage('There is no website type "nope"');

        $this->resolver()->resolve($this->profile('nope', $this->tempDirectory()));
    }

    public function testStorageAndDataInsideTheSiteAreLeftOutExactly(): void
    {
        $root = $this->shop();
        $data = $root . '/data';
        mkdir($data);
        $resolved = $this->resolver($data)->resolve($this->profile('prestashop9', $root, ['storage' => $root . '/var/backups/[x] shop']));

        self::assertSame(['var/backups/[x] shop', 'data'], $resolved->literalExcludes, 'The storage need not exist yet.');
        self::assertTrue($resolved->filters()->excludes('var/backups/[x] shop', true));
        self::assertFalse($resolved->filters()->excludes('var/backups/x shop', true), 'Exactly, not as a pattern.');
        self::assertTrue($resolved->filters()->excludes('data', true));
    }

    public function testStorageOutsideTheSiteIsNotMentioned(): void
    {
        $resolved = $this->resolver()->resolve($this->profile('generic', $this->tempDirectory()));

        self::assertSame([], $resolved->literalExcludes);
    }

    public function testRelativeInside(): void
    {
        $root = $this->tempDirectory();
        mkdir($root . '/a/b', 0777, true);

        self::assertSame('a/b', ProfileResolver::relativeInside((string) realpath($root), $root . '/a/b'));
        self::assertSame('a/new/deeper', ProfileResolver::relativeInside((string) realpath($root), $root . '/a/new/deeper'));
        self::assertNull(ProfileResolver::relativeInside((string) realpath($root), $root));
        self::assertNull(ProfileResolver::relativeInside((string) realpath($root), $this->tempDirectory()));
        self::assertNull(ProfileResolver::relativeInside((string) realpath($root), $root . '-sibling/x'));
    }

    public function testConnectionTestOfAFileSiteWithoutDatabase(): void
    {
        $site = $this->tempDirectory();
        $report = (new ConnectionTest(PluginRegistry::load(), $this->tempDirectory() . '/data'))->run($this->profile('generic', $site));

        self::assertTrue($report->passed(), implode("\n", $report->messages(\ThreadSafe\Profile\CheckLevel::Failure)));
        $text = implode("\n", array_map(static fn ($c): string => $c->subject . ': ' . $c->message, $report->checks));
        self::assertStringContainsString('Any website', $text);
        self::assertStringContainsString('Database: none: only the files are backed up', $text);
        self::assertStringContainsString('does not exist yet and can be created', $text);
        self::assertStringContainsString('PHP command line', $text);
    }

    public function testConnectionTestSaysWhyASiteFails(): void
    {
        $profile = $this->profile('prestashop9', $this->tempDirectory());
        $report = (new ConnectionTest(PluginRegistry::load(), $this->tempDirectory() . '/data'))->run($profile);

        self::assertFalse($report->passed());
        self::assertStringContainsString('not an installation of the website type "prestashop9"', $report->messages(\ThreadSafe\Profile\CheckLevel::Failure)[0]);
        self::assertSame('prestashop9' === $profile->type, true);
    }

    public function testConnectionTestWarnsAboutStorageInsideTheSiteAndRefusesAnUnwritableOne(): void
    {
        $site = $this->tempDirectory();
        $inside = (new ConnectionTest(PluginRegistry::load(), $this->tempDirectory() . '/data'))->run($this->profile('generic', $site, ['storage' => $site . '/backups']));
        self::assertTrue($inside->passed());
        self::assertStringContainsString('inside the site', implode("\n", $inside->messages(\ThreadSafe\Profile\CheckLevel::Warning)));

        $file = $this->tempDirectory() . '/a-file';
        file_put_contents($file, 'x');
        $blocked = (new ConnectionTest(PluginRegistry::load(), $this->tempDirectory() . '/data'))->run($this->profile('generic', $site, ['storage' => $file . '/sub']));
        self::assertFalse($blocked->passed());
        self::assertStringContainsString('is not a directory', implode("\n", $blocked->messages(\ThreadSafe\Profile\CheckLevel::Failure)));
    }
}
