<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use ThreadSafe\Build\BackupBuilder;
use ThreadSafe\Build\ConnectorBuilder;
use ThreadSafe\Crypto\AgeKeys;
use ThreadSafe\Web\AgentSignature;
use ThreadSafe\Tests\Support\CliProcess;
use ThreadSafe\Tests\Support\WordPressClient;
use ThreadSafe\Web\AgentPairing;

/**
 * The WordPress plugin `threadsafe-connector` (docs/PHASE13.md 9.12, M5.4) in a real WordPress 7 behind a real Apache with limits of 5 s and 64 MB: the ZIP
 * is installed and activated with WP-CLI (as the web server's user), the page under Tools is used through a logged-in browser (settings, pairing, the
 * switch), the central side makes a backup through the agent, and the plugin is deactivated, updated (the directory replaced) and uninstalled while the
 * pairing and the archives are watched. A real PHP below 8.4 (the WordPress 6.0 service) is asked at the door.
 *
 * The tests depend on each other, in file order.
 */
#[Group('connector')]
final class ConnectorStackTest extends IntegrationTestCase
{
    private const string SITE = '/wordpress7';

    private const string APACHE_VIEW = '/var/www/html';

    private const string PLUGIN = self::SITE . '/wp-content/plugins/threadsafe-connector';

    private const string POINTER = self::SITE . '/wp-content/threadsafe-agent.php';

    private const string PATH = '/wp-content/plugins/threadsafe-connector/agent.php';

    private const string URL = 'http://wordpress7' . self::PATH;

    private const string DIRECTORY = '/app/.cache/connector';

    private const string PROFILE = 'wp7c';

    private static string $public = '';

    private static string $secret = '';

    private static string $pairingFile = '';

    private static string $backup = '';

    protected function setUp(): void
    {
        parent::setUp();
        if (!is_file(self::SITE . '/wp-includes/version.php')) {
            self::markTestSkipped('The seeded WordPress 7 is not mounted at ' . self::SITE . ' (tests/stack/seed-wordpress.sh).');
        }
        if (self::shell('command -v age-keygen && command -v wp && command -v runuser')[0] !== 0) {
            self::markTestSkipped('age-keygen, wp and runuser are needed (the tools image has them).');
        }
    }

    /** @return array{int, string} */
    private static function shell(string $command): array
    {
        exec($command . ' 2>&1', $lines, $code);

        return [$code, implode("\n", $lines)];
    }

    /**
     * WP-CLI as the user of the web server, so that what it writes is what Apache can write over.
     *
     * @return array{int, string}
     */
    private static function wp(string $arguments, string $site = self::APACHE_VIEW): array
    {
        // the site as the web server sees it: the pointer names a path, and Apache must be able to use that path (this container mounts the files at /wordpress7)
        if (!is_link(self::APACHE_VIEW)) {
            self::shell('mkdir -p /var/www && rmdir ' . self::APACHE_VIEW . ' ; ln -sfn ' . self::SITE . ' ' . self::APACHE_VIEW);
        }

        return self::shell('runuser -u www-data -- env HOME=/tmp wp --path=' . escapeshellarg($site) . ' ' . $arguments);
    }

    private static function centralData(): string
    {
        return self::DIRECTORY . '/central/data';
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string, string}
     */
    private static function cli(array $arguments): array
    {
        putenv('THREADSAFE_DATA=' . self::centralData());
        try {
            return CliProcess::run($arguments, 900);
        } finally {
            putenv('THREADSAFE_DATA');
        }
    }

    /** @return array{int, string} status and body of a signed request to the agent, as the central side makes it */
    private static function signed(string $query, string $host = 'wordpress7', ?string $secret = null): array
    {
        $uri = self::PATH . '?' . $query;
        $headers = ['Connection: close'];
        foreach (AgentSignature::headers($secret ?? self::$secret, 'GET', $uri) as $name => $value) {
            $headers[] = "$name: $value";
        }
        $context = stream_context_create(['http' => ['method' => 'GET', 'header' => implode("\r\n", $headers), 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 30]]);
        $body = (string) @file_get_contents('http://' . $host . $uri, false, $context);
        preg_match('~^HTTP/\S+ (\d+)~', (string) ($http_response_header[0] ?? ''), $m);

        return [(int) ($m[1] ?? 0), $body];
    }

