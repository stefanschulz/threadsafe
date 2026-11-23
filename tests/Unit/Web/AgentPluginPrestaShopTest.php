<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Web;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Crypto\AgeKeys;
use ThreadSafe\Profile\ProfileException;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Web\AgentPlugin;
use ThreadSafe\Web\BackupWorkspace;

/**
 * What the PrestaShop module `threadsafeconnector` asks of the tool (docs/PHASE15.md 3.5): the same class as the WordPress plugin, with the module directory
 * and `modules` where the plugin has its directory and `wp-content`, and the workspace in `var`.
 */
final class AgentPluginPrestaShopTest extends TestCase
{
    use TempDirectory;

    private string $shop;

    private string $module;

    private string $var;

    protected function setUp(): void
    {
        $this->shop = (string) realpath($this->tempDirectory()) . '/shop';
        $this->module = $this->shop . '/modules/threadsafeconnector';
        $this->var = $this->shop . '/var';
        foreach ([$this->module, $this->var, $this->shop . '/src/Core', $this->shop . '/app/config', $this->shop . '/admin-x'] as $directory) {
            mkdir($directory, 0777, true);
        }
        file_put_contents($this->shop . '/src/Core/Version.php', "<?php\nfinal class Version\n{\n    public const VERSION = '9.0.2';\n}\n");
        file_put_contents($this->shop . '/app/config/parameters.php', '<?php return ' . var_export(['parameters' => [
            'database_host' => 'db',
            'database_port' => '3306',
            'database_name' => 'shop',
            'database_user' => 'shop',
            'database_password' => 'not-a-real-password',
            'database_prefix' => 'ps_',
            'secret' => 'FAKE-SECRET',
        ]], true) . ';');
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    private function module(): AgentPlugin
    {
        return new AgentPlugin($this->shop, $this->shop . '/modules', $this->module, null, 'threadsafe-backup.php', 'prestashop9');
    }

    public function testActivationMakesTheWorkspaceInVarAndWritesThePointerInModules(): void
    {
        $workspace = $this->module()->activate($this->var);

        self::assertMatchesRegularExpression('~/var/threadsafe-[0-9a-f]{16}$~', $workspace->directory());
        self::assertSame($workspace->directory(), $this->module()->workspacePath());
        self::assertTrue($this->module()->isOn());
        self::assertFileExists($this->shop . '/modules/threadsafe-agent.php', 'where the door finds it: next to the module directory, which an update replaces');
        self::assertFileDoesNotExist($this->module . '/threadsafe-agent.php');
    }

    public function testSavingMakesTheProfileOfAPrestaShopAndLeavesOutWhatMustNotTravel(): void
    {
        $workspace = $this->module()->activate($this->var);
        $key = AgeKeys::generate()['public'];

        $this->module()->save($workspace, $key, '*.log', '250M');

        $profile = $workspace->profiles()->load(BackupWorkspace::PROFILE);
        self::assertSame('prestashop9', $profile->type);
        self::assertSame($this->shop, $profile->path);
        self::assertSame([$key], $profile->encryption?->recipients);
        self::assertContains('/modules/threadsafeconnector/agent.php', $profile->excludePaths, 'a copy of the shop must not carry the door');
        self::assertContains('/modules/threadsafe-agent.php', $profile->excludePaths, 'nor the pointer to the workspace');
        self::assertContains('/var/' . basename($workspace->directory()) . '/', $profile->excludePaths, 'the workspace inside the shop is left out');
        self::assertContains('*.log', $profile->excludePaths);
    }

    public function testAnotherWebsiteTypeIsRefusedWithTheNameOfTheRightOne(): void
    {
        $workspace = $this->module()->activate($this->var);
        unlink($this->shop . '/src/Core/Version.php');

        $this->expectException(ProfileException::class);
        $this->expectExceptionMessage('not recognised as a PrestaShop site');

        $this->module()->save($workspace, AgeKeys::generate()['public'], '', '');
    }

    public function testAFileWithoutADatabaseAccessIsNamedInTheMessage(): void
    {
        $workspace = $this->module()->activate($this->var);
        file_put_contents($this->shop . '/app/config/parameters.php', '<?php return [];');

        $this->expectException(ProfileException::class);
        $this->expectExceptionMessageMatches('~app/config/parameters\.php|database~');

        $this->module()->save($workspace, AgeKeys::generate()['public'], '', '');
    }

    public function testTheArchivesOfWordPressBackupPluginsAreNotLookedForInAShop(): void
    {
        $workspace = $this->module()->activate($this->var);
        mkdir($this->shop . '/modules/updraft', 0777, true);

        self::assertSame([], $this->module()->otherBackups($workspace));
    }

    public function testUninstallRemovesThePointerAndKeepsTheArchivesUnlessAsked(): void
    {
        $workspace = $this->module()->activate($this->var);
        $storage = (string) $workspace->existingStorage();
        file_put_contents($storage . '/customer.zip', 'an archive');

        $this->module()->uninstall(false);

        self::assertFileDoesNotExist($this->shop . '/modules/threadsafe-agent.php');
        self::assertFileExists($storage . '/customer.zip');
    }
}
