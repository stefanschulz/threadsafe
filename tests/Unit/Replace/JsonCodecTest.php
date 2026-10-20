<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Replace;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Replace\JsonCodec;

final class JsonCodecTest extends TestCase
{
    private function rewrite(string $json): ?string
    {
        return (new JsonCodec())->rewrite($json, static fn (string $s): string => str_replace('old.test', 'new.example.org', $s));
    }

    #[DataProvider('documents')]
    public function testRewritesStringsAndKeepsEverythingElseByteIdentical(string $in, string $expected): void
    {
        self::assertSame($expected, $this->rewrite($in));
    }

    /** @return iterable<string, array{string, string}> */
    public static function documents(): iterable
    {
        yield 'escaped slashes stay escaped' => [
            '{"url":"http:\/\/old.test\/a"}',
            '{"url":"http:\/\/new.example.org\/a"}',
        ];
        yield 'unescaped slashes stay unescaped' => [
            '{"url":"http://old.test/a"}',
            '{"url":"http://new.example.org/a"}',
        ];
        yield 'escaped unicode stays escaped' => [
            '{"t":"gr\u00f6\u00dfe http:\/\/old.test\/"}',
            '{"t":"gr\u00f6\u00dfe http:\/\/new.example.org\/"}',
        ];
        yield 'raw unicode stays raw' => [
            '{"t":"größe http://old.test/ ✓"}',
            '{"t":"größe http://new.example.org/ ✓"}',
        ];
        yield 'surrogate pairs stay escaped' => [
            '{"t":"\ud83d\ude00 http:\/\/old.test\/"}',
            '{"t":"\ud83d\ude00 http:\/\/new.example.org\/"}',
        ];
        yield 'control character escapes do not change the unicode style' => [
            "{\"t\":\"ü\\u0001 http://old.test/\"}",
            "{\"t\":\"ü\\u0001 http://new.example.org/\"}",
        ];
        yield 'pretty printing is kept' => [
            "{\n    \"a\": [\n        \"http://old.test/\",\n        1.0,\n        -0,\n        1e3\n    ],\n    \"b\": null\n}",
            "{\n    \"a\": [\n        \"http://new.example.org/\",\n        1.0,\n        -0,\n        1e3\n    ],\n    \"b\": null\n}",
        ];
        yield 'keys are strings too' => [
            '{"http://old.test/":1}',
            '{"http://new.example.org/":1}',
        ];
        yield 'quotes and backslashes inside strings' => [
            '{"t":"say \"hi\" \\\\ http://old.test/"}',
            '{"t":"say \"hi\" \\\\ http://new.example.org/"}',
        ];
        yield 'escaped backslash before a raw slash is not an escaped slash' => [
            '{"t":"back\\\\/slash http://old.test/"}',
            '{"t":"back\\\\/slash http://new.example.org/"}',
        ];
        yield 'backslash-slash text inside a value' => [
            '{"t":"x \\\\\/ http:\/\/old.test\/"}',
            '{"t":"x \\\\\/ http:\/\/new.example.org\/"}',
        ];
        yield 'bare json string' => ['"http://old.test/"', '"http://new.example.org/"'];
        yield 'nothing to change' => ['{"a":["b",2]}', '{"a":["b",2]}'];
        yield 'scalar' => ['42', '42'];
    }

    public function testCallbackReceivesDecodedStrings(): void
    {
        $seen = [];
        (new JsonCodec())->rewrite('{"u":"http:\/\/x.test\/aä"}', static function (string $s) use (&$seen): string {
            $seen[] = $s;

            return $s;
        });

        self::assertSame(['u', 'http://x.test/aä'], $seen);
    }

    #[DataProvider('invalid')]
    public function testReturnsNullForInvalidJson(string $value): void
    {
        self::assertNull($this->rewrite($value));
    }

    /** @return iterable<string, array{string}> */
    public static function invalid(): iterable
    {
        yield 'bbcode' => ['[b]bold[/b] http://old.test'];
        yield 'trailing comma' => ['{"a":1,}'];
        yield 'single quotes' => ["{'a':'b'}"];
        yield 'empty' => [''];
        yield 'unterminated' => ['{"a":"http://old.test'];
    }

    #[DataProvider('lookingValues')]
    public function testLooks(string $value, bool $expected): void
    {
        self::assertSame($expected, (new JsonCodec())->looks($value));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function lookingValues(): iterable
    {
        yield 'object' => ['{"a":1}', true];
        yield 'array with leading space' => ["  \n[1]", true];
        yield 'string' => ['"x"', true];
        yield 'text' => ['hello', false];
        yield 'number' => ['12', false];
        yield 'empty' => ['', false];
    }

    public function testFallsBackToText(): void
    {
        self::assertTrue((new JsonCodec())->fallsBackToText());
    }
}
