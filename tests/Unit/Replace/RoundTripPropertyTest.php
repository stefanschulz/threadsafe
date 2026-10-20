<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Replace;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use ThreadSafe\Replace\DomainMapping;
use ThreadSafe\Replace\Stats;
use ThreadSafe\Replace\UrlMatcher;
use ThreadSafe\Replace\ValueRewriter;
use ThreadSafe\Tests\Support\Embedded;
use ThreadSafe\Tests\Support\Probe;
use ThreadSafe\Tests\Support\StructureGenerator;

/**
 * Property tests: for random structures, rewriting the *encoded* value must give exactly the bytes that
 * encoding the *transformed structure* gives. This proves that length prefixes, escaping style, number
 * formatting and nesting survive the rewrite.
 */
final class RoundTripPropertyTest extends TestCase
{
    private const int CASES = 300;

    private UrlMatcher $matcher;

    protected function setUp(): void
    {
        // A target with a port makes the URL-encoded form interesting ("new.example%3A8443").
        $this->matcher = new UrlMatcher([new DomainMapping('old.test', 'new.example:8443')]);
    }

    /** @return iterable<string, array{int}> */
    public static function seeds(): iterable
    {
        for ($seed = 1; $seed <= self::CASES; ++$seed) {
            yield "seed $seed" => [$seed];
        }
    }

    #[DataProvider('seeds')]
    public function testSerializedValues(int $seed): void
    {
        $spec = (new StructureGenerator($seed))->phpValue();

        $encoded = serialize($this->materialise($spec));
        $expected = serialize($this->materialise($this->transform($spec)));
        $actual = (new ValueRewriter($this->matcher))->rewrite($encoded)->value;

        self::assertSame($expected, $actual, "Serialized round trip failed for seed $seed.");
    }

    #[DataProvider('seeds')]
    public function testJsonValues(int $seed): void
    {
        $generator = new StructureGenerator($seed);
        $spec = $generator->jsonValue();
        $flags = $this->jsonFlags($generator->int(0, 15));

        $encoded = json_encode($this->materialise($spec), $flags | JSON_THROW_ON_ERROR);
        $expected = json_encode($this->materialise($this->transform($spec)), $flags | JSON_THROW_ON_ERROR);
        $actual = (new ValueRewriter($this->matcher))->rewrite($encoded)->value;

        self::assertSame($expected, $actual, "JSON round trip failed for seed $seed (flags $flags).");
    }

    #[DataProvider('seeds')]
    public function testRewritingTwiceChangesNothingMore(int $seed): void
    {
        $spec = (new StructureGenerator($seed))->phpValue();
        $rewriter = new ValueRewriter($this->matcher);

        $once = $rewriter->rewrite(serialize($this->materialise($spec)));
        $twice = $rewriter->rewrite($once->value);

        self::assertSame($once->value, $twice->value, "Not idempotent for seed $seed.");
        self::assertFalse($twice->changed);
    }

    private function jsonFlags(int $bits): int
    {
        $flags = 0;
        $flags |= ($bits & 1) !== 0 ? JSON_UNESCAPED_SLASHES : 0;
        $flags |= ($bits & 2) !== 0 ? JSON_UNESCAPED_UNICODE : 0;
        $flags |= ($bits & 4) !== 0 ? JSON_PRETTY_PRINT : 0;
        $flags |= ($bits & 8) !== 0 ? JSON_PRESERVE_ZERO_FRACTION : 0;

        return $flags;
    }

    /** Applies the matcher to every string (and every string key) of a spec; embedded specs are transformed too. */
    private function transform(mixed $value): mixed
    {
        $f = fn (mixed $v): mixed => $this->transform($v);
        $text = fn (string $s): string => $this->matcher->rewrite($s, false, new Stats());

        return match (true) {
            is_string($value) => $text($value),
            is_array($value) => $this->mapArray($value, $f, $text),
            $value instanceof stdClass => $this->mapObject($value, $f, $text),
            $value instanceof Probe => $value->map(static fn (mixed $v): mixed => is_string($v) ? $text($v) : $f($v)),
            $value instanceof Embedded => new Embedded($value->kind, $f($value->spec)),
            default => $value,
        };
    }

    /** Turns embedded specs into the encoded strings they stand for. */
    private function materialise(mixed $value): mixed
    {
        $f = fn (mixed $v): mixed => $this->materialise($v);
        $same = static fn (string $s): string => $s;

        return match (true) {
            is_array($value) => $this->mapArray($value, $f, $same),
            $value instanceof stdClass => $this->mapObject($value, $f, $same),
            $value instanceof Probe => $value->map($f),
            $value instanceof Embedded => $value->kind === 'serialized'
                ? serialize($f($value->spec))
                : json_encode($f($value->spec), JSON_THROW_ON_ERROR),
            default => $value,
        };
    }

    /**
     * @param array<array-key, mixed>      $array
     * @param Closure(mixed): mixed        $f
     * @param Closure(string): string      $key
     *
     * @return array<array-key, mixed>
     */
    private function mapArray(array $array, Closure $f, Closure $key): array
    {
        $out = [];
        foreach ($array as $k => $v) {
            $out[is_string($k) ? $key($k) : $k] = $f($v);
        }

        return $out;
    }

    /**
     * @param Closure(mixed): mixed   $f
     * @param Closure(string): string $key
     */
    private function mapObject(stdClass $object, Closure $f, Closure $key): stdClass
    {
        $out = new stdClass();
        foreach (get_object_vars($object) as $k => $v) {
            $out->{$key((string) $k)} = $f($v);
        }

        return $out;
    }
}
