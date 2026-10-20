<?php

declare(strict_types=1);

namespace ThreadSafe\Replace;

use Closure;

/**
 * Rewrites the strings inside a JSON document without touching anything else: whitespace, key order, number
 * formatting and every unchanged string stay byte-identical, because only the string literals that change are
 * re-encoded, in the same escaping style as the original (escaped slashes, escaped unicode).
 */
final class JsonCodec implements ValueCodec
{
    public function looks(string $value): bool
    {
        $trimmed = ltrim($value, " \t\r\n");

        return $trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[' || $trimmed[0] === '"');
    }

    public function fallsBackToText(): bool
    {
        // Text such as "[b]bold[/b] see http://…" starts like JSON but is plain text.
        return true;
    }

    public function rewrite(string $value, Closure $rewriteString): ?string
    {
        if (!json_validate($value)) {
            return null;
        }

        // Every string literal of a valid JSON document is a quoted run; nothing else in JSON contains a quote.
        return preg_replace_callback(
            '~"(?>[^"\\\\]++|\\\\.)*+"~s',
            static function (array $m) use ($rewriteString): string {
                $literal = (string) $m[0];
                $decoded = json_decode($literal);
                if (!is_string($decoded)) {
                    return $literal;
                }

                $new = $rewriteString($decoded);
                if ($new === $decoded) {
                    return $literal;
                }

                // Keep the escaping style of the original literal. Escapes of control characters (\u0000–\u007f) say
                // nothing about the style; only an escaped non-ASCII character does, and raw non-ASCII bytes win.
                $flags = 0;
                // An escaped slash is a backslash that starts an escape: preceded by an even number of backslashes.
                if (preg_match('~(?<!\\\\)(?:\\\\\\\\)*\\\\/~', $literal) !== 1) {
                    $flags |= JSON_UNESCAPED_SLASHES;
                }
                $rawNonAscii = preg_match('/[\x80-\xff]/', $literal) === 1;
                $escapedNonAscii = preg_match('/\\\\u(?!00[0-7][0-9a-fA-F])[0-9a-fA-F]{4}/', $literal) === 1;
                if ($rawNonAscii || !$escapedNonAscii) {
                    $flags |= JSON_UNESCAPED_UNICODE;
                }
                $encoded = json_encode($new, $flags);

                return $encoded === false ? $literal : $encoded;
            },
            $value,
        );
    }
}
