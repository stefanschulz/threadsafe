<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Web;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Crypto\AgeKeys;
use ThreadSafe\Profile\ProfileException;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Tests\Support\WordPressSite;
use ThreadSafe\Web\AgentPlugin;
use ThreadSafe\Web\AgentSetup;
use ThreadSafe\Web\BackupWorkspace;

/**
 * What the WordPress plugin asks of the tool (docs/PHASE13.md 9.12): the workspace outside the plugin directory and the pointer that names it, which is
 * also the switch of the plugin; the profile that the plugin makes from the site and from three fields; and what the page shows.
 */
final class AgentPluginTest extends TestCase
{
    use TempDirectory;

    private string $site;

    private string $plugin;

    private string $uploads;

    protected function setUp(): void
    {
        $this->site = (string) realpath($this->tempDirectory()) . '/site';
        mkdir($this->site, 0777, true);
        WordPressSite::build($this->site);
        $this->plugin = $this->site . '/wp-content/plugins/threadsafe-connector';
        $this->uploads = $this->site . '/wp-content/uploads';
        mkdir($this->plugin, 0777, true);
        mkdir($this->uploads, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    private function plugin(): AgentPlugin
    {
        return new AgentPlugin($this->site, $this->site . '/wp-content', $this->plugin);
    }

    private static function key(): string
    {
        return AgeKeys::generate()['public'];
    }

    public function testActivationMakesAWorkspaceInTheUploadsAndWritesThePointerThatNamesIt(): void
    {
        $workspace = $this->plugin()->activate($this->uploads);

        self::assertMatchesRegularExpression('~/wp-content/uploads/threadsafe-[0-9a-f]{16}$~', $workspace->directory());
        self::assertDirectoryExists((string) $workspace->existingData());
        self::assertDirectoryExists((string) $workspace->existingStorage());
        self::assertFileExists($workspace->directory() . '/.htaccess', 'the directory is closed to the web');
        self::assertSame($workspace->directory(), $this->plugin()->workspacePath());
        self::assertTrue($this->plugin()->isOn());
        $pointer = (string) file_get_contents($this->site . '/wp-content/threadsafe-agent.php');
        self::assertStringStartsWith("<?php http_response_code(404); exit; ?>\n", $pointer, 'fetched through the web the file prints nothing');
        if (DIRECTORY_SEPARATOR === '/') {
            self::assertSame(0600, fileperms($this->site . '/wp-content/threadsafe-agent.php') & 0777);
        }
    }

    public function testAnotherActivationFindsTheWorkspaceAgain(): void
    {
        $first = $this->plugin()->activate($this->uploads);
        file_put_contents((string) $first->existingData() . '/marker', 'the pairing is in here');
        $this->plugin()->deactivate();
        self::assertFalse($this->plugin()->isOn(), 'a pointer that is off makes the entry file answer nobody');
        self::assertSame($first->directory(), $this->plugin()->workspacePath(), 'but the path stays');

        $second = $this->plugin()->activate($this->uploads);
        self::assertSame($first->directory(), $second->directory(), 'an update or a reactivation keeps the archives and the pairing');
        self::assertFileExists((string) $second->existingData() . '/marker');
        self::assertCount(1, glob($this->uploads . '/threadsafe-*') ?: []);
    }

    public function testADamagedForeignOrOpenPointerIsRefused(): void
    {
        $file = $this->site . '/wp-content/threadsafe-agent.php';
        $good = AgentPlugin::pointerSource($this->uploads . '/threadsafe-1');
        foreach ([
            'no prefix' => '{"workspace":"' . $this->uploads . '/threadsafe-1"}',
            'not json' => AgentPlugin::POINTER_PREFIX . 'garbage',
            'no workspace' => AgentPlugin::POINTER_PREFIX . '{"other":1}',
            'a stream wrapper' => AgentPlugin::pointerSource('phar:///tmp/x.phar'),
            'a relative path' => AgentPlugin::pointerSource('uploads/threadsafe-1'),
            'a path with ..' => AgentPlugin::pointerSource($this->uploads . '/../threadsafe-1'),
            'inside the plugin' => AgentPlugin::pointerSource($this->plugin . '/state'),
        ] as $why => $content) {
            file_put_contents($file, $content);
            chmod($file, 0600);
            self::assertNull($this->plugin()->workspacePath(), $why);
        }

        file_put_contents($file, $good);
        chmod($file, 0600);
        self::assertSame($this->uploads . '/threadsafe-1', $this->plugin()->workspacePath());
        if (DIRECTORY_SEPARATOR === '/') {
            chmod($file, 0644);
            self::assertNull($this->plugin()->workspacePath(), 'a pointer that others can read is refused');
        }
    }

    public function testTheWorkspaceInsideThePluginDirectoryIsRefusedOnActivation(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('replaces that directory');

        $this->plugin()->activate($this->plugin);
    }

    public function testSavingMakesTheProfileOfTheSiteWithTheKeysAndLeavesOutWhatMustNotTravel(): void
    {
        $workspace = $this->plugin()->activate($this->uploads);
        $key = self::key();

        $this->plugin()->save($workspace, $key . "\n", "/wp-content/backups/\n  \n*.log", '250M');

        $profile = $workspace->profiles()->load(BackupWorkspace::PROFILE);
        self::assertSame('wordpress', $profile->type);
        self::assertSame($this->site, $profile->path);
        self::assertSame(262144000, $profile->partSize);
        self::assertSame($workspace->existingStorage(), $profile->storage);
        self::assertSame([$key], $profile->encryption?->recipients);
        self::assertContains('/wp-content/plugins/threadsafe-connector/agent.php', $profile->excludePaths, 'a copy of the site must not carry the door');
        self::assertContains('/wp-content/threadsafe-agent.php', $profile->excludePaths, 'nor the pointer to the workspace');
        self::assertContains('/wp-content/plugins/threadsafe-connector/threadsafe-backup.php', $profile->excludePaths);
        self::assertContains('/wp-content/uploads/' . basename($workspace->directory()) . '/', $profile->excludePaths, 'the workspace inside the site is left out');
        self::assertContains('/wp-content/backups/', $profile->excludePaths);
        self::assertContains('*.log', $profile->excludePaths);
        self::assertSame(['recipients' => [$key], 'exclude' => ['/wp-content/backups/', '*.log'], 'partSize' => 262144000], $this->plugin()->settings($workspace));
    }

    public function testSavingAgainGivesAPairedAgentItsCopyOfTheSettings(): void
    {
        $workspace = $this->plugin()->activate($this->uploads);
        $this->plugin()->save($workspace, self::key(), '', '');
        $setup = new AgentSetup($workspace);
        $setup->pair($workspace->profiles()->load(BackupWorkspace::PROFILE), 'customer', 'https://shop.example/wp-content/plugins/threadsafe-connector/agent.php');
        $second = self::key();

        $this->plugin()->save($workspace, $second, '/cache/', '');

        $copy = $workspace->profiles()->load('customer');
        self::assertSame([$second], $copy->encryption?->recipients);
        self::assertContains('/cache/', $copy->excludePaths);
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function badInput(): iterable
    {
        yield 'no key' => ['', '', '', 'at least one public key'];
        yield 'a secret key' => ['AGE-SECRET-KEY-1QQQQ', '', '', 'not a public key'];
        yield 'a bad pattern' => ['KEY', "/a/\n/b/../c", '', 'not valid'];
        yield 'a part that is too small' => ['KEY', '', '100K', 'at least 1M'];
        yield 'a part that is no size' => ['KEY', '', 'big', 'such as 500M'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badInput')]
    public function testWrongInputStoresNothing(string $recipients, string $exclude, string $partSize, string $message): void
    {
        $workspace = $this->plugin()->activate($this->uploads);
        $recipients = str_replace('KEY', self::key(), $recipients);

        try {
            $this->plugin()->save($workspace, $recipients, $exclude, $partSize);
            self::fail('the input should have been refused');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
        self::assertFalse($workspace->profiles()->exists(BackupWorkspace::PROFILE));
        self::assertSame([], $this->plugin()->settings($workspace)['recipients']);
    }

    public function testADirectoryThatIsNoWordPressSiteIsRefused(): void
    {
        $workspace = $this->plugin()->activate($this->uploads);
        $plain = (string) realpath($this->tempDirectory()) . '/plain';
        mkdir($plain . '/wp-content/plugins/threadsafe-connector', 0777, true);
        file_put_contents($plain . '/index.php', '<?php');
        $plugin = new AgentPlugin($plain, $plain . '/wp-content', $plain . '/wp-content/plugins/threadsafe-connector');

        $this->expectException(ProfileException::class);
        $this->expectExceptionMessage('not recognised as a WordPress site');

        $plugin->save($workspace, self::key(), '', '');
    }

    public function testTheStateCountsTheBackupsOnTheHost(): void
    {
        $workspace = $this->plugin()->activate($this->uploads);
        self::assertSame(0, $this->plugin()->state($workspace)['backups']);
        self::assertNull($this->plugin()->state($workspace)['newest']);

        $storage = (string) $workspace->existingStorage();
        file_put_contents($storage . '/customer-2026-11-17.json', '{}');
        touch($storage . '/customer-2026-11-17.json', 1_700_000_000);
        file_put_contents($storage . '/customer-2026-11-16.json', '{}');
        touch($storage . '/customer-2026-11-16.json', 1_600_000_000);

        $state = $this->plugin()->state($workspace);
        self::assertSame(2, $state['backups']);
        self::assertSame(1_700_000_000, $state['newest']);
        self::assertSame($workspace->directory(), $state['directory']);
        self::assertTrue($state['free'] === null || $state['free'] > 0);
    }

    public function testTheProbeNamesTheAddressesOfItsFilesAndEndsWithTheirRemoval(): void
    {
        $workspace = $this->plugin()->activate($this->uploads);
        $name = basename($workspace->directory());

        $probe = $this->plugin()->probe($workspace, $this->uploads, 'https://shop.example/wp-content/uploads/');

        self::assertNotNull($probe);
        self::assertCount(3, $probe['urls'], 'the workspace, its data directory and its storage');
        self::assertStringStartsWith('https://shop.example/wp-content/uploads/' . $name . '/', $probe['urls'][0]);
        foreach ($probe['urls'] as $url) {
            $file = $this->uploads . substr($url, strlen('https://shop.example/wp-content/uploads'));
            self::assertStringContainsString($probe['token'], (string) file_get_contents($file));
        }
        self::assertSame($probe['token'], $this->plugin()->probe($workspace, $this->uploads, 'https://shop.example/wp-content/uploads')['token'] ?? null, 'the same files are used until they are removed');

        $this->plugin()->endProbe($workspace);
        foreach ($probe['urls'] as $url) {
            self::assertFileDoesNotExist($this->uploads . substr($url, strlen('https://shop.example/wp-content/uploads')));
        }
    }

    public function testAWorkspaceOutsideTheUploadsCannotBeProbed(): void
    {
        $elsewhere = (string) realpath($this->tempDirectory()) . '/elsewhere';
        mkdir($elsewhere);
        $workspace = $this->plugin()->activate($elsewhere);

        self::assertNull($this->plugin()->probe($workspace, $this->uploads, 'https://shop.example/wp-content/uploads'));
    }

    public function testUninstallKeepsTheArchivesUnlessAskedToRemoveThem(): void
    {
        $workspace = $this->plugin()->activate($this->uploads);
        $storage = (string) $workspace->existingStorage();
        file_put_contents($storage . '/customer.zip', 'an archive');

        $this->plugin()->uninstall(false);

        self::assertFileDoesNotExist($this->site . '/wp-content/threadsafe-agent.php');
        self::assertNull($workspace->existingData(), 'the state and the pairing go');
        self::assertFileExists($storage . '/customer.zip', 'the archives stay');

        $workspace = $this->plugin()->activate($this->uploads);
        $this->plugin()->uninstall(true);
        self::assertDirectoryDoesNotExist($workspace->directory());
    }
}
