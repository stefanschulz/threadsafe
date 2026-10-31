<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Admin\AdminStore;
use ThreadSafe\Auth\RecoveryCodes;
use ThreadSafe\Auth\Totp;
use ThreadSafe\Auth\TwoFactorException;
use ThreadSafe\Auth\TwoFactorStore;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * The file of the second factor: what it holds and how it is protected, the code that is accepted once, the recovery codes that work
 * once, and that a file which cannot be read never reads as "off".
 */
final class TwoFactorStoreTest extends TestCase
{
    use TempDirectory;

    private const string SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    private const int NOW = 1_800_000_000;

    private string $directory;

    private string $file;

    protected function setUp(): void
    {
        $this->directory = $this->tempDirectory() . '/data';
        $this->file = $this->directory . '/two-factor.json';
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    private function store(int $now = self::NOW): TwoFactorStore
    {
        return new TwoFactorStore($this->file, static fn (): int => $now);
    }

    /** @return list<string> */
    private function enable(string $user = 'admin'): array
    {
        $codes = RecoveryCodes::generate();
        $this->store()->enable($user, self::SECRET, $codes);

        return $codes;
    }

    public function testWithoutAFileTheFactorIsOffAndNothingIsWritten(): void
    {
        $store = $this->store();

        self::assertFalse($store->isOn('admin'));
        self::assertNull($store->secret('admin'));
        self::assertNull($store->enrolled('admin'));
        self::assertSame(0, $store->recoveryLeft('admin'));
        self::assertFalse($store->acceptCode('admin', '123456', self::NOW));
        self::assertFalse($store->useRecoveryCode('admin', 'AAAA-AAAA-AAAA'));
        self::assertFalse($store->disable('admin'));
        self::assertFalse($store->fileExists());
    }

    public function testSwitchingOnWritesAPrivateFileWithTheListFormAndOnlyHashes(): void
    {
        $codes = $this->enable();

        self::assertSame(0600, fileperms($this->file) & 0777);
        self::assertSame(0700, fileperms($this->directory) & 0777);
        $data = json_decode((string) file_get_contents($this->file), true);
        self::assertIsArray($data);
        self::assertSame('admin', $data['users'][0]['name']);
        self::assertSame(self::SECRET, $data['users'][0]['secret']);
        self::assertSame('2027-01-15T08:00:00Z', $data['users'][0]['enrolled']);
        self::assertSame(0, $data['users'][0]['lastStep']);
        self::assertCount(10, $data['users'][0]['recovery']);

        $content = (string) file_get_contents($this->file);
        foreach ($codes as $code) {
            self::assertStringNotContainsString($code, $content, 'a recovery code is never stored');
            self::assertStringNotContainsString(str_replace('-', '', $code), $content);
        }
    }

    public function testWhatWasSwitchedOnCanBeRead(): void
    {
        $this->enable();
        $store = $this->store();

        self::assertTrue($store->isOn('admin'));
        self::assertFalse($store->isOn('someone-else'));
        self::assertSame(self::SECRET, $store->secret('admin'));
        self::assertSame(10, $store->recoveryLeft('admin'));
    }

    public function testASecretThatIsNotBase32IsRefused(): void
    {
        foreach (['', 'short', 'not base32 at all, with spaces and 1s', str_repeat('1', 32)] as $secret) {
            try {
                $this->store()->enable('admin', $secret, []);
                self::fail("accepted \"$secret\"");
            } catch (TwoFactorException) {
                self::assertFalse($this->store()->fileExists());
            }
        }
    }

    public function testARightCodeIsAcceptedOnceAndAcrossRequests(): void
    {
        $this->enable();
        $code = Totp::code(self::SECRET, Totp::step(self::NOW));

        self::assertTrue($this->store()->acceptCode('admin', $code, self::NOW));
        self::assertFalse($this->store()->acceptCode('admin', $code, self::NOW), 'the same code again, in a new request');
        self::assertFalse($this->store()->acceptCode('admin', $code, self::NOW + 30), 'and still when it is the previous step of the window');
        self::assertFalse($this->store()->acceptCode('admin', Totp::code(self::SECRET, Totp::step(self::NOW) - 1), self::NOW), 'an older code');
        self::assertTrue($this->store()->acceptCode('admin', Totp::code(self::SECRET, Totp::step(self::NOW) + 1), self::NOW), 'the next one');
    }

    public function testAWrongCodeChangesNothing(): void
    {
        $this->enable();
        $before = (string) file_get_contents($this->file);

        self::assertFalse($this->store()->acceptCode('admin', '000000', self::NOW));
        self::assertFalse($this->store()->acceptCode('admin', 'not a code', self::NOW));
        self::assertSame($before, (string) file_get_contents($this->file));
        self::assertTrue($this->store()->acceptCode('admin', Totp::code(self::SECRET, Totp::step(self::NOW)), self::NOW), 'and the right one still works');
    }

    public function testARecoveryCodeWorksOnceAndInAnyWayOfWritingIt(): void
    {
        $codes = $this->enable();

        self::assertTrue($this->store()->useRecoveryCode('admin', strtolower($codes[3])));
        self::assertFalse($this->store()->useRecoveryCode('admin', $codes[3]), 'the second time');
        self::assertSame(9, $this->store()->recoveryLeft('admin'));
        self::assertTrue($this->store()->useRecoveryCode('admin', str_replace('-', ' ', $codes[0])));
        self::assertSame(8, $this->store()->recoveryLeft('admin'));
        self::assertFalse($this->store()->useRecoveryCode('admin', 'ZZZZ-ZZZZ-ZZZZ'), 'one that was never made');
        self::assertSame(8, $this->store()->recoveryLeft('admin'));
    }

    public function testNewRecoveryCodesReplaceAllOfTheOldOnes(): void
    {
        $old = $this->enable();
        $new = RecoveryCodes::generate();

        $this->store()->replaceRecoveryCodes('admin', $new);

        self::assertFalse($this->store()->useRecoveryCode('admin', $old[0]));
        self::assertTrue($this->store()->useRecoveryCode('admin', $new[0]));
        self::assertSame(self::SECRET, $this->store()->secret('admin'), 'the secret is not touched');
    }

    public function testNewRecoveryCodesNeedTheFactorToBeOn(): void
    {
        $this->expectException(TwoFactorException::class);

        $this->store()->replaceRecoveryCodes('admin', RecoveryCodes::generate());
    }

    public function testSwitchingOffRemovesTheFileWhenNobodyIsLeft(): void
    {
        $this->enable();

        self::assertTrue($this->store()->disable('admin'));
        self::assertFalse($this->store()->fileExists());
        self::assertFalse($this->store()->isOn('admin'));
        self::assertFalse($this->store()->disable('admin'), 'the second time there is nothing to do');
    }

    public function testSwitchingOffOneUserLeavesTheOther(): void
    {
        $this->enable('admin');
        $this->enable('second');

        self::assertTrue($this->store()->disable('admin'));
        self::assertTrue($this->store()->isOn('second'));
        self::assertSame(self::SECRET, $this->store()->secret('second'));
    }

    public function testSwitchingOnAgainStartsFromZero(): void
    {
        $this->enable();
        $this->store()->acceptCode('admin', Totp::code(self::SECRET, Totp::step(self::NOW)), self::NOW);
        $this->enable();

        self::assertTrue($this->store()->acceptCode('admin', Totp::code(self::SECRET, Totp::step(self::NOW)), self::NOW), 'the counter of the old secret does not carry over');
        self::assertSame(10, $this->store()->recoveryLeft('admin'));
    }

    public function testAChangeOfThePasswordLeavesTheFactorAlone(): void
    {
        $this->enable();
        $admin = new AdminStore($this->directory . '/admin.json');
        $admin->setPassword('admin', 'a first password, long enough');
        $admin->setPassword('admin', 'a second password, long enough');

        self::assertTrue($this->store()->isOn('admin'));
        self::assertSame(self::SECRET, $this->store()->secret('admin'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function damaged(): iterable
    {
        yield 'not JSON' => ['{ this is not json'];
        yield 'empty' => [''];
        yield 'a number' => ['7'];
        yield 'no list of users' => ['{"users": "admin"}'];
        yield 'users as an object' => ['{"users": {"admin": {}}}'];
        yield 'a user without a secret' => ['{"users": [{"name": "admin"}]}'];
        yield 'a user with a secret that is no key' => ['{"users": [{"name": "admin", "secret": "not a key"}]}'];
        yield 'a user without a name' => ['{"users": [{"secret": "GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ"}]}'];
    }

    #[DataProvider('damaged')]
    public function testAFileThatCannotBeUnderstoodIsNeverReadAsOff(string $content): void
    {
        mkdir($this->directory, 0700, true);
        file_put_contents($this->file, $content);

        foreach ([
            fn () => $this->store()->isOn('admin'),
            fn () => $this->store()->secret('admin'),
            fn () => $this->store()->acceptCode('admin', '123456', self::NOW),
            fn () => $this->store()->useRecoveryCode('admin', 'AAAA-AAAA-AAAA'),
            fn () => $this->store()->disable('admin'),
        ] as $call) {
            try {
                $call();
                self::fail('a damaged file was read as if it were fine');
            } catch (TwoFactorException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
        self::assertSame($content, file_get_contents($this->file), 'and the file is left as it was');
    }

    public function testAFileWithNoUsersIsOff(): void
    {
        mkdir($this->directory, 0700, true);
        file_put_contents($this->file, '{"users": []}');

        self::assertFalse($this->store()->isOn('admin'));
    }

    public function testRecoveryHashesThatAreNoHashesAreDropped(): void
    {
        mkdir($this->directory, 0700, true);
        file_put_contents($this->file, json_encode(['users' => [['name' => 'admin', 'secret' => self::SECRET, 'recovery' => ['nope', RecoveryCodes::hash('AAAA-BBBB-CCCC'), 5]]]]));

        self::assertSame(1, $this->store()->recoveryLeft('admin'));
        self::assertTrue($this->store()->useRecoveryCode('admin', 'aaaa-bbbb-cccc'));
    }

    public function testTheLockFileIsPrivateAndHarmless(): void
    {
        $this->enable();

        self::assertSame(0600, fileperms($this->file . '.lock') & 0777);
        $this->store()->disable('admin');
        self::assertFalse($this->store()->isOn('admin'), 'the lock file left behind means nothing');
    }
}
