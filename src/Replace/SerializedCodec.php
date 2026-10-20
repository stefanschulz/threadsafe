<?php

declare(strict_types=1);

namespace ThreadSafe\Replace;

use Closure;
use ThreadSafe\Replace\Exception\UnparseableValue;

/**
 * Rewrites the strings inside a PHP-serialized value and recalculates the byte-length prefixes.
 *
 * This is a tokenizer, not unserialize(): no object is ever created, so no class has to exist and no code can run.
 * Supported: N, b, i, d, s, a, O, E (enums), r and R (references). Not supported (value is reported as
 * unparseable and left alone): C (custom serialization) and S (escaped strings).
 *
 * Property names (array keys, object properties including the "\0Class\0name" forms) are strings like any other
 * and pass through the callback, which leaves them untouched unless they contain a domain.
 */
final class SerializedCodec implements ValueCodec
{
    private const int MAX_DEPTH = 64;

    public function looks(string $value): bool
    {
        // Only containers and strings can hold a domain name; scalars cannot.
        return preg_match('/^(?:a:\d+:\{|O:\d+:"|[CEsS]:\d+:")/', $value) === 1;
    }

    public function fallsBackToText(): bool
    {
        return false;
    }

    public function rewrite(string $value, Closure $rewriteString): ?string
    {
        $pos = 0;
        $out = '';
        try {
            $this->readValue($value, $pos, $out, $rewriteString, 0);
        } catch (UnparseableValue) {
            return null;
        }

        return $pos === strlen($value) ? $out : null;
    }

    /**
     * @param Closure(string): string $f
     */
    private function readValue(string $s, int &$pos, string &$out, Closure $f, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new UnparseableValue('Nesting too deep.');
        }

        $start = 0;
        switch ($s[$pos] ?? '') {
            case 'N':
                $this->copy($s, $pos, $out, '/\GN;/');

                return;
            case 'b':
                $this->copy($s, $pos, $out, '/\Gb:[01];/');

                return;
            case 'i':
                $this->copy($s, $pos, $out, '/\Gi:-?\d+;/');

                return;
            case 'd':
                $this->copy($s, $pos, $out, '/\Gd:[-+0-9.eEINFAN]+;/');

                return;
            case 'r':
            case 'R':
                $this->copy($s, $pos, $out, '/\G[rR]:\d+;/');

                return;
            case 's':
                $length = $this->header($s, $pos, '/\Gs:(\d+):"/', $start);
                $content = $this->take($s, $start, $length, '";');
                $new = $f($content);
                $out .= 's:' . strlen($new) . ':"' . $new . '";';
                $pos = $start + $length + 2;

                return;
            case 'E':
                $length = $this->header($s, $pos, '/\GE:(\d+):"/', $start);
                $this->take($s, $start, $length, '";');
                $out .= substr($s, $pos, $start + $length + 2 - $pos);
                $pos = $start + $length + 2;

                return;
            case 'a':
                $count = $this->header($s, $pos, '/\Ga:(\d+):\{/', $start);
                $out .= substr($s, $pos, $start - $pos);
                $pos = $start;
                $this->readMembers($s, $pos, $out, $f, $depth, $count);

                return;
            case 'O':
                $classLength = $this->header($s, $pos, '/\GO:(\d+):"/', $start);
                $this->take($s, $start, $classLength, '":');
                $afterClass = $start + $classLength + 2;
                if (preg_match('/\G(\d+):\{/', $s, $m, 0, $afterClass) !== 1) {
                    throw new UnparseableValue('Bad object header.');
                }
                $out .= substr($s, $pos, $afterClass + strlen($m[0]) - $pos);
                $pos = $afterClass + strlen($m[0]);
                $this->readMembers($s, $pos, $out, $f, $depth, (int) $m[1]);

                return;
            default:
                throw new UnparseableValue('Unsupported or malformed token at offset ' . $pos . '.');
        }
    }

    /**
     * Reads $count key/value pairs and the closing brace.
     *
     * @param Closure(string): string $f
     */
    private function readMembers(string $s, int &$pos, string &$out, Closure $f, int $depth, int $count): void
    {
        for ($i = 0; $i < $count * 2; ++$i) {
            $this->readValue($s, $pos, $out, $f, $depth + 1);
        }
        if (($s[$pos] ?? '') !== '}') {
            throw new UnparseableValue('Missing closing brace.');
        }
        $out .= '}';
        ++$pos;
    }

    private function copy(string $s, int &$pos, string &$out, string $pattern): void
    {
        if (preg_match($pattern, $s, $m, 0, $pos) !== 1) {
            throw new UnparseableValue('Malformed token at offset ' . $pos . '.');
        }
        $out .= $m[0];
        $pos += strlen($m[0]);
    }

    /**
     * Matches a header with one captured number at $pos and returns that number; $start receives the offset
     * right behind the header.
     */
    private function header(string $s, int $pos, string $pattern, int &$start): int
    {
        if (preg_match($pattern, $s, $m, 0, $pos) !== 1) {
            throw new UnparseableValue('Malformed header at offset ' . $pos . '.');
        }
        $start = $pos + strlen($m[0]);

        return (int) $m[1];
    }

    /** Returns the $length bytes at $start and checks that $terminator follows. */
    private function take(string $s, int $start, int $length, string $terminator): string
    {
        if ($start + $length + strlen($terminator) > strlen($s)
            || substr($s, $start + $length, strlen($terminator)) !== $terminator) {
            throw new UnparseableValue('String length does not match its content.');
        }

        return substr($s, $start, $length);
    }
}
