<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Auth\QrCode;
use ThreadSafe\Auth\Totp;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Tests\Support\ZbarOracle;

/**
 * The QR encoder against a reader that is not ours: `zbarimg` (the package zbar-tools of the tools image) reads the picture, and what it reads
 * must be the text that was encoded (docs/PHASE11.md 3.6, 4.2). The picture is written as a PBM image with the quiet zone of four modules,
 * each module a square of 8 pixels, which ImageMagick (the image library of zbar) reads.
 */
final class QrCodeZbarTest extends TestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    /** @return iterable<string, array{string}> */
    public static function texts(): iterable
    {
        yield 'one letter' => ['a'];
        yield 'the end of version 1' => [str_repeat('x', 14)];
        yield 'an address of an app' => [Totp::uri('JBSWY3DPEHPK3PXP', 'admin', 'shop.example.com')];
        yield 'the characters of a keyboard' => [implode('', array_map('chr', range(33, 126)))];
        yield 'umlauts as UTF-8' => ['Größe: äöüß ' . str_repeat('é', 20)];
        yield 'version 5' => [str_repeat('five', 20)];
        yield 'version 6' => [str_repeat('six', 35)];
        yield 'version 7 (with version information)' => [str_repeat('7', 110)];
        yield 'version 8 (blocks of two sizes)' => [str_repeat('8', 140)];
        yield 'version 9' => [str_repeat('Nine', 44)];
        yield 'version 10 (16 bits of length)' => [str_repeat('ten', 70)];
        yield 'the longest' => [str_repeat('z', 213)];
        yield 'the longest address of the Security page' => [Totp::uri(Totp::newSecret(), 'admin', 'a-long-host-name.example.c')];
    }

    #[DataProvider('texts')]
    public function testZbarReadsWhatWasEncoded(string $text): void
    {
        if (!ZbarOracle::available()) {
            self::markTestSkipped('zbarimg (the package zbar-tools) is needed.');
        }

        self::assertSame($text, ZbarOracle::read(QrCode::modules($text), $this->tempDirectory()));
    }
}
