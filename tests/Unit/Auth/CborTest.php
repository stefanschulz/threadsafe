<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Auth\Cbor;
use ThreadSafe\Auth\PasskeyException;

/**
 * The CBOR reader against the examples of RFC 8949 appendix A (those of the kinds that it reads), every refusal, and a run over damaged
 * input: whatever the bytes, it either returns or throws its own exception (docs/PHASE14.md 3.2, 7.1).
 */
final class CborTest extends TestCase
{
    /** @return iterable<string, array{string, mixed}> hex of RFC 8949 appendix A, value */
    public static function examples(): iterable
    {
        yield '0' => ['00', 0];
        yield '1' => ['01', 1];
        yield '10' => ['0a', 10];
        yield '23' => ['17', 23];
        yield '24' => ['1818', 24];
        yield '25' => ['1819', 25];
        yield '100' => ['1864', 100];
        yield '1000' => ['1903e8', 1000];
        yield '1000000' => ['1a000f4240', 1000000];
        yield '1000000000000' => ['1b000000e8d4a51000', 1000000000000];
        yield 'largest PHP integer' => ['1b7fffffffffffffff', PHP_INT_MAX];
        yield '-1' => ['20', -1];
        yield '-10' => ['29', -10];
        yield '-100' => ['3863', -100];
        yield '-1000' => ['3903e7', -1000];
        yield 'smallest PHP integer' => ['3b7fffffffffffffff', PHP_INT_MIN];
        yield 'empty bytes' => ['40', ''];
        yield 'bytes' => ['4401020304', "\x01\x02\x03\x04"];
        yield 'empty text' => ['60', ''];
        yield 'a' => ['6161', 'a'];
        yield 'IETF' => ['6449455446', 'IETF'];
        yield 'quote and backslash' => ['62225c', '"\\'];
        yield 'u umlaut' => ['62c3bc', "\u{fc}"];
        yield 'water' => ['63e6b0b4', "\u{6c34}"];
        yield 'empty array' => ['80', []];
        yield '[1,2,3]' => ['83010203', [1, 2, 3]];
        yield 'nested array' => ['8301820203820405', [1, [2, 3], [4, 5]]];
        yield '25 items' => ['98190102030405060708090a0b0c0d0e0f101112131415161718181819', range(1, 25)];
        yield 'empty map' => ['a0', []];
        yield '{1:2,3:4}' => ['a201020304', [1 => 2, 3 => 4]];
        yield '{"a":1,"b":[2,3]}' => ['a26161016162820203', ['a' => 1, 'b' => [2, 3]]];
        yield '["a",{"b":"c"}]' => ['826161a161626163', ['a', ['b' => 'c']]];
        yield 'false' => ['f4', false];
        yield 'true' => ['f5', true];
        yield 'null' => ['f6', null];
        yield 'a text of 24 bytes (length in one byte)' => ['7818' . bin2hex(str_repeat('x', 24)), str_repeat('x', 24)];
    }

    #[DataProvider('examples')]
    public function testTheExamplesOfTheRfcAreRead(string $hex, mixed $expected): void
    {
        self::assertSame($expected, Cbor::decode((string) hex2bin($hex)));
    }

    /** @return iterable<string, array{string, string}> hex, a part of the message */
    public static function refusals(): iterable
    {
        yield 'an indefinite array' => ['9f01ff', 'indefinite'];
        yield 'an indefinite text' => ['7f6161ff', 'indefinite'];
        yield 'a reserved form' => ['1c', 'reserved'];
        yield 'a tag' => ['c11a514b67b0', 'tag'];
        yield 'a half float' => ['f90000', 'float'];
        yield 'a double' => ['fb3ff199999999999a', 'float'];
        yield 'undefined' => ['f7', 'undefined'];
        yield 'a simple value' => ['f0', 'simple'];
        yield 'a break' => ['ff', 'simple'];
        yield 'a number above the PHP integer' => ['1bffffffffffffffff', 'larger than this program'];
        yield 'the number 2^63' => ['1b8000000000000000', 'larger than this program'];
        yield 'a number that is cut' => ['1a000f42', 'ends inside'];
        yield 'a text that is cut' => ['64494554', 'longer than the input'];
        yield 'a string of 2 GB on 5 bytes' => ['5a7fffffff', 'longer than the input'];
        yield 'text that is not UTF-8' => ['62c328', 'not UTF-8'];
        yield 'an array that claims too much' => ['9a7fffffff01', 'claims more'];
        yield 'a map that claims too much' => ['ba7fffffff0102', 'claims more'];
        yield 'an array that is cut' => ['830102', 'claims more'];
        yield 'a map that is cut' => ['a1', 'claims more'];
        yield 'a map with a key twice' => ['a201020103', 'key twice'];
        yield 'a map with a byte string as key' => ['a1416102', 'neither an integer nor text'];
        yield 'a map with an array as key' => ['a1810102', 'neither an integer nor text'];
        yield 'nothing' => ['', 'no item'];
        yield 'one item and one byte more' => ['0000', 'left over'];
    }

