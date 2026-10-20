<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Support;

use Closure;
use Random\Engine\Mt19937;
use Random\Randomizer;
use stdClass;

/**
 * Deterministic (seeded) generator of random data structures for property tests.
 *
 * Strings are built from tokens that stress the code under test: URLs in all notations, e-mail addresses, bare
 * domains, a foreign domain, multi-byte text, quotes, backslashes, NUL bytes, delimiters of the serialize format.
 * Every generated text starts with "t " so that it can never be mistaken for a serialized or JSON value.
 */
final class StructureGenerator
{
    private const array TOKENS = [
        'http://old.test/path?x=1',
        'https://www.old.test/',
        '//old.test/a.png',
        'info@old.test',
        'old.test',
        'http://other.test/',
        'ünïcödé ✓ 😀',
        'say "hi" \\ there',
        "line\nbreak",
        "nul\0byte",
        'http%3A%2F%2Fold.test%2Fx',
        'http:\/\/old.test\/esc',
        'old.testing.com',
        'http://old.test:8080/x',
        'a;b:c{d}',
        "tab\there",
        'shop.old.test',
        'a";s:1:"x";}',
        'HTTP://OLD.TEST/UP',
        '0',
        '1.5',
    ];

    private const array SEPARATORS = [' ', '', ', ', "\n", '"', '\\', ';'];

    private const array FLOATS = [0.5, -1.25, 0.1, 3.0, 1e100];

    private Randomizer $random;

    public function __construct(int $seed)
    {
        $this->random = new Randomizer(new Mt19937($seed));
    }

    public function text(): string
    {
        $parts = [];
        for ($i = $this->random->getInt(1, 4); $i > 0; --$i) {
            $parts[] = self::TOKENS[$this->random->getInt(0, count(self::TOKENS) - 1)];
        }

        return 't ' . implode(self::SEPARATORS[$this->random->getInt(0, count(self::SEPARATORS) - 1)], $parts);
    }

    /** A structure for the serialize() tests: arrays, objects, scalars and embedded encodings. */
    public function phpValue(int $depth = 0): mixed
    {
        $roll = $this->random->getInt(0, 99);
        if ($depth >= 3 || $roll < 40) {
            return $this->scalar();
        }
        if ($roll < 55) {
            return $this->list(fn (): mixed => $this->phpValue($depth + 1));
        }
        if ($roll < 70) {
            return $this->assoc(fn (): mixed => $this->phpValue($depth + 1));
        }
        if ($roll < 78) {
            $object = new stdClass();
            foreach ($this->assoc(fn (): mixed => $this->phpValue($depth + 1)) as $key => $value) {
                $object->{$key} = $value;
            }

            return $object;
        }
        if ($roll < 84) {
            return new Probe($this->text(), $this->text(), $this->text(), $this->phpValue($depth + 1));
        }
        if ($roll < 92) {
            return new Embedded('serialized', $this->phpValue($depth + 1));
        }

        return new Embedded('json', $this->jsonValue($depth + 1));
    }

    /** A structure for the json_encode() tests: arrays, scalars and embedded encodings. */
    public function jsonValue(int $depth = 0): mixed
    {
        $roll = $this->random->getInt(0, 99);
        if ($depth >= 3 || $roll < 45) {
            return $this->scalar();
        }
        if ($roll < 62) {
            return $this->list(fn (): mixed => $this->jsonValue($depth + 1));
        }
        if ($roll < 90) {
            return $this->assoc(fn (): mixed => $this->jsonValue($depth + 1));
        }
        if ($roll < 95) {
            return new Embedded('serialized', $this->phpValue($depth + 1));
        }

        return new Embedded('json', $this->jsonValue($depth + 1));
    }

    public function int(int $min, int $max): int
    {
        return $this->random->getInt($min, $max);
    }

    private function scalar(): mixed
    {
        return match ($this->random->getInt(0, 9)) {
            0 => $this->random->getInt(-1000, 100000),
            1 => self::FLOATS[$this->random->getInt(0, count(self::FLOATS) - 1)],
            2 => $this->random->getInt(0, 1) === 1,
            3 => null,
            default => $this->text(),
        };
    }

    /**
     * @param Closure(): mixed $item
     *
     * @return list<mixed>
     */
    private function list(Closure $item): array
    {
        $list = [];
        for ($i = $this->random->getInt(0, 4); $i > 0; --$i) {
            $list[] = $item();
        }

        return $list;
    }

    /**
     * Keys are unique ("k<n>_…") and may contain tokens, but never a NUL byte (not allowed as property name start).
     *
     * @param Closure(): mixed $item
     *
     * @return array<string, mixed>
     */
    private function assoc(Closure $item): array
    {
        $map = [];
        for ($i = $this->random->getInt(1, 4); $i > 0; --$i) {
            $key = 'k' . $i . '_' . str_replace("\0", '', $this->text());
            $map[$key] = $item();
        }

        return $map;
    }
}
