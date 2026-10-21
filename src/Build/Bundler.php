<?php

declare(strict_types=1);

namespace ThreadSafe\Build;

use RuntimeException;

/**
 * Turns a set of PHP source files into one: each file becomes a `namespace X { ... }` block, in an order in which every class
 * comes after the classes it extends, implements or uses as a trait. Needs no autoloader and no Composer where it runs, and
 * behaves like the original (the unit suite runs against the bundle, `composer test:bundle`).
 *
 * Token-based, no regular expressions on source text. What it understands: one `namespace X;` per file, `use` imports (also
 * `use function` and `use const`, which stay in the block), classes, interfaces, traits, enums, anonymous classes, attributes.
 * What it refuses with a message, instead of producing a bundle that fails later: grouped imports (`use A\{B, C}`), files with
 * no namespace, circular inheritance. Only inheritance orders the files; everything else (constants, default values, `new`) is
 * evaluated when the code runs, when all classes exist.
 *
 * The bundle keeps `declare(strict_types=1)` once at its top, which holds for every file in it: the sources all use it.
 */
final class Bundler
{
    /**
     * @param list<string> $files   absolute paths of the PHP files
     * @param string       $root    the directory the paths are shown relative to in the comments of the bundle
     * @param string       $comment a line for the top of the bundle
     *
     * @throws RuntimeException a file cannot be bundled
     */
    public function bundle(array $files, string $root = '', string $comment = ''): string
    {
        $units = [];
        $owner = [];
        foreach ($files as $file) {
            $units[$file] = $this->analyse($file);
            foreach ($units[$file]['declared'] as $class) {
                $owner[strtolower($class)] = $file;
            }
        }

        $ordered = $this->order($files, $units, $owner);

        $bundle = "<?php\n\ndeclare(strict_types=1);\n\n// " . ($comment !== '' ? $comment . ': ' : '') . count($ordered) . " files\n";
        foreach ($ordered as $file) {
            $shown = $root !== '' && str_starts_with($file, $root) ? ltrim(substr($file, strlen($root)), '/\\') : $file;
            $bundle .= "\n// " . str_replace('\\', '/', $shown) . "\nnamespace {$units[$file]['namespace']} {\n" . $units[$file]['body'] . "\n}\n";
        }

        return $bundle;
    }

    /**
     * Every class, interface, trait and enum the files declare, with its full name.
     *
     * @param list<string> $files
     *
     * @return list<string>
     */
    public function declared(array $files): array
    {
        $names = [];
        foreach ($files as $file) {
            array_push($names, ...$this->analyse($file)['declared']);
        }

        return $names;
    }

    /**
     * @param list<string>                                                                                                      $files
     * @param array<string, array{namespace: string, declared: list<string>, depends: list<string>, body: string}> $units
     * @param array<string, string>                                                                                              $owner lower-case class name => file
     *
     * @return list<string>
     */
    private function order(array $files, array $units, array $owner): array
    {
        $ordered = [];
        $state = [];
        $visit = static function (string $file, array $trail) use (&$visit, &$ordered, &$state, $units, $owner): void {
            if (($state[$file] ?? 0) === 2) {
                return;
            }
            if (($state[$file] ?? 0) === 1) {
                throw new RuntimeException('Circular inheritance: ' . implode(' -> ', [...$trail, $file]));
            }
            $state[$file] = 1;
            foreach ($units[$file]['depends'] as $dependency) {
                $other = $owner[strtolower($dependency)] ?? null;
                if ($other !== null && $other !== $file) {
                    $visit($other, [...$trail, $file]);
                }
            }
            $state[$file] = 2;
            $ordered[] = $file;
        };
        foreach ($files as $file) {
            $visit($file, []);
        }

        return $ordered;
    }

