<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\WordPress;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Backup\Filters;
use ThreadSafe\Database\DatabaseError;
use ThreadSafe\Database\DatabaseSettings;
use ThreadSafe\Plugin\PluginError;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Tests\Support\FakeConnection;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\WordPress\Backup\WordPressBackup;
use ThreadSafe\WordPress\WordPressType;

/**
 * The backup side of the WordPress type: detection from the files (also with `wp-config.php` one directory above), the database access from
 * it, the refusal of a multisite, the default filters and the facts that go into the manifest (docs/PHASE9.md 3.3 and 3.4).
 */
final class WordPressBackupTest extends TestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/wordpress/' . $name . '.php');
    }

    /**
     * A directory that looks like an installed WordPress.
     *
     * @param string|null $version       the version in `version.php`; null: the real file of 6.9.4
     * @param string|null $configuration the content of `wp-config.php`; null: the real file of the spikes; '' none
     */
    private function site(?string $version = null, ?string $configuration = null, ?string $root = null): string
    {
        $root ??= $this->tempDirectory();
        mkdir($root . '/wp-includes', 0777, true);
        mkdir($root . '/wp-admin', 0777, true);
        mkdir($root . '/wp-content/plugins', 0777, true);
        file_put_contents($root . '/wp-includes/version.php', $version === null ? self::fixture('version-6.9.4') : "<?php\n\$wp_version = '$version';\n\$wp_db_version = 53496;\n");
        file_put_contents($root . '/wp-load.php', '<?php // loads WordPress');
        $configuration ??= self::fixture('wp-config-6.9.4-plugin-added-constants');
        if ($configuration !== '') {
            file_put_contents($root . '/wp-config.php', $configuration);
        }

        return $root;
    }

    /** @return iterable<string, array{string, string}> */
    public static function supported(): iterable
    {
        yield 'WordPress 6.0' => ['6.0', '6.0'];
        yield 'WordPress 6.9.4' => ['6.9.4', '6.9.4'];
        yield 'WordPress 7.1.4' => ['7.1.4', '7.1.4'];
        yield 'a release candidate' => ['7.2-RC1', '7.2-RC1'];
    }

    #[DataProvider('supported')]
    public function testWordPress6AndLaterAreRecognisedWithTheirVersion(string $in, string $version): void
    {
        $site = (new WordPressBackup())->detect($this->site($in));

        self::assertNotNull($site);
        self::assertSame('WordPress', $site->application);
        self::assertSame($version, $site->version);
        self::assertSame('WordPress ' . $version, $site->describe());
    }

    public function testTheRealVersionFilesOfTheSpikesAreRead(): void
    {
        foreach (['6.9.4', '7.1.4'] as $version) {
            $root = $this->site();
            file_put_contents($root . '/wp-includes/version.php', self::fixture('version-' . $version));

            self::assertSame($version, (new WordPressBackup())->detect($root)?->version);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function notSupported(): iterable
    {
        yield 'WordPress 5.9.2' => ['5.9.2'];
        yield 'WordPress 4.9.8' => ['4.9.8'];
    }

    #[DataProvider('notSupported')]
    public function testAnOlderWordPressIsNotAnInstallationOfTheType(string $version): void
    {
        self::assertNull((new WordPressBackup())->detect($this->site($version)));
    }

    public function testADirectoryThatIsNotWordPressIsNotRecognised(): void
    {
        $backup = new WordPressBackup();

        self::assertNull($backup->detect($this->tempDirectory()), 'an empty directory');
        self::assertNull($backup->detect($this->tempDirectory() . '/missing'), 'a directory that does not exist');

        $joomla = $this->tempDirectory();
        mkdir($joomla . '/libraries/src', 0777, true);
        file_put_contents($joomla . '/configuration.php', '<?php class JConfig {}');
        self::assertNull($backup->detect($joomla));

        $noVersion = $this->site();
        file_put_contents($noVersion . '/wp-includes/version.php', "<?php\n// no version\n");
        self::assertNull($backup->detect($noVersion), 'a version.php without the variable');
    }

    public function testASiteThatIsNotInstalledYetIsNotRecognised(): void
    {
        $root = $this->site(configuration: '');
        file_put_contents($root . '/wp-config-sample.php', self::fixture('wp-config-6.9.4-plugin-added-constants'));

        self::assertNull((new WordPressBackup())->detect($root), 'only wp-config-sample.php: the installation was not made');
    }

    // ------------------------------------------------------------------------------------------------- the configuration above the site

    public function testAConfigurationOneDirectoryAboveTheSiteIsFoundByTheRuleOfWordPress(): void
    {
        $parent = $this->tempDirectory();
        file_put_contents($parent . '/wp-config.php', self::fixture('wp-config-6.9.4-plugin-added-constants'));
        $root = $this->site(configuration: '', root: $parent . '/public');

        $site = (new WordPressBackup())->detect($root);

        self::assertNotNull($site);
        self::assertSame('above', $site->details['configLocation']);
        self::assertSame('test_db', (new WordPressBackup())->databaseSettings($root)->name);
    }

    public function testAConfigurationAboveIsNotUsedWhenTheDirectoryAboveIsAWordPressItself(): void
    {
        $parent = $this->tempDirectory();
        file_put_contents($parent . '/wp-config.php', self::fixture('wp-config-6.9.4-plugin-added-constants'));
        file_put_contents($parent . '/wp-settings.php', '<?php // another WordPress');
        $root = $this->site(configuration: '', root: $parent . '/public');

        self::assertNull((new WordPressBackup())->detect($root), 'WordPress itself would not use it');
    }

    public function testTheConfigurationInTheSiteWinsOverOneAbove(): void
    {
        $parent = $this->tempDirectory();
        file_put_contents($parent . '/wp-config.php', str_replace("'test_db'", "'above_db'", self::fixture('wp-config-6.9.4-plugin-added-constants')));
        $root = $this->site(root: $parent . '/public');

        $site = (new WordPressBackup())->detect($root);

        self::assertSame('root', $site?->details['configLocation']);
        self::assertSame('test_db', (new WordPressBackup())->databaseSettings($root)->name);
    }

    // ------------------------------------------------------------------------------------------------- what the files say

    public function testTheDetectionTellsWhatTheFilesSayButNeverASecret(): void
    {
        $root = $this->site();
        file_put_contents($root . '/wp-content/object-cache.php', '<?php // redis');
        file_put_contents($root . '/wp-content/advanced-cache.php', '<?php // cache');
        mkdir($root . '/wp-content/mu-plugins', 0777, true);
        file_put_contents($root . '/wp-content/mu-plugins/index.php', '<?php // silence');
        file_put_contents($root . '/wp-content/mu-plugins/force-login.php', '<?php // a must-use plugin');

        $site = (new WordPressBackup())->detect($root);

        self::assertNotNull($site);
        self::assertSame('60717', $site->details['dbVersion']);
        self::assertSame('wp_', $site->details['dbPrefix']);
        self::assertFalse($site->details['multisite']);
        self::assertFalse($site->details['homeConstant']);
        self::assertFalse($site->details['siteurlConstant']);
        self::assertSame(['advanced-cache.php', 'object-cache.php'], $site->details['dropIns'], 'in the order WordPress lists them');
        self::assertSame(['force-login.php'], $site->details['mustUsePlugins']);
        self::assertArrayNotHasKey('notUnderstood', $site->details, 'ABSPATH is computed in every wp-config.php: no news');
        $all = (string) json_encode($site->details);
        self::assertStringNotContainsString('test-password', $all);
        self::assertStringNotContainsString('test_user', $all);
    }

    public function testConstantsThatOverrideTheDatabaseAndPathsAreNamed(): void
    {
        $root = $this->tempDirectory();
        $config = str_replace('/var/www/html', $root, self::fixture('wp-config-6.9.4-plugin-added-constants'));
        $config = str_replace("define( 'DB_NAME'", "define( 'WP_HOME', 'http://old.example' );\ndefine( 'WP_SITEURL', 'http://old.example' );\ndefine( 'COMPUTED', getenv('X') );\ndefine( 'DB_NAME'", $config);
        $this->site(configuration: $config, root: $root);

        $site = (new WordPressBackup())->detect($root);

        self::assertNotNull($site);
        self::assertTrue($site->details['homeConstant']);
        self::assertTrue($site->details['siteurlConstant']);
        self::assertSame(['WPCACHEHOME'], $site->details['pathConstants'], 'a plugin wrote an absolute path: a restore must move it');
        self::assertSame(['COMPUTED'], $site->details['notUnderstood']);
    }

    public function testAConfigurationThatCannotBeReadIsReportedAndTheSiteIsStillRecognised(): void
    {
        $root = $this->site(configuration: "<?php\n// an empty file\n");

        $site = (new WordPressBackup())->detect($root);

        self::assertNotNull($site);
        self::assertNull($site->details['dbPrefix']);
        self::assertArrayNotHasKey('configurationWarning', $site->details, 'it parses; it only says nothing');
    }

    // ------------------------------------------------------------------------------------------------- the database access

    public function testTheDatabaseAccessComesFromTheConfigurationFile(): void
    {
        $settings = (new WordPressBackup())->databaseSettings($this->site());

        self::assertEquals(new DatabaseSettings('db', 3306, 'test_db', 'test_user', 'test-password', 'wp_'), $settings);
    }

    public function testAMultisiteIsRefusedWithTheWayToAPlainBackup(): void
    {
        $root = $this->site(configuration: self::fixture('wp-config-6.9.4-multisite'));
        $site = (new WordPressBackup())->detect($root);

        self::assertTrue($site?->details['multisite'], 'it is recognised');
        $this->expectException(PluginError::class);
        $this->expectExceptionMessage('multisite network, which ThreadSafe does not restore yet. Back it up as "Other website"');
        (new WordPressBackup())->databaseSettings($root);
    }

    public function testTheFileOfTheDockerImageIsRefusedWithoutAnyValue(): void
    {
        $root = $this->site(configuration: self::fixture('wp-config-docker-image'));

        try {
            (new WordPressBackup())->databaseSettings($root);
            self::fail('computed settings must be refused');
        } catch (PluginError $e) {
            self::assertStringContainsString('"DB_HOST" is computed by getenv_docker()', $e->getMessage());
            self::assertStringNotContainsString('example password', $e->getMessage());
        }
    }

    public function testASocketAndAMissingConfigurationAreSaid(): void
    {
        $socket = $this->site(configuration: str_replace("'db'", "'localhost:/var/run/mysqld/mysqld.sock'", self::fixture('wp-config-6.9.4-plugin-added-constants')));
        $none = $this->site(configuration: '');

        foreach ([[$socket, 'socket'], [$none, 'there is no wp-config.php']] as [$root, $fragment]) {
            try {
                (new WordPressBackup())->databaseSettings($root);
                self::fail('this must be refused');
            } catch (PluginError $e) {
                self::assertStringContainsString($fragment, $e->getMessage());
            }
        }
    }

    // ------------------------------------------------------------------------------------------------- the filters

    public function testTheDefaultFiltersLeaveOutWhatWordPressAndItsPluginsRebuild(): void
    {
        $backup = new WordPressBackup();
        $site = $backup->detect($this->site());
        self::assertNotNull($site);

        $defaults = $backup->defaultFilters($site);

        $filters = new Filters($defaults->excludePaths);
        foreach (['wp-content/cache', 'wp-content/upgrade', 'wp-content/wflogs', 'wp-content/et-cache', 'wp-content/ai1wm-backups', 'wp-content/updraft', 'wp-snapshots', '.git', 'wp-content/themes/x/.git'] as $left) {
            self::assertTrue($filters->excludes($left, true), "$left is left out");
        }
        foreach (['wp-content/debug.log', '.maintenance', 'error_log', 'wp-admin/error_log', 'wp-content/plugins/x/error_log'] as $left) {
            self::assertTrue($filters->excludes($left, false), "$left is left out");
        }
        self::assertTrue($filters->excludes('wp-content/backupwordpress-1a2b3c', true), 'a pattern with a wildcard');
        // the code and the data of the site stay: directories of the same names inside plugins and themes are code, and uploads are the site
        foreach (['wp-content/plugins/wp-super-cache/cache', 'wp-content/themes/x/cache', 'wp-content/uploads', 'wp-content/uploads/cache', 'wp-content/plugins', 'wp-content/mu-plugins', 'wp-admin/upgrade'] as $kept) {
            self::assertFalse($filters->excludes($kept, true), "$kept stays in");
        }
        self::assertFalse($filters->excludes('sub/.maintenance', false), '.maintenance only at the root');

        foreach (['woocommerce_sessions', 'actionscheduler_logs', 'wfhits', 'wflivetraffichuman', 'redirection_logs', 'redirection_404'] as $table) {
            self::assertContains($table, $defaults->structureOnlyTables, $table);
        }
        foreach (['options', 'posts', 'postmeta', 'users', 'usermeta', 'comments', 'woocommerce_order_items', 'actionscheduler_actions'] as $table) {
            self::assertNotContains($table, $defaults->structureOnlyTables, "$table keeps its data");
        }
    }

    // ------------------------------------------------------------------------------------------------- the manifest

    public function testTheManifestExtrasWithoutADatabaseAreTheFactsOfTheFiles(): void
    {
        $backup = new WordPressBackup();
        $site = $backup->detect($this->site());
        self::assertNotNull($site);

        $extras = $backup->manifestExtras($site, null);

        self::assertSame($site->details, $extras);
        self::assertNotFalse(json_encode($extras), 'plain data');
    }

    public function testTheManifestExtrasWithADatabaseHoldBothAddressesThePluginsAndTheTheme(): void
    {
        $db = FakeConnection::with([
            '~FROM `wp_options` WHERE option_name IN~' => [
                ['option_name' => 'siteurl', 'option_value' => 'https://old.example/wp'],
                ['option_name' => 'home', 'option_value' => 'https://old.example'],
                ['option_name' => 'blogname', 'option_value' => 'My site'],
                ['option_name' => 'blog_public', 'option_value' => '0'],
                ['option_name' => 'db_version', 'option_value' => '60717'],
                ['option_name' => 'template', 'option_value' => 'twentytwentyfive'],
                ['option_name' => 'stylesheet', 'option_value' => 'my-child'],
                ['option_name' => 'active_plugins', 'option_value' => 'a:2:{i:0;s:19:"akismet/akismet.php";i:1;s:27:"woocommerce/woocommerce.php";}'],
                ['option_name' => 'permalink_structure', 'option_value' => '/%postname%/'],
                ['option_name' => 'WPLANG', 'option_value' => 'de_DE'],
            ],
            '~information_schema~' => [['n' => '0']],
        ], 'wp_');
        $backup = new WordPressBackup();
        $site = $backup->detect($this->site());
        self::assertNotNull($site);

        $extras = $backup->manifestExtras($site, $db);

        self::assertSame('https://old.example/wp', $extras['siteUrl']);
        self::assertSame('https://old.example', $extras['homeUrl'], 'the two can differ (WordPress in its own directory)');
        self::assertSame('My site', $extras['siteName']);
        self::assertFalse($extras['searchEngines']);
        self::assertSame('60717', $extras['dbVersionInDatabase']);
        self::assertSame(['template' => 'twentytwentyfive', 'stylesheet' => 'my-child'], $extras['theme']);
        self::assertSame(['akismet/akismet.php', 'woocommerce/woocommerce.php'], $extras['activePlugins']);
        self::assertSame('/%postname%/', $extras['permalinks']);
        self::assertSame('de_DE', $extras['language']);
        self::assertFalse($extras['multisiteTables']);
        self::assertSame('wp_', $extras['dbPrefix'], 'the facts of the files are still there');
        self::assertNotFalse(json_encode($extras), 'plain data');
    }

    public function testADatabaseThatAnswersWithAnErrorIsReportedAndNothingFails(): void
    {
        $db = FakeConnection::with(['~never matches~' => []], 'wp_');
        $backup = new WordPressBackup();
        $site = $backup->detect($this->site());
        self::assertNotNull($site);

        $extras = $backup->manifestExtras($site, $db);

        self::assertStringContainsString('Could not read the site data from the database', (string) $extras['extrasWarning']);
        self::assertArrayNotHasKey('siteUrl', $extras);
        self::assertInstanceOf(DatabaseError::class, new DatabaseError('x'));
    }

    // ------------------------------------------------------------------------------------------------- the type

    public function testTheTypeIsRegisteredAndHasTheStepsOfTheAdaptation(): void
    {
        $registry = PluginRegistry::load();

        self::assertTrue($registry->has('wordpress'));
        $type = $registry->get('wordpress');
        self::assertInstanceOf(WordPressType::class, $type);
        self::assertSame('WordPress', $type->title());
        self::assertSame($type, $registry->backupable('wordpress'));
        self::assertNotNull($type->detect($this->site()), 'the type answers for the backup side');

        self::assertSame($type, $registry->restorable('wordpress'));
        self::assertSame(
            ['ensure-configuration', 'read-source-state', 'ensure-skeleton', 'write-configuration', 'replace-content-domains', 'fix-table-prefix', 'rewrite-htaccess', 'discard-caches', 'apply-dev-mode', 'self-test'],
            array_map(static fn (\ThreadSafe\Core\Step $step): string => $step->id(), $type->steps()),
        );
    }
}