    /** The workspace of the pointer as this container sees it. */
    private static function here(string $workspace): string
    {
        return self::SITE . substr($workspace, strlen(self::APACHE_VIEW));
    }

    /** @return array<string, mixed> */
    private static function pointer(): array
    {
        $text = (string) @file_get_contents(self::POINTER);
        $data = json_decode(substr($text, strlen("<?php http_response_code(404); exit; ?>\n")), true);

        return is_array($data) ? $data : [];
    }

    private static function found(string $pattern, string $text): string
    {
        if (preg_match($pattern, $text, $m) !== 1) {
            self::fail("Nothing on the page matches $pattern");
        }

        return html_entity_decode($m[1]);
    }

    /** Builds the ZIP of the plugin as the release does and puts it where WP-CLI (www-data) can read it. */
    private static function buildPackage(string $version): string
    {
        $tool = self::DIRECTORY . '/tool/threadsafe-backup.php';
        @mkdir(dirname($tool), 0755, true);
        (new BackupBuilder(dirname(__DIR__, 2), $version))->build($tool);
        $zip = self::DIRECTORY . '/' . ConnectorBuilder::packageName($version);
        (new ConnectorBuilder(dirname(__DIR__, 2)))->write($zip, $tool, $version, time());
        chmod($zip, 0644);

        return $zip;
    }

    private static function admin(): WordPressClient
    {
        $client = new WordPressClient('http://wordpress7', 'localhost:8197');
        self::assertTrue($client->login('seedadmin', getenv('WORDPRESS_ADMIN_PASSWORD') ?: 'Seed-admin-0123456789'), 'the administrator can log in');

        return $client;
    }

    // ------------------------------------------------------------------------------------------------- install and set up

    public function testThePluginIsInstalledAndActivatedWithWpCliAndSwitchesTheAgentDoorOn(): void
    {
        self::shell('rm -rf ' . escapeshellarg(self::DIRECTORY));
        mkdir(self::DIRECTORY . '/central', 0777, true);
        chmod(self::DIRECTORY, 0755);
        self::wp('plugin deactivate threadsafe-connector');
        self::shell('rm -rf ' . escapeshellarg(self::PLUGIN) . ' ' . escapeshellarg(self::POINTER) . ' ' . escapeshellarg(self::SITE . '/wp-content/uploads') . '/threadsafe-*');
        $zip = self::buildPackage('1.1.0-stack.1');

        [$code, $out] = self::wp('plugin install ' . escapeshellarg($zip) . ' --force');
        self::assertSame(0, $code, $out);
        self::assertFileExists(self::PLUGIN . '/threadsafe-backup.php');
        self::assertFileExists(self::PLUGIN . '/agent.php');
        [$code, $out] = self::wp('plugin activate threadsafe-connector');
        self::assertSame(0, $code, $out);

        $pointer = self::pointer();
        self::assertTrue($pointer['on'] ?? false, 'activating switches the door on');
        self::assertMatchesRegularExpression('~^' . self::APACHE_VIEW . '/wp-content/uploads/threadsafe-[0-9a-f]{16}$~', $pointer['workspace'] ?? '');
        self::assertSame(0600, fileperms(self::POINTER) & 0777);
        self::assertDirectoryExists(self::here($pointer['workspace']));
        self::assertSame(1, self::wp('option get threadsafe_connector_failure')[0], 'no failure is remembered');
        [$status, $body] = self::signed('api=status', secret: str_repeat('0', 64));
        self::assertSame([404, "Not found.\n"], [$status, $body], 'a door that is on but not paired answers like a file that is not there');
        self::assertSame('1.1.0-stack.1', trim(self::wp('plugin get threadsafe-connector --field=version')[1]));
    }

