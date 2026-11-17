<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Satellites;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Web\AgentPlugin;

/**
 * The door of the WordPress plugin (`satellites/wordpress/threadsafe-connector/agent.php`, docs/PHASE13.md 9.12): it runs without WordPress, reads the
 * pointer file in wp-content and hands over to the tool only if the plugin is switched on. Everything else is answered like a file that is not there. It is
 * run as a real PHP process, in the directory layout of a site, with a stand-in for the tool that says what it was handed.
 */
final class WordPressEntryFileTest extends TestCase
{
    use TempDirectory;

    private string $content;

    private string $plugin;

    protected function setUp(): void
    {
        $this->content = (string) realpath($this->tempDirectory()) . '/wp-content';
        $this->plugin = $this->content . '/plugins/threadsafe-connector';
        mkdir($this->plugin, 0777, true);
        copy(dirname(__DIR__, 3) . '/satellites/wordpress/threadsafe-connector/agent.php', $this->plugin . '/agent.php');
        file_put_contents($this->plugin . '/threadsafe-backup.php', '<?php echo "TOOL " . (defined("THREADSAFE_WORKSPACE") ? THREADSAFE_WORKSPACE : "without a workspace");');
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    /**
     * @return array{string, string} what the process printed and its exit code as text
     */
    private function ask(string $query = 'status', bool $withLog = false): array
    {
        $code = $query === '' ? '' : '$_GET = ["api" => ' . var_export($query, true) . '];';
        $output = [];
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -d log_errors=0 -r ' . escapeshellarg($code . 'require ' . var_export($this->plugin . '/agent.php', true) . ';') . ($withLog ? ' 2>&1' : ' 2>' . (DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null')), $output, $status);

        return [implode("\n", $output), (string) $status];
    }

    private function pointer(string $source, int $mode = 0600): void
    {
        $file = $this->content . '/threadsafe-agent.php';
        file_put_contents($file, $source);
        chmod($file, $mode);
    }

    public function testAnAgentThatIsSwitchedOnHandsOverToTheToolWithTheWorkspaceOfThePointer(): void
    {
        $this->pointer(AgentPlugin::pointerSource('/srv/site/wp-content/uploads/threadsafe-abc'));

        self::assertSame(['TOOL /srv/site/wp-content/uploads/threadsafe-abc', '0'], $this->ask());
    }

    public function testWithoutAnApiRequestThePageOfTheWizardIsNotShown(): void
    {
        $this->pointer(AgentPlugin::pointerSource('/srv/site/wp-content/uploads/threadsafe-abc'));

        self::assertSame(['Not found.', '0'], $this->ask(''), 'the tool would answer with its page "No access key yet", which tells a stranger what lies here');
    }

    public function testAMissingOrSwitchedOffPointerAnswersLikeAFileThatIsNotThere(): void
    {
        self::assertSame('Not found.', $this->ask()[0], 'no pointer: the plugin was never set up');

        $this->pointer(AgentPlugin::pointerSource('/srv/site/wp-content/uploads/threadsafe-abc', false));
        self::assertSame('Not found.', $this->ask()[0], 'the plugin is deactivated');
    }

    public function testADamagedOrForeignPointerIsRefused(): void
    {
        foreach (['<?php return ["workspace" => "/x"];', AgentPlugin::POINTER_PREFIX . 'garbage', AgentPlugin::POINTER_PREFIX . '{"on":true}', AgentPlugin::POINTER_PREFIX . '{"workspace":5,"on":true}'] as $source) {
            $this->pointer($source);
            self::assertSame('Not found.', $this->ask()[0], $source);
        }
    }

    public function testTheReasonOfARefusalGoesToTheErrorLogAndNeverToTheRequester(): void
    {
        $this->pointer(AgentPlugin::POINTER_PREFIX . 'garbage');

        self::assertSame('Not found.', $this->ask()[0]);
        self::assertStringContainsString('is damaged', $this->ask('status', true)[0]);
    }

    public function testAPointerThatOthersCanReadIsRefused(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('file modes');
        }
        $this->pointer(AgentPlugin::pointerSource('/srv/site/wp-content/uploads/threadsafe-abc'), 0644);

        self::assertSame('Not found.', $this->ask()[0]);
    }

    public function testAFileFetchedThroughTheWebPrintsNothingOfWhatItHolds(): void
    {
        $source = AgentPlugin::pointerSource('/srv/site/wp-content/uploads/threadsafe-abc');
        $this->pointer($source);
        $output = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->content . '/threadsafe-agent.php') . ' 2>&1', $output);

        self::assertSame([], $output, 'run as a script, the pointer ends at its first line');
    }

    public function testEveryPhpFileOfThePluginParses(): void
    {
        $root = dirname(__DIR__, 3) . '/satellites/wordpress/threadsafe-connector';
        foreach (['threadsafe-connector.php', 'agent.php', 'uninstall.php', 'includes/plugin.php', 'includes/boot.php'] as $file) {
            $output = [];
            exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($root . '/' . $file) . ' 2>&1', $output, $status);
            self::assertSame(0, $status, $file . ': ' . implode(' ', $output));
        }
    }
}
