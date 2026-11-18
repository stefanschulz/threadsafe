<?php

declare(strict_types=1);

namespace ThreadSafe\Auth;

/**
 * A reader for CBOR (RFC 8949) that is written for one job: the data of a passkey (the attestation object, the key in COSE form). It reads
 * what WebAuthn uses and refuses everything else with a {@see PasskeyException}, because its input comes from a browser that a stranger
 * may drive (docs/PHASE14.md 3.2):
 *
 * - unsigned and negative integers (as far as PHP's integer holds them), byte and text strings (text must be UTF-8), arrays, maps, and
 *   the values false, true and null;
 * - lengths that are written out (no "indefinite" items), no tags, no floats, no `undefined`, no other simple values;
 * - map keys that are integers or text, and no key twice (PHP turns the text "1" into the integer 1, so those two count as the same key);
 * - at most 64 KiB of input, nesting at most 8 deep, at most 4096 items, and a length that claims more than the input holds is refused
 *   before anything is allocated.
 *
 * A byte string and a text string both come out as a PHP string; the caller knows which one it expects.
 */
final class Cbor
{
    public const int MAX_BYTES = 65536;
    public const int MAX_DEPTH = 8;
    public const int MAX_ITEMS = 4096;

    /**
     * Reads one item that is the whole input.
     *
     * @throws PasskeyException
     */
    public static function decode(string $data): mixed
    {
        [$value, $end] = self::decodePrefix($data);
        if ($end !== strlen($data)) {
            throw new PasskeyException(sprintf('CBOR: %d byte(s) are left over after the item (position %d).', strlen($data) - $end, $end));
        }

        return $value;
    }

    /**
     * Reads one item that starts at `$offset` and may be followed by other data (the key in the data of an authenticator is).
     *
     * @return array{0: mixed, 1: int} the item and the position after it
     *
     * @throws PasskeyException
     */
    public static function decodePrefix(string $data, int $offset = 0): array
    {
        if (strlen($data) > self::MAX_BYTES) {
            throw new PasskeyException('CBOR: the input is larger than ' . self::MAX_BYTES . ' bytes.');
        }
        if ($offset < 0 || $offset >= strlen($data)) {
            throw new PasskeyException(sprintf('CBOR: there is no item at position %d.', $offset));
        }
        $items = 0;
        $value = self::item($data, $offset, 0, $items);

        return [$value, $offset];
    }

    private static function item(string $d, int &$o, int $depth, int &$items): mixed
    {
        if (++$items > self::MAX_ITEMS) {
            throw new PasskeyException('CBOR: more than ' . self::MAX_ITEMS . ' items.');
        }
        if ($o >= strlen($d)) {
            throw new PasskeyException(sprintf('CBOR: the input ends where an item should start (position %d).', $o));
        }
        $start = $o;
        $initial = ord($d[$o++]);
        $major = $initial >> 5;
        $info = $initial & 31;

        if ($major === 7) {
            return match ($info) {
                20 => false,
                21 => true,
                22 => null,
                default => throw new PasskeyException(sprintf('CBOR: a float, an undefined or another simple value at position %d is not allowed.', $start)),
            };
        }
        if ($major === 6) {
            throw new PasskeyException(sprintf('CBOR: a tag at position %d is not allowed.', $start));
        }
        $n = self::argument($d, $o, $info, $start);

        switch ($major) {
            case 0:
                return $n;
            case 1:
                return -1 - $n;
            case 2:
            case 3:
                if ($n > strlen($d) - $o) {
                    throw new PasskeyException(sprintf('CBOR: the string at position %d is longer than the input.', $start));
                }
                $string = substr($d, $o, $n);
                $o += $n;
                if ($major === 3 && preg_match('//u', $string) !== 1) {
                    throw new PasskeyException(sprintf('CBOR: the text at position %d is not UTF-8.', $start));
                }

                return $string;
            case 4:
                self::nested($depth, $start);
                if ($n > strlen($d) - $o) {
                    throw new PasskeyException(sprintf('CBOR: the array at position %d claims more items than the input holds.', $start));
                }
                $list = [];
                for ($i = 0; $i < $n; $i++) {
                    $list[] = self::item($d, $o, $depth + 1, $items);
                }

                return $list;
            default: // 5
                self::nested($depth, $start);
                if ($n > intdiv(strlen($d) - $o, 2)) {
                    throw new PasskeyException(sprintf('CBOR: the map at position %d claims more pairs than the input holds.', $start));
                }
                $map = [];
                for ($i = 0; $i < $n; $i++) {
                    if ($o >= strlen($d)) {
                        throw new PasskeyException(sprintf('CBOR: the input ends inside the map at position %d.', $start));
                    }
                    $keyMajor = ord($d[$o]) >> 5;
                    if ($keyMajor !== 0 && $keyMajor !== 1 && $keyMajor !== 3) {
                        throw new PasskeyException(sprintf('CBOR: a key of the map at position %d is neither an integer nor text.', $start));
                    }
                    $key = self::item($d, $o, $depth + 1, $items);
                    \assert(is_int($key) || is_string($key));
                    if (array_key_exists($key, $map)) {
                        throw new PasskeyException(sprintf('CBOR: the map at position %d has a key twice.', $start));
                    }
                    $map[$key] = self::item($d, $o, $depth + 1, $items);
                }

                return $map;
        }
    }

    private static function nested(int $depth, int $position): void
    {
        if ($depth >= self::MAX_DEPTH) {
            throw new PasskeyException(sprintf('CBOR: nesting deeper than %d at position %d.', self::MAX_DEPTH, $position));
        }
    }

    /** The number that follows the first byte of an item: the value itself, or a length, or a count. */
    private static function argument(string $d, int &$o, int $info, int $start): int
    {
        if ($info < 24) {
            return $info;
        }
        $size = match ($info) {
            24 => 1,
            25 => 2,
            26 => 4,
            27 => 8,
            default => throw new PasskeyException(sprintf('CBOR: an indefinite length or a reserved form at position %d is not allowed.', $start)),
        };
        if ($size > strlen($d) - $o) {
            throw new PasskeyException(sprintf('CBOR: the input ends inside the item at position %d.', $start));
        }
        $bytes = substr($d, $o, $size);
        $o += $size;
        $value = match ($size) {
            1 => ord($bytes),
            2 => self::unsigned('n', $bytes),
            4 => self::unsigned('N', $bytes),
            default => self::unsigned('J', $bytes),
        };
        if ($value < 0) {
            throw new PasskeyException(sprintf('CBOR: the number at position %d is larger than this program reads.', $start));
        }

        return $value;
    }

    private static function unsigned(string $format, string $bytes): int
    {
        $unpacked = unpack($format, $bytes);
        if ($unpacked === false || !is_int($unpacked[1] ?? null)) {
            throw new PasskeyException('CBOR: a number cannot be read.');
        }

        return $unpacked[1];
    }
}
