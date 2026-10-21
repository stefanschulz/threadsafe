<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Integration;

use PDO;
use RuntimeException;
use ThreadSafe\Backup\BackupName;
use ThreadSafe\Backup\BackupRun;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Engine\Executor;
use ThreadSafe\Engine\RunStore;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Profile\SiteProfile;
use ThreadSafe\Storage\BackupCatalog;
use ThreadSafe\Storage\LocalStorage;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Tests\Support\TortureDatabase;

/**
 * The wizard behind a real web server (PHP's built-in one, started on a free port): the whole way a person goes - key, backup,
 * target, database, summary, ticks from the page's own script, deletion of the tool - with real requests, real cookies and real
 * headers, and a real database server.
 */
final class WizardHttpTest extends IntegrationTestCase
{
    use TempDirectory;

    /** @var resource|null */
    private $server;

    private int $port = 0;

    private string $web = '';

    private ?string $cookie = null;

    private string $csrf = '';

    private PDO $admin;

    /** @var list<string> */
    private array $databases = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = new PDO('mysql:host=db;charset=utf8mb4', 'root', self::password(), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->web = $this->tempDirectory() . '/web';
        mkdir($this->web);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        foreach ($this->databases as $database) {
            $this->admin->exec("DROP DATABASE IF EXISTS `$database`");
        }
        $this->cleanUpTempDirectories();
    }

    private static function password(): string
    {
        $password = getenv('DB_ROOT_PASSWORD');

        return $password === false || $password === '' ? 'prestashop' : $password;
    }

    public function testADatabaseAndItsFilesComeBackThroughTheBrowserInterface(): void
    {
        // a backup of a site with a database, "uploaded" next to the tool
        $source = 'threadsafe_wizard_src_' . bin2hex(random_bytes(3));
        $this->databases[] = $source;
        TortureDatabase::create($this->admin, $source);
        $copy = 'threadsafe_wizard_copy_' . bin2hex(random_bytes(3));
        $this->databases[] = $copy;
        $this->admin->exec("CREATE DATABASE `$copy` CHARACTER SET utf8mb4");
        $this->uploadBackup($source);
        file_put_contents($this->web . '/restore-key.php', "<?php return 'a long secret from the test';");
        file_put_contents($this->web . '/restore.php', '<?php // the tool');
        $this->startServer();
        $root = $this->web . '/../restored';

        // --- the key
        $page = $this->request('GET', '/');
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('name="key"', $page['body']);
        $wrong = $this->request('POST', '/', ['action' => 'login', 'key' => 'nope nope nope']);
        self::assertSame(403, $wrong['status']);
        $login = $this->request('POST', '/', ['action' => 'login', 'key' => 'a long secret from the test']);
        self::assertSame(303, $login['status']);
        self::assertNotNull($this->cookie, 'the cookie is set');
        self::assertStringContainsString('HttpOnly', (string) $login['headers']['set-cookie']);

        // --- the backup, the requirements
        $backup = $this->follow($login);
        self::assertStringContainsString('Found next to the tool', $backup['body']);
        if (preg_match('/name="choice" value="([^"]+)"/', $backup['body'], $m) !== 1) {
            self::fail('The backup is not offered.');
        }
        $requirements = $this->follow($this->request('POST', '/', ['action' => 'choose-backup', 'choice' => $m[1], 'path' => '', 'csrf' => $this->csrf]));
        self::assertStringContainsString('What this server offers', $requirements['body']);
        self::assertStringContainsString('7 tables', $requirements['body']);

        // --- the target, with a connection test
        $values = ['root' => $root, 'db_host' => 'db', 'db_port' => '3306', 'db_name' => $copy, 'db_user' => 'root', 'db_password' => self::password(), 'db_prefix' => '', 'f_from' => '', 'f_to' => ''];
        $tested = $this->request('POST', '/', ['action' => 'test-db', 'csrf' => $this->csrf] + $values);
        self::assertStringContainsString('Connected to', $tested['body']);
        self::assertStringContainsString('holds 0 table', $tested['body']);
        $this->follow($this->request('POST', '/', ['action' => 'save-target', 'csrf' => $this->csrf] + $values));
        $summary = $this->request('GET', '/?step=summary');
        self::assertStringContainsString('Restore now', $summary['body']);
        self::assertStringContainsString($copy, $summary['body']);
        self::assertStringNotContainsString(self::password(), $summary['body'], 'the password is never shown again');

        // --- the restore, tick by tick as the script of the page does it
        $run = $this->follow($this->request('POST', '/', ['action' => 'start', 'csrf' => $this->csrf]));
        self::assertStringContainsString('Keep this page open', $run['body']);
        $last = [];
        for ($tick = 1; $tick <= 500; ++$tick) {
            $answer = $this->request('POST', '/', ['action' => 'tick', 'format' => 'json', 'csrf' => $this->csrf]);
            self::assertStringContainsString('application/json', (string) $answer['headers']['content-type']);
            $last = json_decode($answer['body'], true);
            self::assertContains($last['status'], ['running', 'finished'], $answer['body']);
            if ($last['status'] === 'finished') {
                break;
            }
        }
        self::assertSame('finished', $last['status']);
        foreach (['binkey', 'blobs', 'docs', 'items', 'nopk', 'types'] as $table) {
            self::assertSame($this->checksum($source, $table), $this->checksum($copy, $table), "CHECKSUM TABLE $table");
        }
        self::assertSame('<?php echo 1;', file_get_contents($root . '/index.php'));

        // --- done, and the tool deletes itself
        $done = $this->request('GET', '/?step=done');
        self::assertStringContainsString('The backup is restored', $done['body']);
        self::assertStringNotContainsString(self::password(), $done['body']);
        $gone = $this->request('POST', '/', ['action' => 'cleanup', 'csrf' => $this->csrf]);
        self::assertStringContainsString('The tool is gone', $gone['body']);
        self::assertFileDoesNotExist($this->web . '/restore.php');
        self::assertFileDoesNotExist($this->web . '/restore-key.php');
        self::assertDirectoryDoesNotExist($this->web . '/.threadsafe-restore');
        $left = array_values(array_filter(scandir($this->web) ?: [], static fn (string $f): bool => !in_array($f, ['.', '..'], true) && !str_ends_with($f, '.zip') && !str_ends_with($f, '.json')));
        self::assertSame([], $left, 'only the backup files are left');
    }

