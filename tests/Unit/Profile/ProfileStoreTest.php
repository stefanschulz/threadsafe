<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Profile;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Profile\ProfileException;
use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Profile\SiteProfile;
use ThreadSafe\Tests\Support\TempDirectory;

final class ProfileStoreTest extends TestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    private function profile(string $id = 'shop'): SiteProfile
    {
        return SiteProfile::fromArray([...SiteProfileTest::minimal(), 'id' => $id, 'database' => ['name' => 'db', 'user' => 'u', 'password' => 'pw']]);
    }

    public function testSaveAndLoad(): void
    {
        $store = new ProfileStore($this->tempDirectory() . '/profiles');

        self::assertFalse($store->exists('shop'));
        $store->save($this->profile());

        self::assertTrue($store->exists('shop'));
        self::assertSame($this->profile()->toArray(), $store->load('shop')->toArray());
    }

    public function testFileAndDirectoryAreNotReadableByOthers(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('POSIX permissions');
        }
        $directory = $this->tempDirectory() . '/profiles';
        $store = new ProfileStore($directory);
        $store->save($this->profile());

        self::assertSame('0700', substr(sprintf('%o', fileperms($directory)), -4));
        self::assertSame('0600', substr(sprintf('%o', fileperms($directory . '/shop.json')), -4));
        self::assertSame(['shop.json'], array_values(array_diff(scandir($directory) ?: [], ['.', '..'])), 'No temporary file is left.');
    }

    public function testSavingAgainReplacesTheFile(): void
    {
        $store = new ProfileStore($this->tempDirectory());
        $store->save($this->profile());
        $store->save($this->profile()->with(['note' => 'changed']));

        self::assertSame('changed', $store->load('shop')->note);
        self::assertSame(['shop'], $store->ids());
    }

    public function testIdsAndAllAreSortedAndSkipBrokenFiles(): void
    {
        $directory = $this->tempDirectory();
        $store = new ProfileStore($directory);
        $store->save($this->profile('b'));
        $store->save($this->profile('a'));
        file_put_contents($directory . '/broken.json', '{not json');
        file_put_contents($directory . '/Not Valid.json', '{}');
        file_put_contents($directory . '/notes.txt', 'x');
        file_put_contents($directory . '/wrongid.json', (string) json_encode($this->profile('other')->toArray()));

        self::assertSame(['a', 'b', 'broken', 'wrongid'], $store->ids());
        self::assertSame(['a', 'b'], array_map(static fn (SiteProfile $p): string => $p->id, $store->all()));
    }

    public function testDamagedFilesAreExplained(): void
    {
        $directory = $this->tempDirectory();
        $store = new ProfileStore($directory);
        file_put_contents($directory . '/broken.json', '{not json');
        file_put_contents($directory . '/invalid.json', '{"id": "invalid", "type": "x"}');
        file_put_contents($directory . '/wrongid.json', (string) json_encode($this->profile('other')->toArray()));
        file_put_contents($directory . '/list.json', '[1,2]');

        foreach ([['broken', 'damaged'], ['invalid', 'Profile "invalid"'], ['wrongid', 'says its id is "other"'], ['list', 'JSON object']] as [$id, $message]) {
            try {
                $store->load($id);
                self::fail("Loaded $id");
            } catch (ProfileException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function testMissingProfile(): void
    {
        $this->expectException(ProfileException::class);
        $this->expectExceptionMessage('There is no profile "nope"');

        (new ProfileStore($this->tempDirectory()))->load('nope');
    }

    public function testIdsCannotLeaveTheDirectory(): void
    {
        $directory = $this->tempDirectory();
        file_put_contents(dirname($directory) . '/secret.json', '{}');
        $store = new ProfileStore($directory);

        foreach (['../secret', '..', 'a/b', '', 'A', "x\0y"] as $id) {
            self::assertFalse($store->exists($id), $id);
            try {
                $store->load($id);
                self::fail('Loaded ' . json_encode($id));
            } catch (ProfileException $e) {
                self::assertStringContainsString('not a valid profile id', $e->getMessage());
            }
        }
        $store->delete('../secret');
        self::assertFileExists(dirname($directory) . '/secret.json', 'delete() does not follow a path in an id.');
        @unlink(dirname($directory) . '/secret.json');
    }

    public function testDelete(): void
    {
        $store = new ProfileStore($this->tempDirectory());
        $store->save($this->profile());
        $store->delete('shop');
        $store->delete('shop');

        self::assertFalse($store->exists('shop'));
    }

    public function testSavingIntoAnUnwritableLocationFails(): void
    {
        $file = $this->tempDirectory() . '/a-file';
        file_put_contents($file, 'x');

        $this->expectException(ProfileException::class);

        (new ProfileStore($file . '/profiles'))->save($this->profile());
    }
}
