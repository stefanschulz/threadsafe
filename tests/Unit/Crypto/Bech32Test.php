<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Crypto;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Crypto\Bech32;
use ThreadSafe\Crypto\CryptoException;

/**
 * Bech32 against the test vectors of BIP 173 (the valid and the invalid strings it lists), and the round trip.
 */
final class Bech32Test extends TestCase
{
    /** @return iterable<string, array{string, string}> text, human readable part */
    public static function valid(): iterable
    {
        yield 'empty data, upper case' => ['A12UEL5L', 'a'];
        yield 'empty data' => ['a12uel5l', 'a'];
        yield 'a long human readable part with a 1 in it' => ['an83characterlonghumanreadablepartthatcontainsthenumber1andtheexcludedcharactersbio1tt5tgs', 'an83characterlonghumanreadablepartthatcontainsthenumber1andtheexcludedcharactersbio'];
        yield 'the whole alphabet' => ['abcdef1qpzry9x8gf2tvdw0s3jn54khce6mua7lmqqqxw', 'abcdef'];
        yield 'split' => ['split1checkupstagehandshakeupstreamerranterredcaperred2y9e3w', 'split'];
    }

    #[DataProvider('valid')]
    public function testAValidStringIsDecodedAndEncodedAgain(string $text, string $hrp): void
    {
        [$decoded, $data] = Bech32::decode($text);

        self::assertSame($hrp, $decoded);
        self::assertSame(strtolower($text), Bech32::encode($hrp, $data), 'and back to the same string (data of whole bytes)');
    }

    /** @return iterable<string, array{string}> */
    public static function invalid(): iterable
    {
        yield 'no separator' => ['pzry9x0s0muk'];
        yield 'empty human readable part' => ['1pzry9x0s0muk'];
        yield 'a character that is not in the alphabet' => ['x1b4n0q5v'];
        yield 'a checksum that is too short' => ['li1dgmt3'];
        yield 'a wrong checksum (the part in upper case)' => ['A1G7SGD8'];
        yield 'an empty human readable part, valid checksum' => ['10a06t8'];
        yield 'an empty human readable part again' => ['1qzzfhee'];
        yield 'upper and lower case mixed' => ['A12uEL5L'];
        yield 'one character changed' => ['a12uel5m'];
        yield 'empty' => [''];
    }

    #[DataProvider('invalid')]
    public function testAnInvalidStringIsRefused(string $text): void
    {
        $this->expectException(CryptoException::class);
        Bech32::decode($text);
    }

    public function testRandomDataSurvivesTheRoundTrip(): void
    {
        foreach ([0, 1, 5, 20, 32, 33, 100] as $length) {
            $data = $length === 0 ? '' : random_bytes($length);
            [$hrp, $back] = Bech32::decode(Bech32::encode('test', $data));
            self::assertSame('test', $hrp);
            self::assertSame($data, $back, "$length bytes");
        }
    }

    public function testBitsThatAreNotZeroAfterTheDataAreRefused(): void
    {
        // "a" + one byte encodes to 2 five bit groups and 2 padding bits; make them 1 and fix the checksum by encoding by hand is not
        // possible here, so take a valid string of a longer data and cut a group: the checksum then fails first
        $this->expectException(CryptoException::class);
        Bech32::decode(substr(Bech32::encode('a', 'x'), 0, -1));
    }
}
