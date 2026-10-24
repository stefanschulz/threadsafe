<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Joomla;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Database\DatabaseSettings;
use ThreadSafe\Joomla\Backup\JoomlaBackup;
use ThreadSafe\Plugin\PluginError;
use ThreadSafe\Tests\Support\FakeConnection;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * The backup side of the Joomla type: detection from the files, the database access from `configuration.php`, the default filters
 * and the facts that go into the manifest.
 */
final class JoomlaBackupTest extends TestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    /**
     * A directory that looks like an installed Joomla.
     */
    private function site(string $major = '5', string $minor = '4', string $patch = '8', string $extra = '', bool $installed = true, ?string $configuration = null): string
    {
        $root = $this->tempDirectory();
        mkdir($root . '/libraries/src', 0777, true);
        file_put_contents($root . '/libraries/src/Version.php', "<?php\nnamespace Joomla\\CMS;\nfinal class Version\n{\n    public const MAJOR_VERSION = $major;\n    public const MINOR_VERSION = $minor;\n    public const PATCH_VERSION = $patch;\n    public const EXTRA_VERSION = '$extra';\n}\n");
        if ($installed) {
            mkdir($root . '/administrator', 0777, true);
            file_put_contents($root . '/configuration.php', $configuration ?? (string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/joomla/configuration-5.4.php'));
        }

        return $root;
    }

    /** @return iterable<string, array{string, string, string, string, string}> */
    public static function supported(): iterable
    {
        yield 'Joomla 5.4.8' => ['5', '4', '8', '', '5.4.8'];
        yield 'Joomla 5.0.0' => ['5', '0', '0', '', '5.0.0'];
        yield 'Joomla 6.2.0' => ['6', '2', '0', '', '6.2.0'];
        yield 'a release candidate' => ['6', '3', '0', 'rc1', '6.3.0-rc1'];
    }

    #[DataProvider('supported')]
    public function testJoomla5And6AreRecognisedWithTheirVersion(string $major, string $minor, string $patch, string $extra, string $version): void
    {
        $site = (new JoomlaBackup())->detect($this->site($major, $minor, $patch, $extra));

        self::assertNotNull($site);
        self::assertSame('Joomla', $site->application);
        self::assertSame($version, $site->version);
        self::assertSame('Joomla ' . $version, $site->describe());
    }

    /** @return iterable<string, array{string}> */
    public static function notSupported(): iterable
    {
        yield 'Joomla 4' => ['4'];
        yield 'Joomla 3' => ['3'];
        yield 'a Joomla of the future' => ['7'];
    }

    #[DataProvider('notSupported')]
    public function testOtherMajorVersionsAreNotAnInstallationOfTheType(string $major): void
    {
        self::assertNull((new JoomlaBackup())->detect($this->site($major)));
    }

    public function testADirectoryThatIsNotJoomlaIsNotRecognised(): void
    {
        $backup = new JoomlaBackup();

        self::assertNull($backup->detect($this->tempDirectory()), 'an empty directory');
        self::assertNull($backup->detect($this->tempDirectory() . '/missing'), 'a directory that does not exist');

        $wordpress = $this->tempDirectory();
        mkdir($wordpress . '/wp-includes', 0777, true);
        file_put_contents($wordpress . '/wp-config.php', '<?php // wordpress');
        self::assertNull($backup->detect($wordpress));

        $noVersion = $this->site();
        file_put_contents($noVersion . '/libraries/src/Version.php', "<?php\nfinal class Version {}\n");
        self::assertNull($backup->detect($noVersion), 'a Version.php without the constants');
    }

    public function testASiteThatIsNotInstalledYetIsNotRecognised(): void
    {
        self::assertNull((new JoomlaBackup())->detect($this->site(installed: false)), 'no configuration.php: the installation was not finished');
    }

    public function testTheDetectionTellsWhatTheConfigurationSaysButNeverASecret(): void
    {
        $site = (new JoomlaBackup())->detect($this->site());

        self::assertNotNull($site);
        self::assertSame('Test site', $site->detail('siteName'));
        self::assertSame('mysqli', $site->detail('dbType'));
        self::assertSame('joom_', $site->detail('dbPrefix'));
        self::assertSame('', $site->detail('liveSite'));
        self::assertFalse($site->details['offline']);
        self::assertTrue($site->details['sef']);
        self::assertFalse($site->details['sefRewrite']);
        $all = (string) json_encode($site->details);
        self::assertStringNotContainsString('test-db-password', $all);
        self::assertStringNotContainsString('test0123456789abc', $all);
        self::assertSame(['siteName', 'dbType', 'dbPrefix', 'liveSite', 'offline', 'sef', 'sefRewrite'], array_keys($site->details));
    }

    public function testAConfigurationThatCannotBeReadIsReportedAndTheSiteIsStillRecognised(): void
    {
        $site = (new JoomlaBackup())->detect($this->site(configuration: "<?php\n\$config = [];\n"));

        self::assertNotNull($site);
        self::assertArrayHasKey('configurationWarning', $site->details);
    }

    public function testPropertiesThatAreNotLiteralsAreListed(): void
    {
        $site = (new JoomlaBackup())->detect($this->site(configuration: "<?php\nclass JConfig {\n\tpublic \$sitename = 'S';\n\tpublic \$tmp_path = JPATH_ROOT . '/tmp';\n}\n"));

        self::assertNotNull($site);
        self::assertSame(['tmp_path'], $site->details['notUnderstood']);
    }

    public function testTheDatabaseAccessComesFromTheConfigurationFile(): void
    {
        $settings = (new JoomlaBackup())->databaseSettings($this->site());

        self::assertEquals(new DatabaseSettings('db', 3306, 'joomla_test', 'joomla', 'test-db-password', 'joom_'), $settings);
    }

    public function testADatabaseThatCannotBeUsedIsAPluginErrorWithoutAnyValue(): void
    {
        $root = $this->site(configuration: "<?php\nclass JConfig {\n\tpublic \$dbtype = 'pgsql';\n\tpublic \$password = 'sekrit-value';\n}\n");

        try {
            (new JoomlaBackup())->databaseSettings($root);
            self::fail('PostgreSQL is not supported');
        } catch (PluginError $e) {
            self::assertStringContainsString('pgsql', $e->getMessage());
            self::assertStringNotContainsString('sekrit-value', $e->getMessage());
        }
    }

    public function testTheDefaultFiltersLeaveOutWhatJoomlaRebuilds(): void
    {
        $backup = new JoomlaBackup();
        $site = $backup->detect($this->site());
        self::assertNotNull($site);

        $defaults = $backup->defaultFilters($site);

        foreach (['cache', 'administrator/cache', 'tmp', 'administrator/logs', 'logs', '.git', 'administrator/components/com_akeebabackup/backup'] as $path) {
            self::assertContains($path, $defaults->excludePaths, $path);
        }
        foreach (['session', 'user_keys', 'updates', 'finder_links', 'finder_links_terms', 'finder_logging', 'finder_taxonomy_map', 'finder_terms', 'finder_tokens', 'finder_tokens_aggregate'] as $table) {
            self::assertContains($table, $defaults->structureOnlyTables, $table);
        }
        // what must keep its data: the ROOT row of the taxonomy (the indexer stops without it), the lookups, and the audit trail
        foreach (['finder_taxonomy', 'finder_types', 'finder_filters', 'finder_terms_common', 'action_logs', 'users', 'content', 'extensions'] as $table) {
            self::assertNotContains($table, $defaults->structureOnlyTables, $table);
        }
        self::assertNotContains('images', $defaults->excludePaths, 'the media of the site are part of it');
    }

    public function testTheManifestExtrasWithoutADatabaseAreTheFactsOfTheConfiguration(): void
    {
        $backup = new JoomlaBackup();
        $site = $backup->detect($this->site());
        self::assertNotNull($site);

        $extras = $backup->manifestExtras($site, null);

        self::assertSame($site->details, $extras);
        self::assertNotFalse(json_encode($extras), 'plain data');
    }

    public function testTheManifestExtrasWithADatabaseListThirdPartyExtensionsTemplatesLanguagesAndAddresses(): void
    {
        $core = static fn (string $type, string $element, string $folder = '', string $version = '5.4.8'): array => [
            'type' => $type, 'element' => $element, 'folder' => $folder, 'client_id' => '0', 'enabled' => '1', 'locked' => '1',
            'manifest_cache' => json_encode(['name' => $element, 'version' => $version, 'author' => 'Joomla! Project']),
        ];
        $db = FakeConnection::with([
            '~FROM `joom_extensions`~' => [
                $core('file', 'joomla'),
                $core('component', 'com_content'),
                $core('plugin', 'system', 'system'),
                ['type' => 'component', 'element' => 'com_shop', 'folder' => '', 'client_id' => '1', 'enabled' => '1', 'locked' => '0', 'manifest_cache' => json_encode(['name' => 'Shop', 'version' => '2.1.0', 'author' => 'Example Ltd'])],
                ['type' => 'plugin', 'element' => 'seo', 'folder' => 'system', 'client_id' => '0', 'enabled' => '0', 'locked' => '0', 'manifest_cache' => 'not json'],
                // shipped with Joomla, made by others (the editors, a library): locked, so core
                ['type' => 'plugin', 'element' => 'tinymce', 'folder' => 'editors', 'client_id' => '0', 'enabled' => '1', 'locked' => '1', 'manifest_cache' => json_encode(['name' => 'plg_editors_tinymce', 'version' => '8.9.1', 'author' => 'Tiny Technologies, Inc'])],
            ],
            '~FROM `joom_languages`~' => [['lang_code' => 'en-GB', 'title' => 'English', 'published' => '1'], ['lang_code' => 'de-DE', 'title' => 'Deutsch', 'published' => '0']],
            '~FROM `joom_template_styles`~' => [
                ['client_id' => '0', 'template' => 'cassiopeia', 'home' => '1', 'title' => 'Cassiopeia - Default'],
                ['client_id' => '1', 'template' => 'atum', 'home' => '1', 'title' => 'Atum - Default'],
            ],
            '~information_schema~' => [['n' => '1']],
            '~LEFT\(`introtext`, \d+\) AS value FROM `joom_content`~' => [['value' => '<a href="https://www.example.org/a">x</a> https://www.example.org/b']],
            '~LEFT\(~' => [],
        ], 'joom_');
        $backup = new JoomlaBackup();
        $site = $backup->detect($this->site());
        self::assertNotNull($site);

        $extras = $backup->manifestExtras($site, $db);

        self::assertSame('5.4.8', $extras['versionInDatabase']);
        self::assertSame(4, $extras['coreExtensions'], 'the core ones are counted, not listed (also the locked ones of other authors)');
        self::assertCount(2, $extras['extensions']);
        self::assertSame(['type' => 'component', 'element' => 'com_shop', 'folder' => '', 'client' => 'administrator', 'name' => 'Shop', 'version' => '2.1.0', 'author' => 'Example Ltd', 'enabled' => true], $extras['extensions'][0]);
        self::assertSame(['type' => 'plugin', 'element' => 'seo', 'folder' => 'system', 'client' => 'site', 'name' => null, 'version' => null, 'author' => null, 'enabled' => false], $extras['extensions'][1], 'a manifest that is not JSON does not stop the list');
        self::assertSame([['code' => 'en-GB', 'title' => 'English', 'published' => true], ['code' => 'de-DE', 'title' => 'Deutsch', 'published' => false]], $extras['languages']);
        self::assertSame('administrator', $extras['templates'][1]['client']);
        self::assertTrue($extras['templates'][0]['default']);
        self::assertSame([['host' => 'www.example.org', 'count' => 2]], $extras['addressCandidates']);
        self::assertSame('Test site', $extras['siteName'], 'the facts of the configuration stay');
        self::assertNotFalse(json_encode($extras));
    }

    public function testADatabaseThatAnswersWithAnErrorIsReportedNotThrown(): void
    {
        $db = FakeConnection::with([]);   // every query is an error
        $backup = new JoomlaBackup();
        $site = $backup->detect($this->site());
        self::assertNotNull($site);

        $extras = $backup->manifestExtras($site, $db);

        self::assertArrayHasKey('extrasWarning', $extras);
        self::assertSame('Test site', $extras['siteName']);
    }
}