    #[DataProvider('refusals')]
    public function testWhatIsNotAllowedIsRefused(string $hex, string $message): void
    {
        $this->expectException(PasskeyException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($message, '/') . '/');
        Cbor::decode((string) hex2bin($hex));
    }

    public function testTheTextOneAndTheNumberOneAreTheSameKey(): void
    {
        // {1: 2, "1": 3}: PHP cannot keep both, so the second one is refused
        $this->expectException(PasskeyException::class);
        $this->expectExceptionMessageMatches('/key twice/');
        Cbor::decode((string) hex2bin('a20102613103'));
    }

    public function testNestingIsLimited(): void
    {
        $ok = str_repeat("\x81", Cbor::MAX_DEPTH) . "\x00";
        self::assertIsArray(Cbor::decode($ok));

        $this->expectException(PasskeyException::class);
        $this->expectExceptionMessageMatches('/nesting/');
        Cbor::decode(str_repeat("\x81", Cbor::MAX_DEPTH + 1) . "\x00");
    }

    public function testTheSizeOfTheInputIsLimited(): void
    {
        $this->expectException(PasskeyException::class);
        $this->expectExceptionMessageMatches('/larger than/');
        Cbor::decode("\x5a" . pack('N', Cbor::MAX_BYTES) . str_repeat('x', Cbor::MAX_BYTES));
    }

    public function testTheNumberOfItemsIsLimited(): void
    {
        $count = Cbor::MAX_ITEMS + 1;
        $this->expectException(PasskeyException::class);
        $this->expectExceptionMessageMatches('/items/');
        Cbor::decode("\x99" . pack('n', $count) . str_repeat("\x00", $count));
    }

    public function testAnItemInsideLargerDataIsReadWithItsEnd(): void
    {
        // what the data of an authenticator does: a key, and after it something else
        $data = 'xx' . (string) hex2bin('a201020304') . 'yy';
        [$value, $end] = Cbor::decodePrefix($data, 2);
        self::assertSame([1 => 2, 3 => 4], $value);
        self::assertSame(7, $end);
        self::assertSame('yy', substr($data, $end));
    }

    public function testDamagedInputEndsInTheOwnExceptionOnly(): void
    {
        mt_srand(8949);
        $seeds = array_map(static fn (string $h): string => (string) hex2bin($h), [
            'a201020304', 'a26161016162820203', '8301820203820405', '826161a161626163',
            'a5010203262001215820' . str_repeat('11', 32) . '225820' . str_repeat('22', 32),
        ]);
        $returned = 0;
        for ($i = 0; $i < 3000; $i++) {
            $bytes = $seeds[$i % count($seeds)];
            for ($m = mt_rand(1, 4); $m > 0; $m--) {
                $at = mt_rand(0, strlen($bytes) - 1);
                $bytes[$at] = chr(mt_rand(0, 255));
            }
            if ($i % 5 === 0) {
                $bytes = substr($bytes, 0, mt_rand(0, strlen($bytes)));
            }
            try {
                Cbor::decode($bytes);
                $returned++;
            } catch (PasskeyException) {
                // refused: the way it should end
            }
        }
        self::assertGreaterThan(0, $returned, 'some of the damaged inputs are still valid items');
    }
}
