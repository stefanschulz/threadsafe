<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use ThreadSafe\Build\BackupBuilder;
use ThreadSafe\Build\ConnectorBuilder;
use ThreadSafe\Crypto\AgeKeys;
use ThreadSafe\Tests\Support\CliProcess;
use ThreadSafe\Tests\Support\PrestaShopClient;
use ThreadSafe\Web\AgentPairing;
use ThreadSafe\Web\AgentSignature;

/**
 * The PrestaShop module `threadsafeconnector` (docs/PHASE15.md 5, work package 5) in a real PrestaShop 9 behind a real Apache with limits of 5 s and 64 MB:
 * the ZIP is installed through the module manager of the back office (as a person does it), the page of the module is used through a logged-in browser
 * (settings, pairing, the switch), the central side makes a backup through the agent, and the module is disabled, enabled, updated (the directory replaced)
 * and uninstalled while the pairing and the archives are watched. The shop is the service `prestashopconnector` (tests/stack/seed-prestashop-connector.sh);
 * the volume of its files is mounted at /var/www/html in this container, so that the paths in the pointer are the paths that Apache uses.
 *
 * The tests depend on each other, in file order.
 */
#[Group('connector-ps')]
final class PrestaShopConnectorStackTest extends IntegrationTestCase
{
    private const string SITE = '/var/www/html';

    private const string MODULE = self::SITE . '/modules/threadsafeconnector';

    private const string POINTER = self::SITE . '/modules/threadsafe-agent.php';

    private const string PATH = '/modules/threadsafeconnector/agent.php';

    private const string URL = 'http://prestashopconnector' . self::PATH;

    private const string DIRECTORY = '/app/.cache/connector-ps';

    private const string PROFILE = 'psc';

    private static string $public = '';

    private static string $secret = '';

