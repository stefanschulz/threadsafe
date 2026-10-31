<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Auth;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Auth\Base32;

/**
 * Base32 against the test vectors of RFC 4648 section 10 (written without the padding), and how a typed key is read.
 */
final class Base32Test extends TestCase
{
    /** @return iterable<string, array{string, string}> bytes, text */
    public static function vectors(): iterable
    {
        yield 'empty' => ['', ''];
        yield 'f' => ['f', 'MY'];
        yield 'fo' => ['fo', 'MZXQ'];
        yield 'foo' => ['foo', 'MZXW6'];
        yield 'foob' => ['foob', 'MZXW6YQ'];
        yield 'fooba' => ['fooba', 'MZXW6YTB'];
        yield 'foobar' => ['foobar', 'MZXW6YTBOI'];
    }

    #[DataProvider('vectors')]
    public function testTheVectorsOfTheRfcAreWrittenAndRead(string $bytes, string $text): void
    {
        self::assertSame($text, Base32::encode($bytes));
        self::assertSame($bytes, Base32::decode($text));
    }

    public function testAKeyTypedFromAScreenIsRead(): void
    {
        self::assertSame('foobar', Base32::decode('mzxw 6ytb-oi'), 'lower case, a space and a hyphen');
        self::assertSame('foobar', Base32::decode('MZXW6YTBOI======'), 'with the padding');
    }

    public function testAnyBytesSurviveTheRoundTrip(): void
    {
        foreach ([1, 7, 20, 31, 64] as $length) {
            $bytes = random_bytes($length);

            self::assertSame($bytes, Base32::decode(Base32::encode($bytes)), "$length bytes");
        }
    }

    #[DataProvider('wrong')]
    public function testACharacterOutsideTheAlphabetIsRefused(string $text): void
    {
        $this->expectException(InvalidArgumentException::class);

        Base32::decode($text);
    }

    /** @return iterable<string, array{string}> */
    public static function wrong(): iterable
    {
        yield 'a 1 (not in the alphabet)' => ['MZXW1YTB'];
        yield 'an 8' => ['MZXW8YTB'];
        yield 'a 0' => ['MZXW0YTB'];
        yield 'a symbol' => ['MZXW6!TB'];
    }
}
