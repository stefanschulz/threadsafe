<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Backup;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Backup\BackupName;

final class BackupNameTest extends TestCase
{
    private function backupName(string $profile = 'shop'): BackupName
    {
        return BackupName::create($profile, new DateTimeImmutable('2026-10-20 14:34:56', new DateTimeZone('Europe/Berlin')), 'ab12cd34');
    }

    public function testFileNamesFollowFromTheName(): void
    {
        $name = $this->backupName();

        self::assertSame('shop_20261020-123456_ab12cd34', $name->base(), 'The time is UTC.');
        self::assertSame('shop_20261020-123456_ab12cd34.zip', $name->partFile(1, false));
        self::assertSame('shop_20261020-123456_ab12cd34-p01.zip', $name->partFile(1, true));
        self::assertSame('shop_20261020-123456_ab12cd34-p12.zip', $name->partFile(12, true));
        self::assertSame('shop_20261020-123456_ab12cd34-p100.zip', $name->partFile(100, true));
        self::assertSame('shop_20261020-123456_ab12cd34.json', $name->sidecarFile());
    }

    public function testRandomPartIsGeneratedAndDiffers(): void
    {
        $now = new DateTimeImmutable('now');
        $a = BackupName::create('shop', $now);
        $b = BackupName::create('shop', $now);

        self::assertMatchesRegularExpression('/^shop_\d{8}-\d{6}_[0-9a-f]{8}$/', $a->base());
        self::assertNotSame($a->base(), $b->base());
    }

    /** @return iterable<string, array{string}> */
    public static function badProfiles(): iterable
    {
        yield 'path' => ['../x'];
        yield 'capital' => ['Shop'];
        yield 'empty' => [''];
        yield 'dot' => ['a.b'];
        yield 'too long' => [str_repeat('a', 41)];
    }

    #[DataProvider('badProfiles')]
    public function testOnlyValidProfileIdsMakeNames(string $profile): void
    {
        $this->expectException(InvalidArgumentException::class);

        BackupName::create($profile, new DateTimeImmutable('now'));
    }

    public function testBadRandomAndPartNumber(): void
    {
        foreach ([fn () => BackupName::create('shop', new DateTimeImmutable('now'), 'XYZ'), fn () => $this->backupName()->partFile(0, true)] as $call) {
            try {
                $call();
                self::fail('Accepted');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testParseIsTheInverseOfBase(): void
    {
        foreach (['shop', 'my_shop-2', 'a', 'x_y_z'] as $profile) {
            $name = $this->backupName($profile);
            $parsed = BackupName::parse($name->base());

            self::assertNotNull($parsed, $profile);
            self::assertSame($name->base(), $parsed->base());
            self::assertSame($profile, $parsed->profile);
            self::assertSame($name->time->getTimestamp(), $parsed->time->getTimestamp());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function notBases(): iterable
    {
        yield 'empty' => [''];
        yield 'no random' => ['shop_20261020-123456'];
        yield 'short random' => ['shop_20261020-123456_ab12cd3'];
        yield 'upper random' => ['shop_20261020-123456_AB12CD34'];
        yield 'bad date' => ['shop_20261320-123456_ab12cd34'];
        yield 'extension' => ['shop_20261020-123456_ab12cd34.zip'];
        yield 'traversal' => ['../shop_20261020-123456_ab12cd34'];
    }

    #[DataProvider('notBases')]
    public function testParseRefusesOtherText(string $text): void
    {
        self::assertNull(BackupName::parse($text));
    }

    /** @return iterable<string, array{string, string|null, int|null, string|null}> file, base, part, extension */
    public static function files(): iterable
    {
        yield 'single' => ['shop_20261020-123456_ab12cd34.zip', 'shop_20261020-123456_ab12cd34', null, 'zip'];
        yield 'part' => ['shop_20261020-123456_ab12cd34-p03.zip', 'shop_20261020-123456_ab12cd34', 3, 'zip'];
        yield 'sidecar' => ['shop_20261020-123456_ab12cd34.json', 'shop_20261020-123456_ab12cd34', null, 'json'];
        yield 'profile with hyphen' => ['my-shop_20261020-123456_ab12cd34-p10.zip', 'my-shop_20261020-123456_ab12cd34', 10, 'zip'];
        yield 'unfinished part' => ['shop_20261020-123456_ab12cd34.zip.part', null, null, null];
        yield 'protection file' => ['.htaccess', null, null, null];
        yield 'foreign zip' => ['holiday.zip', null, null, null];
        yield 'part number zero' => ['shop_20261020-123456_ab12cd34-p00.zip', null, null, null];
        yield 'part of a sidecar' => ['shop_20261020-123456_ab12cd34-p01.json', null, null, null];
    }

    #[DataProvider('files')]
    public function testFromFile(string $file, ?string $base, ?int $part, ?string $extension): void
    {
        $parsed = BackupName::fromFile($file);

        if ($base === null) {
            self::assertNull($parsed);

            return;
        }
        self::assertNotNull($parsed);
        self::assertSame($base, $parsed['name']->base());
        self::assertSame($part, $parsed['part']);
        self::assertSame($extension, $parsed['extension']);
    }
}