    public function testThePageSetsTheKeyPairsTheAgentAndSwitchesItOn(): void
    {
        [$code, $out] = self::shell('age-keygen -o ' . escapeshellarg(self::DIRECTORY . '/key.txt'));
        self::assertSame(0, $code, $out);
        chmod(self::DIRECTORY . '/key.txt', 0600);
        self::$public = trim(self::shell('age-keygen -y ' . escapeshellarg(self::DIRECTORY . '/key.txt'))[1]);
        self::assertTrue(AgeKeys::isRecipient(self::$public));

        $admin = self::admin();
        $page = $admin->get('/wp-admin/tools.php?page=threadsafe-connector');
        self::assertSame(200, $page['status'], substr($page['body'], 0, 500));
        self::assertStringContainsString('ThreadSafe Connector', $page['body']);
        self::assertStringContainsString('name="recipients"', $page['body'], mb_substr(trim((string) preg_replace('/\s+/', ' ', strip_tags((string) preg_replace('~<script.*?</script>|<style.*?</style>~s', '', (string) strstr($page['body'], 'class="wrap'))))), 0, 1500));
        self::assertStringNotContainsString('Fatal error', $page['body']);
        $csrf = self::found('~name="csrf" value="([^"]+)"~', $page['body']);
        $url = '/wp-admin/tools.php?page=threadsafe-connector';

        $refused = $admin->post($url, ['action' => 'connector-save', 'csrf' => $csrf, 'recipients' => '', 'exclude' => '', 'part_size' => '']);
        self::assertStringContainsString('Paste at least one public key', $refused['body'], 'nothing is stored without a key');
        $wrong = $admin->post($url, ['action' => 'connector-save', 'csrf' => 'forged', 'recipients' => self::$public]);
        self::assertStringContainsString('The form has expired', $wrong['body'], 'a form without its nonce does nothing');

        $saved = $admin->post($url, ['action' => 'connector-save', 'csrf' => $csrf, 'recipients' => self::$public, 'exclude' => "/wp-content/backups/\n", 'part_size' => '5M']);
        self::assertStringContainsString('Saved.', $saved['body'], substr(strip_tags($saved['body']), 0, 800));
        $paired = $admin->post($url, ['action' => 'agent-pair', 'csrf' => $csrf, 'agent_id' => self::PROFILE, 'agent_url' => self::URL, 'agent_allow' => '', 'agent_keep' => '1', 'agent_plain' => '1']);
        $string = self::found('~<code>(ts1:[A-Za-z0-9_-]+)</code>~', $paired['body']);
        $decoded = AgentPairing::decode($string);
        self::assertSame(self::PROFILE, $decoded['profile']);
        self::$secret = $decoded['secret'];
        self::$pairingFile = self::DIRECTORY . '/pairing.txt';
        file_put_contents(self::$pairingFile, $string . "\n");
        chmod(self::$pairingFile, 0600);
        self::assertStringNotContainsString(self::$secret, $admin->get($url)['body'], 'the string is shown once');

        self::assertSame(404, self::signed('api=status')[0], 'paired but off');
        $on = $admin->post($url, ['action' => 'agent-on', 'csrf' => $csrf]);
        self::assertStringContainsString('The agent is on', $on['body']);
        [$status, $body] = self::signed('api=status');
        self::assertSame(200, $status, $body);
        $answer = json_decode($body, true);
        self::assertSame(['id' => self::PROFILE, 'ready' => true], $answer['profile']);
        self::assertSame(5, $answer['limits']['max_execution_time']);
        self::assertSame('64M', $answer['limits']['memory_limit']);
        self::assertStringContainsString('Can the web server hand out the backups?', $admin->get($url)['body']);
    }