    private static string $pairingFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        if (!is_file(self::SITE . '/app/config/parameters.php')) {
            self::markTestSkipped('The shop of the module is not mounted at ' . self::SITE . ' (tests/stack/seed-prestashop-connector.sh, and run.sh mounts its volume).');
        }
        if (self::shell('command -v age-keygen && command -v runuser && command -v unzip')[0] !== 0) {
            self::markTestSkipped('age-keygen, runuser and unzip are needed (the tools image has them).');
        }
    }

    /** @return array{int, string} */
    private static function shell(string $command): array
    {
        exec($command . ' 2>&1', $lines, $code);

        return [$code, implode("\n", $lines)];
    }

    /**
     * The console of PrestaShop as the user of the web server, so that what it writes is what Apache can write over.
     *
     * @return array{int, string}
     */
    private static function console(string $arguments): array
    {
        return self::shell('cd ' . self::SITE . ' && runuser -u www-data -- env HOME=/tmp php -d memory_limit=-1 bin/console ' . $arguments . ' --env=prod --no-interaction');
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
    private static function signed(string $query, ?string $secret = null): array
    {
        $uri = self::PATH . '?' . $query;
        $headers = ['Connection: close'];
        foreach (AgentSignature::headers($secret ?? self::$secret, 'GET', $uri) as $name => $value) {
            $headers[] = "$name: $value";
        }
        $context = stream_context_create(['http' => ['method' => 'GET', 'header' => implode("\r\n", $headers), 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 30]]);
        $body = (string) @file_get_contents('http://prestashopconnector' . $uri, false, $context);
        preg_match('~^HTTP/\S+ (\d+)~', (string) ($http_response_header[0] ?? ''), $m);

        return [(int) ($m[1] ?? 0), $body];
    }

    /** The status line of an unsigned request for a path of the shop. */
    private static function statusOf(string $path): int
    {
        $headers = @get_headers('http://prestashopconnector' . $path, false, stream_context_create(['http' => ['ignore_errors' => true, 'follow_location' => 0, 'timeout' => 30]]));
        preg_match('~ (\d{3})~', (string) ($headers[0] ?? ''), $m);

        return (int) ($m[1] ?? 0);
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

    /** Builds the ZIP of the module as the release does. */
    private static function buildPackage(string $version): string
    {
        $tool = self::DIRECTORY . '/tool/threadsafe-backup.php';
        @mkdir(dirname($tool), 0755, true);
        (new BackupBuilder(dirname(__DIR__, 2), $version))->build($tool);
        $zip = self::DIRECTORY . '/' . ConnectorBuilder::packageName($version, 'prestashop');
        (new ConnectorBuilder(dirname(__DIR__, 2), 'prestashop'))->write($zip, $tool, $version, time());
        chmod($zip, 0644);

        return $zip;
    }

    private static function admin(): PrestaShopClient
    {
        $client = new PrestaShopClient('http://prestashopconnector', 'localhost:8590');
        self::assertTrue($client->login(getenv('PS_ADMIN_MAIL') ?: 'admin@example.com', getenv('PS_ADMIN_PASSWORD') ?: 'Admin1234!'), 'the administrator can log in');

        return $client;
    }

    // ------------------------------------------------------------------------------------------------- install and set up

    public function testTheModuleIsInstalledFromItsZipInTheBackOfficeAndSwitchesTheDoorOn(): void
    {
        self::shell('rm -rf ' . escapeshellarg(self::DIRECTORY));
        mkdir(self::DIRECTORY . '/central', 0777, true);
        chmod(self::DIRECTORY, 0755);
        self::console('prestashop:module uninstall threadsafeconnector');
        self::shell('rm -rf ' . escapeshellarg(self::MODULE) . ' ' . escapeshellarg(self::POINTER) . ' ' . escapeshellarg(self::SITE . '/var') . '/threadsafe-*');
        $zip = self::buildPackage('1.3.0-stack.1');

        $answer = self::admin()->importModule($zip);

        self::assertTrue($answer['status'] ?? false, json_encode($answer, JSON_UNESCAPED_SLASHES) ?: '');
        self::assertSame('threadsafeconnector', $answer['module_name'] ?? '');
        self::assertFileExists(self::MODULE . '/threadsafe-backup.php');
        self::assertFileExists(self::MODULE . '/agent.php');
        self::assertFileExists(self::MODULE . '/.htaccess');
        self::assertSame('www-data', trim(self::shell('stat -c %U ' . escapeshellarg(self::MODULE . '/agent.php'))[1]), 'the web server unpacked it');

        $pointer = self::pointer();
        self::assertTrue($pointer['on'] ?? false, 'installing switches the door on');
        self::assertMatchesRegularExpression('~^' . self::SITE . '/var/threadsafe-[0-9a-f]{16}$~', $pointer['workspace'] ?? '');
        self::assertSame(0600, fileperms(self::POINTER) & 0777);
        self::assertDirectoryExists($pointer['workspace']);
        [$status, $body] = self::signed('api=status', str_repeat('0', 64));
        self::assertSame([404, "Not found.\n"], [$status, $body], 'a door that is on but not paired answers like a file that is not there, not with the 403 of PrestaShop');
        self::assertStringContainsString("\$this->version = '1.3.0-stack.1';", (string) file_get_contents(self::MODULE . '/threadsafeconnector.php'));
    }

    public function testThePageSetsTheKeyPairsTheAgentAndSwitchesItOn(): void
    {
        [$code, $out] = self::shell('age-keygen -o ' . escapeshellarg(self::DIRECTORY . '/key.txt'));
        self::assertSame(0, $code, $out);
        chmod(self::DIRECTORY . '/key.txt', 0600);
        self::$public = trim(self::shell('age-keygen -y ' . escapeshellarg(self::DIRECTORY . '/key.txt'))[1]);
        self::assertTrue(AgeKeys::isRecipient(self::$public));

        $admin = self::admin();
        $url = $admin->configureUrl('threadsafeconnector');
        $page = $admin->get($url);
        self::assertSame(200, $page['status'], substr($page['body'], 0, 500));
        self::assertStringContainsString('name="recipients"', $page['body'], mb_substr(trim((string) preg_replace('/\s+/', ' ', strip_tags((string) preg_replace('~<script.*?</script>|<style.*?</style>~s', '', (string) strstr($page['body'], 'class="tsc"'))))), 0, 1500));
        self::assertStringNotContainsString('Fatal error', $page['body']);
        $csrf = self::found('~name="csrf" value="([^"]+)"~', $page['body']);

        $refused = $admin->post($url, ['action' => 'connector-save', 'csrf' => $csrf, 'recipients' => '', 'exclude' => '', 'part_size' => '']);
        self::assertStringContainsString('Paste at least one public key', $refused['body'], 'nothing is stored without a key');
        $wrong = $admin->post($url, ['action' => 'connector-save', 'csrf' => 'forged', 'recipients' => self::$public]);
        self::assertStringContainsString('The form has expired', $wrong['body'], 'a form without its token does nothing');

        $saved = $admin->post($url, ['action' => 'connector-save', 'csrf' => $csrf, 'recipients' => self::$public, 'exclude' => "/var/logs/\n", 'part_size' => '50M']);
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
        self::assertStringContainsString('Can the central ThreadSafe reach the door of the agent?', $admin->get($url)['body']);
    }

    public function testTheWebServerHandsOutTheDoorAndNothingElseOfTheModule(): void
    {
        $workspace = (string) (self::pointer()['workspace'] ?? '');
        foreach (['/', '/.htaccess'] as $suffix) {
            self::assertNotSame(200, self::statusOf('/var/' . basename($workspace) . $suffix), $suffix . ' of the workspace must not be served');
        }
        foreach (['/modules/threadsafe-agent.php', '/modules/threadsafeconnector/threadsafe-backup.php', '/modules/threadsafeconnector/threadsafeconnector.php', '/modules/threadsafeconnector/includes/page.php'] as $path) {
            self::assertSame(403, self::statusOf($path), $path . ' must be refused by PrestaShop\'s rule: only the door is opened');
        }
        self::assertSame('Not found.', trim((string) @file_get_contents('http://prestashopconnector' . self::PATH, false, stream_context_create(['http' => ['ignore_errors' => true]]))), 'no api request: no page of the tool');
    }

    // ------------------------------------------------------------------------------------------------- a backup through the module

    public function testTheCentralSideBacksTheShopUpThroughTheModuleAndNoneOfTheDoorIsInIt(): void
    {
        [$code, $out, $err] = self::cli(['profile', 'add', self::PROFILE, '--pairing-file=' . self::$pairingFile, '--storage=' . self::DIRECTORY . '/central/storage', '--plain-http', '--name=Module shop', '--no-test']);
        self::assertSame(0, $code, $out . $err);
        [$code, $out, $err] = self::cli(['profile', 'test', self::PROFILE]);
        self::assertSame(0, $code, $out . $err);
        self::assertStringContainsString('The profile can be backed up.', $out);
        [$code, $out, $err] = self::cli(['backup', self::PROFILE]);
        self::assertSame(0, $code, $out . $err);
        [$code, $out, $err] = self::cli(['verify', self::PROFILE, '--identity-file=' . self::DIRECTORY . '/key.txt']);
        self::assertSame(0, $code, $out . $err);
        self::assertStringContainsString('is intact', $out);

        $storage = self::DIRECTORY . '/central/storage';
        self::assertCount(1, glob($storage . '/*.json') ?: [], 'one backup, one sidecar file');
        $listing = '';
        foreach (glob($storage . '/*.zip.age') ?: [] as $part) {
            [$code, $out] = self::shell('age -d -i ' . escapeshellarg(self::DIRECTORY . '/key.txt') . ' ' . escapeshellarg($part) . ' > ' . escapeshellarg(self::DIRECTORY . '/part.zip'));
            self::assertSame(0, $code, $out);
            $listing .= self::shell('unzip -Z1 ' . escapeshellarg(self::DIRECTORY . '/part.zip'))[1] . "\n";
        }
        @unlink(self::DIRECTORY . '/part.zip');
        self::assertStringContainsString('files/app/config/parameters.php', $listing, 'the shop is in it');
        foreach (['modules/threadsafe-agent.php', 'threadsafeconnector/agent.php', 'threadsafeconnector/threadsafe-backup.php', '.threadsafe-backup', 'var/threadsafe-'] as $never) {
            self::assertStringNotContainsString($never, $listing, "$never must not travel into a copy of the shop");
        }
        self::assertStringContainsString('threadsafeconnector/threadsafeconnector.php', $listing, 'the inert rest of the module is part of the shop');
    }

    // ------------------------------------------------------------------------------------------------- disable, enable, update, uninstall

    public function testDisablingSwitchesTheDoorOffAndEnablingFindsThePairingAgain(): void
    {
        [$code, $out] = self::console('prestashop:module disable threadsafeconnector');
        self::assertSame(0, $code, $out);
        self::assertFalse(self::pointer()['on'] ?? true, 'disabling switches the door off');
        self::assertSame(404, self::signed('api=status')[0]);

        [$code, $out] = self::console('prestashop:module enable threadsafeconnector');
        self::assertSame(0, $code, $out);
        self::assertTrue(self::pointer()['on'] ?? false);
        self::assertSame(200, self::signed('api=status')[0], 'the same pairing works again');
    }

    public function testAnUpdateOfTheModuleDirectoryKeepsThePairingAndTheArchives(): void
    {
        $workspace = (string) (self::pointer()['workspace'] ?? '');
        $archives = array_map('basename', glob($workspace . '/storage-*/*') ?: []);
        $zip = self::buildPackage('1.3.0-stack.2');

        $answer = self::admin()->importModule($zip);

        self::assertTrue($answer['status'] ?? false, json_encode($answer, JSON_UNESCAPED_SLASHES) ?: '');
        self::assertStringContainsString("\$this->version = '1.3.0-stack.2';", (string) file_get_contents(self::MODULE . '/threadsafeconnector.php'));
        self::assertSame($workspace, self::pointer()['workspace'] ?? '', 'the workspace is where it was');
        self::assertSame($archives, array_map('basename', glob($workspace . '/storage-*/*') ?: []), 'the archives are still there');
        [$status, $body] = self::signed('api=status');
        self::assertSame(200, $status, 'the pairing survived the update: ' . $body);
        $admin = self::admin();
        self::assertStringContainsString('Paired with the profile ' . self::PROFILE, strip_tags($admin->get($admin->configureUrl('threadsafeconnector'))['body']));
    }

    public function testUninstallingRemovesThePointerAndThePairingButLeavesTheArchives(): void
    {
        $workspace = (string) (self::pointer()['workspace'] ?? '');
        $archives = glob($workspace . '/storage-*/*.zip.age') ?: [];

        [$code, $out] = self::console('prestashop:module uninstall threadsafeconnector');
        self::assertSame(0, $code, $out);

        self::assertFileDoesNotExist(self::POINTER);
        self::assertSame([], glob($workspace . '/data-*') ?: [], 'the pairing and the state are gone');
        self::assertSame($archives, glob($workspace . '/storage-*/*.zip.age') ?: [], 'the archives stay (the box was not ticked)');
        self::assertNotSame(200, self::signed('api=status')[0]);
        self::shell('rm -rf ' . escapeshellarg($workspace) . ' ' . escapeshellarg(self::MODULE));
    }
}