    // ------------------------------------------------------------------------------------------------------ helpers

    private function uploadBackup(string $database): void
    {
        $site = dirname($this->web) . '/site';
        mkdir($site);
        file_put_contents($site . '/index.php', '<?php echo 1;');
        $data = dirname($this->web) . '/data';
        $profiles = new ProfileStore($data . '/profiles');
        $profiles->save(SiteProfile::fromArray([
            'id' => 'shop', 'type' => 'generic', 'path' => $site, 'storage' => dirname($this->web) . '/storage',
            'database' => ['host' => 'db', 'port' => 3306, 'name' => $database, 'user' => 'root', 'password' => self::password(), 'prefix' => '', 'driver' => 'pdo'],
        ]));
        $executor = new Executor(new RunStore($data . '/runs'), new BackupRun($profiles, PluginRegistry::load(), $data), static fn (): Budget => Budget::unlimited());
        $run = $executor->execute($executor->start(['profile' => 'shop'])->id);
        $name = BackupName::parse((string) ($run->state['result']['backup'] ?? ''));
        self::assertNotNull($name);
        foreach ((new BackupCatalog(new LocalStorage(dirname($this->web) . '/storage')))->archiveParts($name) as $part) {
            copy($part, $this->web . '/' . basename($part));
        }
    }

    private function startServer(): void
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($probe === false) {
            throw new RuntimeException("No free port: $error");
        }
        $this->port = (int) substr((string) stream_socket_get_name($probe, false), (int) strrpos((string) stream_socket_get_name($probe, false), ':') + 1);
        fclose($probe);

        $server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $this->port, dirname(__DIR__, 2) . '/tools/restore-web.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['THREADSAFE_RESTORE_BASE' => $this->web, 'PATH' => (string) getenv('PATH'), 'DB_ROOT_PASSWORD' => self::password()],
        );
        if (!is_resource($server)) {
            throw new RuntimeException('Cannot start the PHP web server.');
        }
        $this->server = $server;
        for ($i = 0; $i < 100; ++$i) {
            $connection = @fsockopen('127.0.0.1', $this->port, $errno, $error, 0.2);
            if ($connection !== false) {
                fclose($connection);

                return;
            }
            usleep(100000);
        }
        throw new RuntimeException('The PHP web server did not start.');
    }

    /**
     * @param array<string, string> $fields
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function request(string $method, string $path, array $fields = []): array
    {
        $headers = ['Connection: close'];
        if ($this->cookie !== null) {
            $headers[] = 'Cookie: threadsafe_restore=' . $this->cookie;
        }
        $content = '';
        if ($method === 'POST') {
            $content = http_build_query($fields);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        $context = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'content' => $content, 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 120]]);
        $body = @file_get_contents('http://127.0.0.1:' . $this->port . $path, false, $context);
        $lines = $http_response_header;
        self::assertNotFalse($body, 'The web server answered.');

        $status = 0;
        $parsed = [];
        foreach ($lines as $line) {
            if (preg_match('~^HTTP/\S+ (\d+)~', $line, $m) === 1) {
                $status = (int) $m[1];
            } elseif (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $parsed[strtolower($name)] = trim($value);
            }
        }
        if (isset($parsed['set-cookie']) && preg_match('/threadsafe_restore=([0-9a-f]+)/', $parsed['set-cookie'], $m) === 1) {
            $this->cookie = $m[1];
        }
        if (preg_match('/name="csrf" value="([0-9a-f]+)"/', (string) $body, $m) === 1) {
            $this->csrf = $m[1];
        }

        return ['status' => $status, 'headers' => $parsed, 'body' => (string) $body];
    }

    /**
     * @param array{status: int, headers: array<string, string>, body: string} $response
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function follow(array $response): array
    {
        for ($i = 0; $i < 5 && $response['status'] === 303; ++$i) {
            $response = $this->request('GET', '/' . $response['headers']['location']);
        }

        return $response;
    }

    private function checksum(string $database, string $table): string
    {
        $statement = $this->admin->query("CHECKSUM TABLE `$database`.`$table`");
        self::assertNotFalse($statement);
        $row = $statement->fetch(PDO::FETCH_NUM);

        return is_array($row) ? (string) $row[1] : '';
    }
}
