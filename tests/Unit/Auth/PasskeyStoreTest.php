<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Auth\Base64Url;
use ThreadSafe\Auth\CoseKey;
use ThreadSafe\Auth\PasskeyCredential;
use ThreadSafe\Auth\PasskeyStore;
use ThreadSafe\Auth\PasskeyStoreException;
use ThreadSafe\Auth\Passkeys;
use ThreadSafe\Tests\Support\SoftwareAuthenticator as Device;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * The file of the passkeys: what it holds, that it is public data in a closed file, that the first passkey fixes the host name, the limit of
 * ten, the counter that a login writes, and that a file which cannot be read is an error and never "no passkeys" (docs/PHASE14.md 3.4, 6).
 */
final class PasskeyStoreTest extends TestCase
{
    use TempDirectory;

    private const string RP = 'admin.example.test';
    private const int NOW = 1_800_000_000;

    private string $file;

    protected function setUp(): void
    {
        $this->file = $this->tempDirectory() . '/data/passkeys.json';
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    private function store(int $now = self::NOW): PasskeyStore
    {
        return new PasskeyStore($this->file, static fn (): int => $now);
    }

    /** A passkey that a registration made, with the user handle of the store. */
    private function made(PasskeyStore $store, int $algorithm = CoseKey::ES256, bool $synced = false): PasskeyCredential
    {
        $rp = new Passkeys(self::RP, 'https://' . self::RP);
        $challenge = Passkeys::newChallenge();
        $device = new Device($algorithm, 'https://' . self::RP, self::RP, $synced);

        return $rp->verifyRegistration($challenge, $device->register($rp->registrationOptions($challenge, $store->userHandle(), 'admin', [])), []);
    }

    public function testNoFileMeansNoPasskeys(): void
    {
        $store = $this->store();

        self::assertFalse($store->fileExists());
        self::assertSame(0, $store->count());
        self::assertNull($store->rpId());
        self::assertSame([], $store->all());
        self::assertSame(64, strlen($store->userHandle()));
        self::assertFileDoesNotExist($this->file, 'asking writes nothing');
    }

    public function testAPasskeyIsStoredAndReadBackWithEverythingTheDeviceSaid(): void
    {
        $store = $this->store();
        $handle = $store->userHandle();
        $credential = $this->made($store, CoseKey::EDDSA, true);

        $store->add('admin', self::RP, $handle, $credential, '  My phone  ');

        $again = $this->store();
        self::assertSame(1, $again->count());
        self::assertSame(self::RP, $again->rpId());
        self::assertSame($handle, $again->userHandle(), 'the handle that the first passkey was made with stays');
        $stored = $again->all()[0];
        self::assertSame('My phone', $stored->name);
        self::assertSame('2027-01-15T08:00:00Z', $stored->created);
        self::assertNull($stored->lastUsed);
        self::assertSame('synced', $stored->kind());
        self::assertSame($credential->id, $stored->credential->id);
        self::assertSame($credential->key->toCbor(), $stored->credential->key->toCbor());
        self::assertSame([$credential->id], array_map(static fn (PasskeyCredential $c): string => $c->id, $again->credentials()));
    }

    public function testTheFileIsPrivateAndHoldsNoSecret(): void
    {
        $store = $this->store();
        $store->add('admin', self::RP, $store->userHandle(), $this->made($store), 'Key');

        if (DIRECTORY_SEPARATOR === '/') {
            self::assertSame('0600', substr(sprintf('%o', fileperms($this->file)), -4));
        }
        $json = (string) file_get_contents($this->file);
        self::assertStringNotContainsString('PRIVATE', $json);
        self::assertSame(['user', 'rpId', 'userHandle', 'passkeys'], array_keys((array) json_decode($json, true)));
        self::assertSame([], glob($this->file . '.*.tmp') ?: [], 'no temporary file is left');
    }

    public function testTheFirstPasskeyFixesTheHostNameAndTheHandle(): void
    {
        $store = $this->store();
        $handle = $store->userHandle();
        $store->add('admin', self::RP, $handle, $this->made($store), 'One');

        $this->expectException(PasskeyStoreException::class);
        $this->expectExceptionMessageMatches('/another host name/');
        $store->add('admin', 'other.example.test', $handle, $this->made($store), 'Two');
    }

    public function testAnotherUserHandleIsRefused(): void
    {
        $store = $this->store();
        $store->add('admin', self::RP, $store->userHandle(), $this->made($store), 'One');

        $this->expectException(PasskeyStoreException::class);
        $store->add('admin', self::RP, random_bytes(64), $this->made($store), 'Two');
    }

    public function testThePasskeyOfAnotherDeviceIsAddedAndTheSameOneIsNot(): void
    {
        $store = $this->store();
        $handle = $store->userHandle();
        $first = $this->made($store);
        $store->add('admin', self::RP, $handle, $first, 'One');
        $store->add('admin', self::RP, $handle, $this->made($store, CoseKey::RS256), 'Two');
        self::assertSame(2, $store->count());

        $this->expectException(PasskeyStoreException::class);
        $this->expectExceptionMessageMatches('/stored already/');
        $store->add('admin', self::RP, $handle, $first, 'Again');
    }

    public function testAtMostTenPasskeysAreStored(): void
    {
        $store = $this->store();
        $handle = $store->userHandle();
        for ($i = 0; $i < PasskeyStore::MAX_PASSKEYS; $i++) {
            $store->add('admin', self::RP, $handle, $this->made($store), 'Key ' . $i);
        }

        $this->expectException(PasskeyStoreException::class);
        $this->expectExceptionMessageMatches('/remove one first/');
        $store->add('admin', self::RP, $handle, $this->made($store), 'Eleven');
    }

    /** @return iterable<string, array{string, string}> */
    public static function names(): iterable
    {
        yield 'trimmed' => ["  Laptop \n", 'Laptop'];
        yield 'control characters become spaces' => ["Lap\x00top", 'Lap top'];
        yield 'empty gets a number' => ['   ', 'Passkey 1'];
        yield 'cut at 64 characters' => [str_repeat('x', 100), str_repeat('x', PasskeyStore::MAX_NAME)];
        yield 'umlauts count as one character' => [str_repeat("\u{e4}", 70), str_repeat("\u{e4}", PasskeyStore::MAX_NAME)];
    }

    #[DataProvider('names')]
    public function testTheNameIsCleaned(string $given, string $expected): void
    {
        $store = $this->store();
        $store->add('admin', self::RP, $store->userHandle(), $this->made($store), $given);

        self::assertSame($expected, $store->all()[0]->name);
    }

    public function testALoginWritesTheCounterTheStateOfTheBackupAndTheTime(): void
    {
        $store = $this->store();
        $credential = $this->made($store);
        $store->add('admin', self::RP, $store->userHandle(), $credential, 'Key');

        $this->store(self::NOW + 3600)->recordLogin($credential->afterLogin(7, true));

        $stored = $this->store()->all()[0];
        self::assertSame(7, $stored->credential->signCount);
        self::assertTrue($stored->credential->backupState);
        self::assertSame('2027-01-15T09:00:00Z', $stored->lastUsed);
        self::assertSame('2027-01-15T08:00:00Z', $stored->created);
        self::assertSame('Key', $stored->name);
    }

    public function testAPasskeyIsRenamedAndRemoved(): void
    {
        $store = $this->store();
        $handle = $store->userHandle();
        $a = $this->made($store);
        $b = $this->made($store, CoseKey::EDDSA);
        $store->add('admin', self::RP, $handle, $a, 'A');
        $store->add('admin', self::RP, $handle, $b, 'B');

        self::assertTrue($store->rename($a->id, 'Phone'));
        self::assertFalse($store->rename('nobody', 'x'));
        self::assertSame(['Phone', 'B'], array_map(static fn ($p): string => $p->name, $store->all()));

        self::assertTrue($store->remove($a->id));
        self::assertFalse($store->remove($a->id));
        self::assertSame(1, $store->count());

        self::assertTrue($store->remove($b->id));
        self::assertFileDoesNotExist($this->file, 'the last one takes the file with it');
        self::assertSame(0, $store->count());
    }

    public function testRemoveAllRemovesTheFileAndSaysHowManyWereThere(): void
    {
        $store = $this->store();
        $handle = $store->userHandle();
        $store->add('admin', self::RP, $handle, $this->made($store), 'A');
        $store->add('admin', self::RP, $handle, $this->made($store), 'B');

        self::assertSame(2, $store->removeAll());
        self::assertFileDoesNotExist($this->file);
        self::assertSame(0, $store->removeAll());
    }

    /** @return iterable<string, array{string}> */
    public static function damagedFiles(): iterable
    {
        yield 'not JSON' => ['{ damaged'];
        yield 'a list' => ['[1,2]'];
        yield 'no passkeys' => ['{"user":"admin","rpId":"h","userHandle":"AAAA","passkeys":[]}'];
        yield 'no host name' => ['{"user":"admin","userHandle":"AAAA","passkeys":[{}]}'];
        yield 'an entry without a key' => ['{"user":"admin","rpId":"h","userHandle":"AAAA","passkeys":[{"id":"AAAA","name":"x"}]}'];
        yield 'a handle that is not base64url' => ['{"user":"admin","rpId":"h","userHandle":"A=","passkeys":[{}]}'];
    }

    #[DataProvider('damagedFiles')]
    public function testAFileThatCannotBeReadIsAnErrorAndNeverNoPasskeys(string $content): void
    {
        mkdir(dirname($this->file), 0700, true);
        file_put_contents($this->file, $content);
        $store = $this->store();

        self::assertTrue($store->fileExists());
        foreach ([static fn () => $store->count(), static fn () => $store->all(), static fn () => $store->rpId(), static fn () => $store->credentials()] as $read) {
            try {
                $read();
                self::fail('a damaged file was read as if it were fine');
            } catch (PasskeyStoreException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAKeyThatDoesNotParseMakesTheFileDamaged(): void
    {
        $store = $this->store();
        $credential = $this->made($store);
        $store->add('admin', self::RP, $store->userHandle(), $credential, 'Key');
        $data = json_decode((string) file_get_contents($this->file), true);
        $data['passkeys'][0]['key'] = Base64Url::encode("\xa0");
        file_put_contents($this->file, json_encode($data));

        $this->expectException(PasskeyStoreException::class);
        $this->expectExceptionMessageMatches('/cannot be used/');
        $store->all();
    }

    public function testRemoveAllRemovesADamagedFile(): void
    {
        mkdir(dirname($this->file), 0700, true);
        file_put_contents($this->file, '{ damaged');

        self::assertSame(0, $this->store()->removeAll());
        self::assertFileDoesNotExist($this->file);
    }

    public function testTwoStoresWritingTogetherLoseNothing(): void
    {
        $a = $this->store();
        $b = $this->store();
        $handle = $a->userHandle();
        $a->add('admin', self::RP, $handle, $this->made($a), 'A');
        $b->add('admin', self::RP, $handle, $this->made($b), 'B');
        $a->add('admin', self::RP, $handle, $this->made($a), 'C');

        self::assertSame(['A', 'B', 'C'], array_map(static fn ($p): string => $p->name, $this->store()->all()));
    }
}
