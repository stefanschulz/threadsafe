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

    private const string KEY = 'age1ql3z7hjy54pw3hyww5ayyfg7zqgvc7w3j2elw8zmrj2kg5sfn9aqmcac8p';

    public function testScheduleRetentionAndEncryptionRoundTrip(): void
    {
        $data = [
            ...self::minimal(),
            'name' => 'shop',
            'schedule' => ['every' => 'day', 'at' => '03:30'],
            'retention' => ['keep' => 14, 'maxAgeDays' => 90, 'maxBytes' => 21474836480],
            'encryption' => ['recipients' => [self::KEY]],
        ];

        $profile = SiteProfile::fromArray($data);

        self::assertSame('30 3 * * *', $profile->schedule?->cron());
        self::assertSame([14, 90, 21474836480], [$profile->retention?->keep, $profile->retention?->maxAgeDays, $profile->retention?->maxBytes]);
        self::assertSame([self::KEY], $profile->encryption?->recipients);
        self::assertSame(['schedule', 'retention', 'encryption'], array_slice(array_keys($profile->toArray()), -3));
        self::assertSame($profile->toArray(), SiteProfile::fromArray(json_decode((string) json_encode($profile->toArray()), true))->toArray());
        self::assertSame('0 4 * * *', $profile->with(['schedule' => ['every' => 'day', 'at' => '04:00']])->schedule?->cron());
        self::assertSame([self::KEY], $profile->with(['note' => 'x'])->encryption?->recipients, 'with() keeps what it is not asked to change');
    }

    public function testAProfileWithoutThemIsWrittenWithoutThem(): void
    {
        $profile = SiteProfile::fromArray(self::minimal());

        self::assertNull($profile->schedule);
        self::assertNull($profile->retention);
        self::assertNull($profile->encryption);
        self::assertArrayNotHasKey('schedule', $profile->toArray());
        self::assertArrayNotHasKey('retention', $profile->toArray());
        self::assertArrayNotHasKey('encryption', $profile->toArray());
    }

    public function testNothingToApplyMeansNoRule(): void
    {
        $profile = SiteProfile::fromArray([...self::minimal(), 'schedule' => ['every' => null], 'retention' => [], 'encryption' => ['recipients' => []]]);
        $other = SiteProfile::fromArray([...self::minimal(), 'schedule' => null, 'retention' => ['keep' => null], 'encryption' => null]);

        foreach ([$profile, $other] as $each) {
            self::assertNull($each->schedule);
            self::assertNull($each->retention);
            self::assertNull($each->encryption);
        }
    }

    public function testTheScheduleBecomesTheCronExpressionOfTheEngine(): void
    {
        self::assertSame('0 3 * * *', SiteProfile::fromArray([...self::minimal(), 'schedule' => ['every' => 'day', 'at' => '3:00']])->schedule?->cron());
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidOptionalFields(): iterable
    {
        yield 'a schedule that is a string' => [['schedule' => '30 3 * * *'], '"schedule" must be'];
        yield 'a schedule that is a list' => [['schedule' => ['30 3 * * *']], '"schedule" must be'];
        yield 'an unknown schedule field' => [['schedule' => ['every' => 'day', 'at' => '03:00', 'zone' => 'UTC']], 'Unknown field "zone" in "schedule"'];
        yield 'a field of another kind of schedule' => [['schedule' => ['every' => 'day', 'at' => '03:00', 'interval' => 6]], 'Unknown field "interval" in "schedule" (known for "day"'];
        yield 'the old cron expression' => [['schedule' => ['cron' => '0 3 * * *']], '"schedule.every" is missing'];
        yield 'an unknown kind' => [['schedule' => ['every' => 'fortnight']], '"schedule.every" must be one of'];
        yield 'hours that do not divide a day' => [['schedule' => ['every' => 'hours', 'interval' => 5, 'minute' => 0]], '"schedule.interval" must be one of'];
        yield 'a time of day that is wrong' => [['schedule' => ['every' => 'day', 'at' => '25:00']], 'The hour of "schedule.at"'];
        yield 'a day of the month that some months lack' => [['schedule' => ['every' => 'month', 'day' => 31, 'at' => '03:00']], '"schedule.day" must be a day from 1 to 28'];
        yield 'weekly without a day' => [['schedule' => ['every' => 'week', 'on' => [], 'at' => '03:00']], 'at least one day of the week'];
        yield 'a paused flag that is text' => [['schedule' => ['every' => 'day', 'at' => '03:00', 'paused' => 'yes']], '"schedule.paused" must be true or false'];
        yield 'keep zero' => [['retention' => ['keep' => 0]], '"retention.keep" must be a whole number from 1'];
        yield 'keep as text' => [['retention' => ['keep' => '5']], '"retention.keep"'];
        yield 'an age of a century and more' => [['retention' => ['maxAgeDays' => 40000]], '"retention.maxAgeDays"'];
        yield 'a size below a megabyte' => [['retention' => ['maxBytes' => 1000]], '"retention.maxBytes"'];
        yield 'an unknown retention field' => [['retention' => ['keepAll' => true]], 'Unknown field "keepAll" in "retention"'];
        yield 'encryption that is a list' => [['encryption' => [self::KEY]], '"encryption" must be'];
        yield 'an unknown encryption field' => [['encryption' => ['passphrase' => 'x']], 'Unknown field "passphrase" in "encryption"'];
        yield 'recipients that are not a list' => [['encryption' => ['recipients' => self::KEY]], 'must be a list of public keys'];
        yield 'a recipient that is a secret key' => [['encryption' => ['recipients' => ['AGE-SECRET-KEY-1QQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQ']]], 'not a public key'];
        yield 'a recipient with a wrong letter' => [['encryption' => ['recipients' => ['age1bl3z7hjy54pw3hyww5ayyfg7zqgvc7w3j2elw8zmrj2kg5sfn9aqmcac8p']]], 'not a public key'];
        yield 'a recipient with a mistyped character (wrong checksum)' => [['encryption' => ['recipients' => ['age1ql3z7hjy54pw3hyww5ayyfg7zqgvc7w3j2elw8zmrj2kg5sfn9aqmcac8q']]], 'not a public key'];
        yield 'a recipient that is too short' => [['encryption' => ['recipients' => ['age1ql3z7hjy54pw']]], 'not a public key'];
    }

    /** @param array<string, mixed> $field */
    #[DataProvider('invalidOptionalFields')]
    public function testInvalidOptionalFieldsAreRefused(array $field, string $message): void
    {
        $this->expectException(ProfileException::class);
        $this->expectExceptionMessage($message);

        SiteProfile::fromArray([...self::minimal(), ...$field]);
    }

    public function testTheKeyOfTheExampleHasTheShapeOfARecipient(): void
    {
        self::assertTrue(\ThreadSafe\Profile\EncryptionSettings::isRecipient(self::KEY));
        self::assertFalse(\ThreadSafe\Profile\EncryptionSettings::isRecipient(self::KEY . 'q'));
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
        yield 'exclude with two slashes in front' => [['excludePaths' => ['//var/cache']], 'may start with one slash'];
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
