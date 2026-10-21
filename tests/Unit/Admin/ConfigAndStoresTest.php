<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Admin\AdminStore;
use ThreadSafe\Admin\AuditLog;
use ThreadSafe\Admin\Config;
use ThreadSafe\Admin\ConfigException;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * The three files of the web interface in the data directory: settings, the administrator, the audit log.
 */
final class ConfigAndStoresTest extends TestCase
{
    use TempDirectory;

    private string $directory;

    private int $now = 1_800_000_000;

    protected function setUp(): void
    {
        $this->directory = $this->tempDirectory();
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    // ------------------------------------------------------------------------------------------------- Config

    public function testWithoutAFileThereAreTheDefaultsAndTheInterfaceIsOff(): void
    {
        $config = Config::load($this->directory . '/config.json');

        self::assertFalse($config->enabled());
        self::assertTrue($config->prettyUrls());
        self::assertSame(7200, $config->sessionIdleSeconds());
        self::assertSame(524288000, $config->get('download.warnAbove'));
        self::assertCount(count(Config::SCHEMA), $config->all());
    }

    public function testASettingIsSavedAndReadBackAndOnlyTheChangedOnesAreInTheFile(): void
    {
        $file = $this->directory . '/config.json';
        Config::defaults()->with('web.enabled', true)->with('web.sessionIdleMinutes', '30')->save($file);

        $config = Config::load($file);
        self::assertTrue($config->enabled());
        self::assertSame(1800, $config->sessionIdleSeconds());
        self::assertSame(['web' => ['enabled' => true, 'sessionIdleMinutes' => 30]], json_decode((string) file_get_contents($file), true));
        if (DIRECTORY_SEPARATOR === '/') {
            self::assertSame(0600, fileperms($file) & 0777);
        }
    }

    public function testDefaultsAreSavedAsAnEmptyObject(): void
    {
        Config::defaults()->save($this->directory . '/config.json');

        self::assertSame('{}', trim((string) file_get_contents($this->directory . '/config.json')));
        self::assertFalse(Config::load($this->directory . '/config.json')->enabled());
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function badSettings(): iterable
    {
        yield 'unknown key' => ['web.colour', 'red'];
        yield 'bool as text' => ['web.enabled', 'maybe'];
        yield 'idle too short' => ['web.sessionIdleMinutes', 1];
        yield 'idle too long' => ['web.sessionIdleMinutes', 100000];
        yield 'idle not a number' => ['web.sessionIdleMinutes', 'soon'];
        yield 'accel unknown' => ['download.accel', 'magic'];
        yield 'warn below a megabyte' => ['download.warnAbove', 10];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badSettings')]
    public function testASettingOutOfRangeIsRefused(string $key, mixed $value): void
    {
        $this->expectException(ConfigException::class);

        Config::defaults()->with($key, $value);
    }

    public function testDamagedFilesAreRefusedWithAMessage(): void
    {
        $file = $this->directory . '/config.json';
        foreach (['{', '[1]', '{"web": 1}', '{"web": {"colour": 1}}', '{"web": {"enabled": "maybe"}}'] as $content) {
            file_put_contents($file, $content);
            try {
                Config::load($file);
                self::fail("$content should be refused");
            } catch (ConfigException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
    }

    // ------------------------------------------------------------------------------------------------- AdminStore

    public function testThePasswordIsKeptOnlyAsAHashAndChecks(): void
    {
        $store = new AdminStore($this->directory . '/admin.json');
        self::assertFalse($store->exists());

        $store->setPassword('admin', 'correct horse battery');

        self::assertTrue($store->exists());
        $file = (string) file_get_contents($this->directory . '/admin.json');
        self::assertStringNotContainsString('correct horse', $file);
        self::assertMatchesRegularExpression('/\$(argon2id|2y)\$/', $file);
        self::assertTrue($store->verify('admin', 'correct horse battery'));
        self::assertFalse($store->verify('admin', 'correct horse batterz'));
        self::assertFalse($store->verify('nobody', 'correct horse battery'), 'no such user');
        self::assertFalse($store->verify('admin', ''));
        if (DIRECTORY_SEPARATOR === '/') {
            self::assertSame(0600, fileperms($this->directory . '/admin.json') & 0777);
        }
    }

    public function testChangingThePasswordReplacesTheOldOne(): void
    {
        $store = new AdminStore($this->directory . '/admin.json');
        $store->setPassword('admin', 'first password here');
        $store->setPassword('admin', 'second password here');

        self::assertFalse($store->verify('admin', 'first password here'));
        self::assertTrue($store->verify('admin', 'second password here'));
        $data = json_decode((string) file_get_contents($this->directory . '/admin.json'), true);
        self::assertIsArray($data);
        self::assertCount(1, $data['users'], 'still one user');
    }

    public function testAWeakPasswordOrAHostileNameIsRefused(): void
    {
        $store = new AdminStore($this->directory . '/admin.json');

        foreach ([['admin', 'short'], ['Admin', 'long enough password'], ['../x', 'long enough password'], ['', 'long enough password']] as [$user, $password]) {
            try {
                $store->setPassword($user, $password);
                self::fail("$user / $password should be refused");
            } catch (ConfigException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
        self::assertFalse($store->exists());
    }

    public function testAnOldHashIsRenewedOnALogin(): void
    {
        $store = new AdminStore($this->directory . '/admin.json');
        file_put_contents($this->directory . '/admin.json', json_encode(['users' => [['name' => 'admin', 'hash' => password_hash('long enough password', PASSWORD_BCRYPT, ['cost' => 4]), 'changed' => '']]]));

        self::assertTrue($store->verify('admin', 'long enough password'));

        $data = json_decode((string) file_get_contents($this->directory . '/admin.json'), true);
        self::assertIsArray($data);
        self::assertSame(AdminStore::algorithmName() === 'argon2id' ? 'argon2id' : 'bcrypt', password_get_info($data['users'][0]['hash'])['algoName']);
        self::assertTrue($store->verify('admin', 'long enough password'));
    }

    public function testADamagedAdministratorFileMeansNoAdministrator(): void
    {
        file_put_contents($this->directory . '/admin.json', '{"users": [{"name": "admin"}]}');

        self::assertFalse((new AdminStore($this->directory . '/admin.json'))->exists());
    }

    // ------------------------------------------------------------------------------------------------- AuditLog

    private function audit(int $maxBytes = 5242880): AuditLog
    {
        return new AuditLog($this->directory . '/audit.log', $maxBytes, fn (): int => $this->now);
    }

    public function testEntriesAreOneLineEachAndComeBackNewestFirst(): void
    {
        $audit = $this->audit();
        $audit->record('login', 'admin', '203.0.113.7');
        $this->now += 60;
        $audit->record('backup.started', 'admin', '203.0.113.7', ['profile' => 'shop', 'force' => false, 'files' => 12]);

        $lines = file($this->directory . '/audit.log', FILE_IGNORE_NEW_LINES);
        self::assertCount(2, $lines ?: []);
        $recent = $audit->recent(5);
        self::assertSame(['backup.started', 'login'], array_column($recent, 'event'));
        self::assertSame('shop', $recent[0]['profile']);
        self::assertSame(12, $recent[0]['files']);
        self::assertSame('2027-01-15T08:01:00Z', $recent[0]['time']);
        self::assertCount(1, $audit->recent(1));
    }

    public function testSecretsNewlinesAndLongTextsNeverReachTheFile(): void
    {
        $audit = $this->audit();

        $audit->record("login\nINJECTED", "ad\nmin", '1.2.3.4', [
            'password' => 'hunter2hunter2',
            'dbPassword' => 'hunter3hunter3',
            'setupKey' => 'hunter4hunter4',
            'token' => 'hunter5hunter5',
            'note' => "line one\nline two",
            'long' => str_repeat('ä', 500),
            'array' => ['x'],
        ]);

        $file = (string) file_get_contents($this->directory . '/audit.log');
        self::assertStringNotContainsString('hunter', $file);
        self::assertCount(1, explode("\n", trim($file)), 'one line');
        $entry = $audit->recent(1)[0];
        self::assertSame('login INJECTED', $entry['event']);
        self::assertSame('line one line two', $entry['note']);
        self::assertSame(200, mb_strlen($entry['long']));
        self::assertArrayNotHasKey('array', $entry);
    }

    public function testTheLogIsRotatedAtItsSizeAndTwoOldOnesAreKept(): void
    {
        $audit = $this->audit(400);
        for ($i = 0; $i < 30; ++$i) {
            $audit->record('event', 'admin', '', ['n' => $i]);
        }

        self::assertFileExists($this->directory . '/audit.log.1');
        self::assertFileExists($this->directory . '/audit.log.2');
        self::assertFileDoesNotExist($this->directory . '/audit.log.3');
        $recent = $audit->recent(100);
        self::assertSame(29, $recent[0]['n'], 'the newest entry first');
        $numbers = array_column($recent, 'n');
        $sorted = $numbers;
        rsort($sorted);
        self::assertSame($sorted, $numbers, 'in order across the files');
    }
}