    public function testTheWebServerDoesNotHandOutTheWorkspaceNorThePointerToTheStranger(): void
    {
        $workspace = (string) (self::pointer()['workspace'] ?? '');
        $url = 'http://wordpress7/wp-content/uploads/' . basename($workspace);
        foreach (['/', '/.htaccess'] as $suffix) {
            $headers = (string) (@get_headers($url . $suffix, false, stream_context_create(['http' => ['ignore_errors' => true]])) ?: [''])[0];
            self::assertDoesNotMatchRegularExpression('~ 200~', $headers, $suffix . ' of the workspace must not be served');
        }
        $pointer = (string) @file_get_contents('http://wordpress7/wp-content/threadsafe-agent.php');
        self::assertStringNotContainsString('workspace', $pointer, 'fetched through the web, the pointer says nothing');
        self::assertSame('Not found.', trim((string) @file_get_contents('http://wordpress7' . self::PATH, false, stream_context_create(['http' => ['ignore_errors' => true]]))), 'no api request: no page of the tool');
    }

    // ------------------------------------------------------------------------------------------------- a backup through the plugin

    public function testTheCentralSideBacksTheSiteUpThroughThePluginAndNoneOfThePluginIsInIt(): void
    {
        [$code, $out, $err] = self::cli(['profile', 'add', self::PROFILE, '--pairing-file=' . self::$pairingFile, '--storage=' . self::DIRECTORY . '/central/storage', '--plain-http', '--name=Seeded WordPress 7', '--no-test']);
        self::assertSame(0, $code, $out . $err);
        [$code, $out, $err] = self::cli(['profile', 'test', self::PROFILE]);
        self::assertSame(0, $code, $out . $err);
        self::assertStringContainsString('The profile can be backed up.', $out);

        [$code, $out, $err] = self::cli(['backup', self::PROFILE]);
        self::assertSame(0, $code, $out . $err);
        [$code, $out, $err] = self::cli(['verify', self::PROFILE]);
        self::assertSame(0, $code, $out . $err);
        self::assertStringContainsString('is intact', $out);

        $sidecars = glob(self::DIRECTORY . '/central/storage/*.json') ?: [];
        self::assertCount(1, $sidecars);
        self::$backup = basename($sidecars[0], '.json');
        $parts = glob(self::DIRECTORY . '/central/storage/' . self::$backup . '-p*.zip.age') ?: [];
        self::assertGreaterThan(0, count($parts));

        // the backup opens with the real age and holds the site, but neither the door nor the pointer nor the tool
        $listing = '';
        foreach ($parts as $part) {
            [$code, $out] = self::shell('age -d -i ' . escapeshellarg(self::DIRECTORY . '/key.txt') . ' ' . escapeshellarg($part) . ' > ' . escapeshellarg(self::DIRECTORY . '/open.zip'));
            self::assertSame(0, $code, $out);
            $listing .= self::shell('unzip -Z1 ' . escapeshellarg(self::DIRECTORY . '/open.zip'))[1] . "\n";
        }
        @unlink(self::DIRECTORY . '/open.zip');
        self::assertStringContainsString('files/wp-config.php', $listing);
        foreach (['threadsafe-agent.php', 'threadsafe-connector/agent.php', 'threadsafe-connector/threadsafe-backup.php', '.threadsafe-backup', 'uploads/threadsafe-'] as $never) {
            self::assertStringNotContainsString($never, $listing, "$never must not travel into a copy of the site");
        }
        self::assertStringContainsString('threadsafe-connector/threadsafe-connector.php', $listing, 'the inert rest of the plugin is part of the site');
    }

    // ------------------------------------------------------------------------------------------------- off, update, uninstall

    public function testDeactivatingSwitchesTheDoorOffAndActivatingFindsThePairingAgain(): void
    {
        [$code, $out] = self::wp('plugin deactivate threadsafe-connector');
        self::assertSame(0, $code, $out);
        self::assertFalse(self::pointer()['on'] ?? true);
        self::assertSame(404, self::signed('api=status')[0], 'a deactivated plugin answers nobody');

        [$code, $out] = self::wp('plugin activate threadsafe-connector');
        self::assertSame(0, $code, $out);
        self::assertSame(200, self::signed('api=status')[0], 'the same pairing works again');
    }

