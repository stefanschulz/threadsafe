<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Profile;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Profile\ProfileException;
use ThreadSafe\Profile\SiteProfile;

final class SiteProfileTest extends TestCase
{
    /** @return array<string, mixed> */
    public static function minimal(): array
    {
        return ['id' => 'shop', 'type' => 'prestashop9', 'path' => '/var/www/shop', 'storage' => '/var/backups/shop'];
    }

    public function testMinimalProfileGetsDefaults(): void
    {
        $profile = SiteProfile::fromArray(self::minimal());

        self::assertSame('shop', $profile->name, 'The name defaults to the id.');
        self::assertNull($profile->database);
        self::assertSame([], $profile->excludePaths);
        self::assertSame([], $profile->structureOnlyTables);
        self::assertTrue($profile->useDefaultFilters);
        self::assertSame(6, $profile->compression);
        self::assertNull($profile->partSize);
        self::assertSame('', $profile->note);
    }

    public function testRoundTripThroughData(): void
    {
        $data = [
            'id' => 'my_shop-2',
            'name' => 'Webshop (live) ü',
            'type' => 'generic',
            'path' => '/var/www/shop',
            'database' => ['host' => 'db.example', 'port' => 3307, 'name' => 'shop', 'user' => 'u', 'password' => "p'w\"\\", 'prefix' => 'ps_', 'driver' => 'mysqli'],
            'storage' => 'C:\\backups\\shop',
            'excludePaths' => ['var/log-old', '*.bak'],
            'structureOnlyTables' => ['log', 'ps_stats'],
            'useDefaultFilters' => false,
            'compression' => 9,
            'partSize' => 2147483648,
            'note' => 'live, shared hosting',
        ];

        $profile = SiteProfile::fromArray($data);

        self::assertSame($data, $profile->toArray());
        self::assertSame($data, SiteProfile::fromArray(json_decode((string) json_encode($profile->toArray()), true))->toArray());
        self::assertSame('mysqli', $profile->database?->driver);
    }

    public function testThePasswordIsHiddenOnRequest(): void
    {
        $profile = SiteProfile::fromArray([...self::minimal(), 'database' => ['name' => 'shop', 'user' => 'u', 'password' => 'secret']]);

        self::assertSame('secret', $profile->toArray()['database']['password']);
        self::assertSame('********', $profile->toArray(withPassword: false)['database']['password']);
        self::assertStringNotContainsString('secret', (string) json_encode($profile->toArray(false)));
    }

    public function testDatabaseDefaults(): void
    {
        $database = SiteProfile::fromArray([...self::minimal(), 'database' => ['name' => 'shop', 'user' => 'u']])->database;

        self::assertSame(['localhost', 3306, '', '', 'auto'], [$database?->host, $database?->port, $database?->password, $database?->prefix, $database?->driver]);
    }

    public function testWithChangesValidatesAgain(): void
    {
        $profile = SiteProfile::fromArray(self::minimal());

        self::assertSame(9, $profile->with(['compression' => 9])->compression);
        self::assertSame('shop', $profile->with(['compression' => 9])->id);
        $this->expectException(ProfileException::class);

        $profile->with(['compression' => 10]);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> changes to the minimal profile, fragment of the message */
    public static function invalid(): iterable
    {
        yield 'no id' => [['id' => null], '"id" is missing'];
        yield 'id with capital' => [['id' => 'Shop'], 'profile id'];
        yield 'id with slash' => [['id' => '../x'], 'profile id'];
        yield 'id starting with hyphen' => [['id' => '-x'], 'profile id'];
        yield 'id too long' => [['id' => str_repeat('a', 41)], 'profile id'];
        yield 'id not a string' => [['id' => 5], '"id" must be'];
        yield 'type with capital' => [['type' => 'PrestaShop'], 'website type'];
        yield 'no type' => [['type' => null], '"type" is missing'];
        yield 'empty name' => [['name' => '  '], 'name'];
        yield 'name with newline' => [['name' => "a\nb"], 'name'];
        yield 'relative path' => [['path' => 'shop'], 'absolute path'];
        yield 'path with parent' => [['path' => '/var/www/../etc'], '".."'];
        yield 'storage relative' => [['storage' => 'backups'], 'absolute path'];
        yield 'storage with NUL' => [['storage' => "/var/\0x"], 'absolute path'];
        yield 'compression too high' => [['compression' => 10], 'compression'];
        yield 'compression negative' => [['compression' => -1], 'compression'];
        yield 'compression as text' => [['compression' => '6'], 'compression'];
        yield 'part size too small' => [['partSize' => 1000], 'partSize'];
        yield 'part size as text' => [['partSize' => '2G'], 'partSize'];
        yield 'default filters as text' => [['useDefaultFilters' => 'yes'], 'useDefaultFilters'];
        yield 'exclude not a list' => [['excludePaths' => 'var/cache'], 'list of strings'];
        yield 'exclude with number' => [['excludePaths' => [5]], 'list of strings'];
        yield 'exclude absolute' => [['excludePaths' => ['/var/cache']], 'must not start with a slash'];
        yield 'exclude with parent' => [['excludePaths' => ['../x']], '..'];
        yield 'empty table name' => [['structureOnlyTables' => ['']], 'table name'];
        yield 'unknown field' => [['colour' => 'red'], 'Unknown field "colour"'];
        yield 'database as string' => [['database' => 'x'], '"database" must be'];
        yield 'database as list' => [['database' => ['a']], '"database" must be'];
        yield 'database without name' => [['database' => ['user' => 'u']], 'database.name'];
        yield 'database without user' => [['database' => ['name' => 'n']], 'database.user'];
        yield 'database unknown field' => [['database' => ['name' => 'n', 'user' => 'u', 'pass' => 'x']], 'Unknown field "pass" in "database"'];
        yield 'database port text' => [['database' => ['name' => 'n', 'user' => 'u', 'port' => '3306']], 'port'];
        yield 'database port zero' => [['database' => ['name' => 'n', 'user' => 'u', 'port' => 0]], 'port'];
        yield 'database driver' => [['database' => ['name' => 'n', 'user' => 'u', 'driver' => 'odbc']], 'driver'];
        yield 'database prefix with space' => [['database' => ['name' => 'n', 'user' => 'u', 'prefix' => 'a b']], 'prefix'];
    }

    /**
     * @param array<string, mixed> $changes
     */
    #[DataProvider('invalid')]
    public function testInvalidProfilesAreRefused(array $changes, string $message): void
    {
        $data = [...self::minimal(), ...$changes];
        foreach ($changes as $key => $value) {
            if ($value === null && $key !== 'database') {
                unset($data[$key]);
            }
        }

        try {
            SiteProfile::fromArray($data);
            self::fail('Accepted ' . json_encode($changes));
        } catch (ProfileException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
    }

    public function testDuplicatesInListsAreDropped(): void
    {
        $profile = SiteProfile::fromArray([...self::minimal(), 'excludePaths' => ['a', 'a', 'b']]);

        self::assertSame(['a', 'b'], $profile->excludePaths);
    }

    public function testAProfileIsAnObjectNotAList(): void
    {
        $this->expectException(ProfileException::class);

        SiteProfile::fromArray(['a', 'b']);
    }
}
