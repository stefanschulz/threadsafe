<?php

declare(strict_types=1);

namespace ThreadSafe\Replace;

/**
 * Rewrites the domains in one database value, whatever form it has: serialized PHP, JSON, or plain text –
 * also nested (a JSON document holding a serialized string holding a URL).
 *
 * A value that looks like a structured format but cannot be parsed is never modified (a blind text replacement
 * would corrupt length prefixes); it is counted in {@see Stats::$unparseable} instead.
 */
final class ValueRewriter
{
    private const int MAX_DEPTH = 8;

    /** @var list<ValueCodec> */
    private array $codecs;

    /**
     * @param list<ValueCodec>|null $codecs structured formats to try in order; default: serialized PHP, JSON
     */
    public function __construct(
        private readonly UrlMatcher $matcher,
        ?array $codecs = null,
    ) {
        $this->codecs = $codecs ?? [new SerializedCodec(), new JsonCodec()];
    }

    /**
     * @param bool $replaceBare also replace bare domain names (use only for columns known to hold a domain)
     */
    public function rewrite(string $value, bool $replaceBare = false): RewriteResult
    {
        $stats = new Stats();
        $new = $this->walk($value, $replaceBare, $stats, 0);

        return new RewriteResult($new, $new !== $value, $stats);
    }

    private function walk(string $value, bool $replaceBare, Stats $stats, int $depth): string
    {
        if (!$this->matcher->mayContain($value)) {
            return $value;
        }
        if ($depth > self::MAX_DEPTH) {
            ++$stats->unparseable;

            return $value;
        }

        $nested = function (string $inner) use ($replaceBare, $stats, $depth): string {
            return $this->walk($inner, $replaceBare, $stats, $depth + 1);
        };

        foreach ($this->codecs as $codec) {
            if (!$codec->looks($value)) {
                continue;
            }
            $result = $codec->rewrite($value, $nested);
            if ($result !== null) {
                return $result;
            }
            if (!$codec->fallsBackToText()) {
                // Looks structured but is not valid: do not touch it, a text replacement would break it.
                ++$stats->unparseable;

                return $value;
            }
        }

        return $this->matcher->rewrite($value, $replaceBare, $stats);
    }
}
