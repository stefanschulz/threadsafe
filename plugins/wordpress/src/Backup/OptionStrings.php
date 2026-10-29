<?php

declare(strict_types=1);

namespace ThreadSafe\WordPress\Backup;

/**
 * Reads the strings of a flat PHP-serialized array of strings (the option `active_plugins`: `a:2:{i:0;s:19:"akismet/akismet.php";i:1;s:9:"hello.php";}`)
 * without `unserialize()`: no object is ever made and nothing runs. Strings are read by their length, so a quote inside one does no harm.
 * What is not such an array gives an empty list.
 *
 * @internal
 */
final class OptionStrings
{
    /**
     * @return list<string>
     */
    public static function list(string $serialized): array
    {
        if (preg_match('/^a:(\d+):\{/', $serialized, $m) !== 1) {
            return [];
        }
        $position = strlen($m[0]);
        $length = strlen($serialized);
        $strings = [];
        while ($position < $length && $serialized[$position] !== '}') {
            // the key: i:N;
            if (preg_match('/\Gi:-?\d+;/', $serialized, $key, 0, $position) !== 1) {
                return $strings;
            }
            $position += strlen($key[0]);
            // the value: s:LEN:"...";
            if (preg_match('/\Gs:(\d+):"/', $serialized, $value, 0, $position) !== 1) {
                return $strings;
            }
            $start = $position + strlen($value[0]);
            $size = (int) $value[1];
            if (substr($serialized, $start + $size, 2) !== '";') {
                return $strings;
            }
            $strings[] = substr($serialized, $start, $size);
            $position = $start + $size + 2;
        }

        return $strings;
    }

    private function __construct()
    {
    }
}
