<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\PrestaShop9;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ThreadSafe\PrestaShop9\ParametersFile;

/**
 * Property test: for random parameter sets written with var_export() (as PrestaShop does), reading gives the
 * original values back, and changing or adding a value gives exactly the bytes var_export() writes for the
 * changed data.
 */
final class ParametersFilePropertyTest extends TestCase
{
    private const array FRAGMENTS = [
        '', 'x', "it's", 'back\\slash', 'dq"uote', "new\nline", "nul\0byte", 'ünï ✓ 😀', '?>', '<?php', "'", "\\'",
        '$var', '{$x}', '/* c */', '// c', 'a . b', '=> ,', "\r\n", 'array (', '1e3', 'NULL',
    ];

    private const array FLOATS = [0.0, 0.1, 1.5, -2.25, 1e100, 1.0E-7, 100000.0];

    /** @return iterable<string, array{int}> */
    public static function seeds(): iterable
    {
        for ($seed = 1; $seed <= 200; ++$seed) {
            yield "seed $seed" => [$seed];
        }
    }

    #[DataProvider('seeds')]
    public function testReadReplaceAndAppendMatchVarExport(int $seed): void
    {
        $random = new Randomizer(new Mt19937($seed));
        $data = [];
        for ($i = $random->getInt(0, 12); $i > 0; --$i) {
            $data['key_' . $i . '_' . $random->getInt(0, 99)] = $this->value($random);
        }

        $source = $this->source($data);
        $file = ParametersFile::fromString($source);
        self::assertSame($data, $file->all(), "Reading failed for seed $seed.");
        self::assertSame($source, $file->toString());

        if ($data !== []) {
            $keys = array_keys($data);
            $key = $keys[$random->getInt(0, count($keys) - 1)];
            $new = $this->value($random);
            if (is_array($new)) {
                $new = 'scalar';
            }
            $replaced = $data;
            $replaced[$key] = $new;

            self::assertSame($this->source($replaced), $file->with($key, $new)->toString(), "Replacing '$key' failed for seed $seed.");
        }

        $appended = $data;
        $appended['appended_key'] = 'appended value';
        self::assertSame($this->source($appended), $file->with('appended_key', 'appended value')->toString(), "Appending failed for seed $seed.");
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function source(array $parameters): string
    {
        return '<?php return ' . var_export(['parameters' => $parameters], true) . ';';
    }

    private function value(Randomizer $random): mixed
    {
        return match ($random->getInt(0, 9)) {
            0 => $random->getInt(-1000, 1000),
            1 => PHP_INT_MAX,
            2 => self::FLOATS[$random->getInt(0, count(self::FLOATS) - 1)],
            3 => $random->getInt(0, 1) === 1,
            4 => null,
            5 => ['a', $this->text($random), 'k' => [1, 2]],
            default => $this->text($random),
        };
    }

    private function text(Randomizer $random): string
    {
        $text = '';
        for ($i = $random->getInt(0, 4); $i > 0; --$i) {
            $text .= self::FRAGMENTS[$random->getInt(0, count(self::FRAGMENTS) - 1)];
        }

        return $text;
    }
}