    /**
     * @return array{namespace: string, declared: list<string>, depends: list<string>, body: string}
     */
    private function analyse(string $path): array
    {
        $source = @file_get_contents($path);
        if ($source === false) {
            throw new RuntimeException("Cannot read \"$path\".");
        }
        $tokens = token_get_all($source);
        $count = count($tokens);
        $namespace = '';
        $uses = [];
        $declared = [];
        $depends = [];
        $bodyStart = null;
        $depth = 0;

        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];
            if ($token === '{') {
                ++$depth;
            } elseif ($token === '}') {
                --$depth;
            }
            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE && $bodyStart === null) {
                ++$i;
                $namespace = $this->name($tokens, $i);
                if (!isset($tokens[$i]) || $tokens[$i] !== ';') {
                    throw new RuntimeException("$path: only the form `namespace X;` is supported.");
                }
                $bodyStart = $i + 1;

                continue;
            }
            if ($bodyStart === null) {
                continue;
            }

            if ($token[0] === T_USE && $depth === 0) {
                $this->import($tokens, $i, $uses, $path);

                continue;
            }
            if ($token[0] === T_USE && $depth >= 1 && $this->isTraitUse($tokens, $i)) {
                // `use TraitName;` inside a class body
                $k = $i + 1;
                while (true) {
                    $name = $this->name($tokens, $k);
                    if ($name !== '') {
                        $depends[] = $name;
                    }
                    if (isset($tokens[$k]) && $tokens[$k] === ',') {
                        ++$k;

                        continue;
                    }
                    break;
                }

                continue;
            }
            if (in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true) && $depth === 0) {
                $previous = $this->previous($tokens, $i);
                if ($previous !== null && is_array($previous) && in_array($previous[0], [T_DOUBLE_COLON, T_NEW], true)) {
                    continue;   // `X::class`, `new class`
                }
                $j = $this->next($tokens, $i);
                if ($j === null || !is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING) {
                    continue;
                }
                $declared[] = ($namespace !== '' ? $namespace . '\\' : '') . $tokens[$j][1];
                for ($k = $j + 1; $k < $count && $tokens[$k] !== '{'; ++$k) {
                    if (is_array($tokens[$k]) && in_array($tokens[$k][0], [T_EXTENDS, T_IMPLEMENTS], true)) {
                        ++$k;
                        while ($k < $count && $tokens[$k] !== '{') {
                            $name = $this->name($tokens, $k);
                            if ($name !== '') {
                                $depends[] = $name;
                            }
                            if (isset($tokens[$k]) && $tokens[$k] === ',') {
                                ++$k;

                                continue;
                            }
                            break;
                        }
                        --$k;
                    }
                }
            }
        }
        if ($bodyStart === null) {
            throw new RuntimeException("$path has no namespace; every file of the program has one.");
        }

        $body = '';
        for ($i = $bodyStart; $i < $count; ++$i) {
            $body .= is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
        }

        return ['namespace' => $namespace, 'declared' => $declared, 'depends' => $this->resolve($depends, $uses, $namespace), 'body' => trim($body, "; \n\r\t") . "\n"];
    }

    /**
     * @param list<string>          $names
     * @param array<string, string> $uses
     *
     * @return list<string>
     */
    private function resolve(array $names, array $uses, string $namespace): array
    {
        $resolved = [];
        foreach ($names as $name) {
            if (str_starts_with($name, '\\')) {
                $resolved[] = ltrim($name, '\\');

                continue;
            }
            $first = strstr($name, '\\', true);
            $first = $first === false ? $name : $first;
            if (isset($uses[$first])) {
                $resolved[] = $uses[$first] . (str_contains($name, '\\') ? substr($name, strlen($first)) : '');
            } else {
                $resolved[] = ($namespace !== '' ? $namespace . '\\' : '') . $name;
            }
        }

        return array_values(array_unique($resolved));
    }

    /**
     * Reads a `use` import at file level into $uses (alias => full name); `use function` and `use const` are left alone.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     * @param array<string, string>                       $uses
     */
    private function import(array $tokens, int &$i, array &$uses, string $path): void
    {
        $kind = false;
        for ($k = $i + 1; isset($tokens[$k]) && is_array($tokens[$k]) && in_array($tokens[$k][0], [T_WHITESPACE, T_FUNCTION, T_CONST], true); ++$k) {
            $kind = $kind || $tokens[$k][0] !== T_WHITESPACE;
        }
        while (true) {
            $name = $this->name($tokens, $k);
            if (isset($tokens[$k]) && $tokens[$k] === '{' || str_ends_with($name, '\\')) {
                throw new RuntimeException("$path: grouped imports (use A\\{B, C}) are not supported by the bundler.");
            }
            $alias = str_contains($name, '\\') ? substr($name, (int) strrpos($name, '\\') + 1) : $name;
            if (isset($tokens[$k]) && is_array($tokens[$k]) && $tokens[$k][0] === T_AS) {
                ++$k;
                $alias = $this->name($tokens, $k);
            }
            if (!$kind && $name !== '') {
                $uses[$alias] = ltrim($name, '\\');
            }
            if (isset($tokens[$k]) && $tokens[$k] === ',') {
                ++$k;

                continue;
            }
            break;
        }
        $i = $k;
    }

    /**
     * Whether a `use` inside a class body imports traits (a closure's `use (...)` is not one).
     *
     * @param array<int, array{int, string, int}|string> $tokens
     */
    private function isTraitUse(array $tokens, int $i): bool
    {
        $next = $this->next($tokens, $i);
        $previous = $this->previous($tokens, $i);

        return $next !== null && $tokens[$next] !== '(' && ($previous === null || $previous === '{' || $previous === '}' || $previous === ';' || (is_array($previous) && in_array($previous[0], [T_DOC_COMMENT, T_COMMENT, T_ATTRIBUTE], true)));
    }

    /**
     * Reads a (qualified) name starting at $i and leaves $i at the first token after it.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     */
    private function name(array $tokens, int &$i): string
    {
        $name = '';
        $count = count($tokens);
        while ($i < $count) {
            $token = $tokens[$i];
            if (is_array($token) && in_array($token[0], [T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
                $name .= $token[1];
            } elseif (!is_array($token) || $token[0] !== T_WHITESPACE) {
                break;
            }
            ++$i;
        }

        return $name;
    }

    /**
     * @param array<int, array{int, string, int}|string> $tokens
     */
    private function next(array $tokens, int $i): ?int
    {
        for ($j = $i + 1, $count = count($tokens); $j < $count; ++$j) {
            if (!is_array($tokens[$j]) || !in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT], true)) {
                return $j;
            }
        }

        return null;
    }

    /**
     * @param array<int, array{int, string, int}|string> $tokens
     *
     * @return array{int, string, int}|string|null
     */
    private function previous(array $tokens, int $i): array|string|null
    {
        for ($j = $i - 1; $j >= 0; --$j) {
            if (!is_array($tokens[$j]) || !in_array($tokens[$j][0], [T_WHITESPACE], true)) {
                return $tokens[$j];
            }
        }

        return null;
    }
}