    public function testAnUpdateOfThePluginDirectoryKeepsThePairingAndTheArchives(): void
    {
        $workspace = self::here((string) self::pointer()['workspace']);
        $before = glob($workspace . '/storage-*/*.json') ?: [];
        self::assertNotSame([], $before, 'the backup of the first test is still on the host (it keeps one)');
        $zip = self::buildPackage('1.1.0-stack.2');

        [$code, $out] = self::wp('plugin install ' . escapeshellarg($zip) . ' --force');

        self::assertSame(0, $code, $out);
        self::assertSame('1.1.0-stack.2', trim(self::wp('plugin get threadsafe-connector --field=version')[1]));
        self::assertSame($workspace, self::here((string) self::pointer()['workspace']), 'the workspace is where it was');
        self::assertSame($before, glob($workspace . '/storage-*/*.json') ?: []);
        [$status, $body] = self::signed('api=status');
        self::assertSame(200, $status, 'the pairing survived the update: ' . $body);
        self::assertSame(['id' => self::PROFILE, 'ready' => true], json_decode($body, true)['profile']);
        $page = self::admin()->get('/wp-admin/tools.php?page=threadsafe-connector');
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Paired with the profile <code>' . self::PROFILE . '</code>', $page['body']);
    }

    public function testUninstallingRemovesThePointerAndThePairingButLeavesTheArchives(): void
    {
        $workspace = self::here((string) self::pointer()['workspace']);
        $archives = glob($workspace . '/storage-*/*') ?: [];
        self::assertNotSame([], $archives);

        self::assertSame(0, self::wp('plugin deactivate threadsafe-connector')[0]);
        [$code, $out] = self::wp('plugin uninstall threadsafe-connector');

        self::assertSame(0, $code, $out);
        self::assertFileDoesNotExist(self::POINTER);
        self::assertSame([], glob($workspace . '/data-*') ?: [], 'the pairing and the state are gone');
        self::assertSame($archives, glob($workspace . '/storage-*/*') ?: [], 'the archives stay unless the box was ticked');
        // the file is gone, so the request goes to WordPress, which answers 404 (the seeded site's object-cache drop-in makes that a 500 on this stack: it is the fixture's, not the plugin's)
        [$status, $body] = self::signed('api=status');
        self::assertNotSame(200, $status, 'the door of a plugin that is uninstalled does not answer');
        self::assertStringNotContainsString('"api"', $body);
        self::shell('rm -rf ' . escapeshellarg($workspace));
    }

    // ------------------------------------------------------------------------------------------------- a host below PHP 8.4

    public function testTheDoorOfThePluginAnswersLikeAFileThatIsNotThereOnPhp81EvenWhenItIsSwitchedOn(): void
    {
        $site = '/wordpress60';
        if (!is_file($site . '/wp-includes/version.php')) {
            self::markTestSkipped('The WordPress 6.0 service (PHP 8.1) is not mounted.');
        }
        $plugin = $site . '/wp-content/plugins/threadsafe-connector';
        self::shell('rm -rf ' . escapeshellarg($plugin));
        mkdir($plugin, 0755, true);
        copy(dirname(__DIR__, 2) . '/satellites/wordpress/threadsafe-connector/agent.php', $plugin . '/agent.php');
        file_put_contents($plugin . '/threadsafe-backup.php', '<?php echo "THE TOOL WAS REACHED";');
        file_put_contents($site . '/wp-content/threadsafe-agent.php', "<?php http_response_code(404); exit; ?>\n" . json_encode(['workspace' => $site . '/wp-content/uploads/threadsafe-x', 'on' => true]) . "\n");
        chmod($site . '/wp-content/threadsafe-agent.php', 0600);
        self::shell('chown -R www-data:www-data ' . escapeshellarg($plugin) . ' ' . escapeshellarg($site . '/wp-content/threadsafe-agent.php'));

        try {
            $body = (string) @file_get_contents('http://wordpress60/wp-content/plugins/threadsafe-connector/agent.php?api=status', false, stream_context_create(['http' => ['ignore_errors' => true]]));
            self::assertSame('Not found.', trim($body));
            self::assertStringNotContainsString('THE TOOL WAS REACHED', $body);
        } finally {
            self::shell('rm -rf ' . escapeshellarg($plugin) . ' ' . escapeshellarg($site . '/wp-content/threadsafe-agent.php'));
        }
    }
}
