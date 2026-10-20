<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Replace;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use ThreadSafe\Replace\SerializedCodec;
use ThreadSafe\Tests\Support\Probe;

final class SerializedCodecTest extends TestCase
{
    private static function swap(string $s): string
    {
        return str_replace('old.test', 'new.example.org', $s);
    }

    private function rewrite(string $serialized): ?string
    {
        return (new SerializedCodec())->rewrite($serialized, self::swap(...));
    }

    public function testRecalculatesTheLengthOfAString(): void
    {
        self::assertSame(serialize('see http://new.example.org/x'), $this->rewrite(serialize('see http://old.test/x')));
    }

    public function testRewritesKeysAndValuesOfNestedArrays(): void
    {
        $in = ['a' => ['http://old.test/' => 'x', 'u' => ['http://old.test/y', 5, true, null, 1.5]], 'n' => 'plain'];
        $out = ['a' => ['http://new.example.org/' => 'x', 'u' => ['http://new.example.org/y', 5, true, null, 1.5]], 'n' => 'plain'];

        self::assertSame(serialize($out), $this->rewrite(serialize($in)));
    }

    public function testRewritesObjectsWithPrivateAndProtectedProperties(): void
    {
        $result = $this->rewrite(serialize(new Probe('http://old.test/a', 'http://old.test/b', 'http://old.test/c', ['http://old.test/d'])));

        self::assertNotNull($result);
        $object = unserialize($result);
        self::assertEquals(
            new Probe('http://new.example.org/a', 'http://new.example.org/b', 'http://new.example.org/c', ['http://new.example.org/d']),
            $object,
        );
    }

    public function testRewritesStdClass(): void
    {
        $in = new stdClass();
        $in->url = 'http://old.test/';
        $out = new stdClass();
        $out->url = 'http://new.example.org/';

        self::assertSame(serialize($out), $this->rewrite(serialize($in)));
    }

    public function testLengthIsCountedInBytesNotCharacters(): void
    {
        $result = $this->rewrite(serialize('Größe ✓ http://old.test/ 😀'));

        self::assertSame(serialize('Größe ✓ http://new.example.org/ 😀'), $result);
    }

    public function testDelimitersInsideStringsDoNotConfuseTheParser(): void
    {
        $text = 'a";s:1:"x";} http://old.test/ };';

        self::assertSame(serialize(str_replace('old.test', 'new.example.org', $text)), $this->rewrite(serialize($text)));
    }

    public function testReferencesAreCopiedUnchanged(): void
    {
        $in = 'a:2:{i:0;s:16:"http://old.test/";i:1;R:2;}';
        $result = $this->rewrite($in);

        self::assertSame('a:2:{i:0;s:23:"http://new.example.org/";i:1;R:2;}', $result);
        self::assertNotFalse(unserialize((string) $result));
    }

    public function testEnumsAreCopiedUnchanged(): void
    {
        self::assertSame('a:1:{i:0;E:11:"Suit:Hearts";}', $this->rewrite('a:1:{i:0;E:11:"Suit:Hearts";}'));
    }

    public function testEveryStringPassesThroughTheCallback(): void
    {
        $seen = [];
        (new SerializedCodec())->rewrite(serialize(['k1' => 'v1', 'k2' => ['k3' => 'v3']]), static function (string $s) use (&$seen): string {
            $seen[] = $s;

            return $s;
        });

        self::assertSame(['k1', 'v1', 'k2', 'k3', 'v3'], $seen);
    }

    #[DataProvider('invalid')]
    public function testReturnsNullForInvalidValues(string $value): void
    {
        self::assertNull($this->rewrite($value));
    }

    /** @return iterable<string, array{string}> */
    public static function invalid(): iterable
    {
        yield 'length too short' => ['s:3:"http://old.test";'];
        yield 'length too long' => ['s:99:"abc";'];
        yield 'trailing data' => [serialize('a') . 'x'];
        yield 'truncated array' => ['a:2:{i:0;s:1:"a";}'];
        yield 'missing brace' => ['a:1:{i:0;s:1:"a";'];
        yield 'custom serialization' => ['C:3:"Foo":3:{abc}'];
        yield 'escaped string' => ['S:1:"a";'];
        yield 'plain text' => ['hello'];
        yield 'empty' => [''];
        yield 'bad object header' => ['O:3:"Foo":x:{}'];
    }

    public function testRejectsTooDeepNesting(): void
    {
        $value = 's:1:"x";';
        for ($i = 0; $i < 70; ++$i) {
            $value = 'a:1:{i:0;' . $value . '}';
        }

        self::assertNull($this->rewrite($value));
    }

    #[DataProvider('lookingValues')]
    public function testLooks(string $value, bool $expected): void
    {
        self::assertSame($expected, (new SerializedCodec())->looks($value));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function lookingValues(): iterable
    {
        yield 'array' => ['a:1:{i:0;i:1;}', true];
        yield 'string' => ['s:1:"a";', true];
        yield 'object' => ['O:8:"stdClass":0:{}', true];
        yield 'plain text' => ['a: note', false];
        yield 'json' => ['{"a":1}', false];
        yield 'integer' => ['i:5;', false];
    }

    public function testDoesNotFallBackToText(): void
    {
        self::assertFalse((new SerializedCodec())->fallsBackToText());
    }
}
