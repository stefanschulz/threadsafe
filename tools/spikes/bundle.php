<?php

/**
 * SPIKE (phase 3, WP 1a) - throw-away script, not part of the program.
 *
 * Questions:
 *  - Can src/ and the plugins be bundled into ONE PHP file (namespace blocks, classes in dependency order) that behaves like
 *    the original (the whole unit suite runs against the bundle)?
 *  - Can a stub written in PHP 5-compatible syntax, followed by `__halt_compiler()` and a payload, print a clear message on an
 *    old PHP and start the bundle on PHP 8.4? What does it do about resources (JSON files the plugins read from disk)?
 *
 *   php tools/spikes/bundle.php build <output.php>      writes the bundle (classes only) to <output.php>
 *   php tools/spikes/bundle.php restore <restore.php>   writes stub + payload (bundle and resource files) to <restore.php>
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$mode = $argv[1] ?? '';
$output = $argv[2] ?? '';
if (!in_array($mode, ['build', 'restore'], true) || $output === '') {
    fwrite(STDERR, "usage: php tools/spikes/bundle.php build|restore <output>\n");
    exit(1);
}

/** @return list<string> */
function phpFiles(string $directory): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php' && !str_contains($file->getPathname(), DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR)) {
            $files[] = $file->getPathname();
        }
    }
    sort($files);

    return $files;
}

/**
 * Splits a source file into namespace, imports, declared types with their dependencies and the body after the namespace line.
 *
 * @return array{namespace: string, uses: array<string, string>, declared: list<string>, depends: list<string>, body: string}
 */
function analyse(string $path): array
{
    $source = (string) file_get_contents($path);
    $tokens = token_get_all($source);
    $namespace = '';
    $uses = [];
    $declared = [];
    $depends = [];
    $bodyStart = null;
    $count = count($tokens);

    $names = static function (int &$i) use ($tokens, $count): string {
        $name = '';
        while ($i < $count) {
            $t = $tokens[$i];
            if (is_array($t) && in_array($t[0], [T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
                $name .= $t[1];
            } elseif (is_array($t) && $t[0] === T_WHITESPACE) {
                // skip
            } else {
                break;
            }
            ++$i;
        }

        return $name;
    };

    $depth = 0;
    for ($i = 0; $i < $count; ++$i) {
        $t = $tokens[$i];
        if ($t === '{') {
            ++$depth;
        } elseif ($t === '}') {
            --$depth;
        }
        if (!is_array($t)) {
            continue;
        }
        if ($t[0] === T_NAMESPACE && $namespace === '') {
            ++$i;
            $namespace = $names($i);
            $bodyStart = $i + 1;   // after the semicolon
            continue;
        }
        if ($t[0] === T_USE && $depth === 0 && $bodyStart !== null) {
            ++$i;
            $kind = '';
            while ($i < $count && is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_FUNCTION, T_CONST], true)) {
                $kind = $tokens[$i][0] === T_WHITESPACE ? $kind : 'x';
                ++$i;
            }
            $name = $names($i);
            $alias = substr($name, (int) strrpos($name, '\\') + (str_contains($name, '\\') ? 1 : 0));
            while ($i < $count && $tokens[$i] !== ';') {
                if (is_array($tokens[$i]) && $tokens[$i][0] === T_AS) {
                    ++$i;
                    $alias = $names($i);
                    continue;
                }
                ++$i;
            }
            if ($kind === '') {
                $uses[$alias] = ltrim($name, '\\');
            }
            continue;
        }
        if (in_array($t[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true) && $bodyStart !== null && $depth === 0) {
            $prev = $tokens[$i - 1] ?? null;
            if (is_array($prev) && $prev[0] === T_DOUBLE_COLON) {
                continue;
            }
            $j = $i + 1;
            while (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                ++$j;
            }
            if (!is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING) {
                continue;
            }
            $declared[] = ($namespace !== '' ? $namespace . '\\' : '') . $tokens[$j][1];
            // the header up to the opening brace: extends / implements / enum backing type
            for ($k = $j + 1; $k < $count && $tokens[$k] !== '{'; ++$k) {
                if (is_array($tokens[$k]) && in_array($tokens[$k][0], [T_EXTENDS, T_IMPLEMENTS], true)) {
                    ++$k;
                    while ($k < $count && $tokens[$k] !== '{') {
                        $name = $names($k);
                        if ($name !== '') {
                            $depends[] = $name;
                        }
                        if ($tokens[$k] === ',') {
                            ++$k;
                        } elseif (is_array($tokens[$k]) && in_array($tokens[$k][0], [T_IMPLEMENTS], true)) {
                            ++$k;
                        } else {
                            break;
                        }
                    }
                    --$k;
                }
            }
        }
        if ($t[0] === T_USE && $depth >= 1 && $bodyStart !== null) {
            // `use TraitName;` inside a class body
            $k = $i + 1;
            $name = $names($k);
            if ($name !== '' && !str_contains($name, '(')) {
                $depends[] = $name;
            }
        }
    }
    if ($bodyStart === null) {
        throw new RuntimeException("$path has no namespace.");
    }

    // resolve the dependencies to full names
    $resolved = [];
    foreach ($depends as $name) {
        if (str_starts_with($name, '\\')) {
            $resolved[] = ltrim($name, '\\');
            continue;
        }
        $first = strstr($name, '\\', true) ?: $name;
        $resolved[] = isset($uses[$first]) ? $uses[$first] . (str_contains($name, '\\') ? substr($name, strlen($first)) : '') : ($namespace !== '' ? $namespace . '\\' : '') . $name;
    }

    // body: everything after `namespace X;` without the opening tag and the declare statement
    $body = '';
    for ($i = $bodyStart; $i < $count; ++$i) {
        $body .= is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
    }
    $body = ltrim($body, "; \n\r\t");

    return ['namespace' => $namespace, 'uses' => $uses, 'declared' => $declared, 'depends' => array_values(array_unique($resolved)), 'body' => $body];
}

$files = [...phpFiles($root . '/src'), ...phpFiles($root . '/plugins')];
$units = [];
$owner = [];
foreach ($files as $file) {
    $info = analyse($file);
    $units[$file] = $info;
    foreach ($info['declared'] as $class) {
        $owner[$class] = $file;
    }
}

// dependency order: a file after the files that declare what its classes extend or implement
$ordered = [];
$state = [];
$visit = static function (string $file) use (&$visit, &$ordered, &$state, $units, $owner): void {
    if (($state[$file] ?? 0) === 2) {
        return;
    }
    if (($state[$file] ?? 0) === 1) {
        throw new RuntimeException("Circular dependency at $file");
    }
    $state[$file] = 1;
    foreach ($units[$file]['depends'] as $dependency) {
        if (isset($owner[$dependency]) && $owner[$dependency] !== $file) {
            $visit($owner[$dependency]);
        }
    }
    $state[$file] = 2;
    $ordered[] = $file;
};
foreach ($files as $file) {
    $visit($file);
}

$bundle = "<?php\n\ndeclare(strict_types=1);\n\n// ThreadSafe bundle (spike): " . count($ordered) . " files\n";
foreach ($ordered as $file) {
    $info = $units[$file];
    $bundle .= "\n// " . str_replace($root . DIRECTORY_SEPARATOR, '', $file) . "\nnamespace {$info['namespace']} {\n" . $info['body'] . "\n}\n";
}
// the one change the program needs to run from a bundle: where its plugins and resources are
$bundle = str_replace('return dirname(__DIR__, 2);', "return defined('THREADSAFE_PROGRAM_DIR') ? THREADSAFE_PROGRAM_DIR : dirname(__DIR__, 2);", $bundle);

if ($mode === 'build') {
    file_put_contents($output, $bundle);
    printf("bundle: %d files, %d KB, written to %s\n", count($ordered), strlen($bundle) / 1024, $output);
    exit(0);
}

// ---- restore.php: a stub that old PHP versions can parse, then the payload
$resources = [];
$base = $root . '/plugins';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    $path = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($base) + 1));
    if ($file->isFile() && (str_contains($path, '/resources/') || basename($path) === 'plugin.json')) {
        $resources['plugins/' . $path] = base64_encode((string) file_get_contents($file->getPathname()));
    }
}
$payload = gzdeflate((string) json_encode(['bundle' => base64_encode($bundle), 'files' => $resources]), 9);

$stub = <<<'STUB'
<?php
// restore.php (spike). This part must stay readable by old PHP versions: no namespaces, no ::class, no short arrays, no types.
if (version_compare(PHP_VERSION, '8.4.0', '<')) {
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        header('Content-Type: text/plain; charset=utf-8');
        header('HTTP/1.1 500 Internal Server Error');
    }
    echo "This restore tool needs PHP 8.4 or newer; this server runs PHP " . PHP_VERSION . ".\n";
    echo "Ask your host for a newer PHP version (often a setting in the control panel) and reload this page.\n";
    exit(1);
}
$threadsafeDir = dirname(__FILE__) . '/.threadsafe-restore';
$threadsafeId = filesize(__FILE__) . '-' . filemtime(__FILE__);
$threadsafeBundle = $threadsafeDir . '/bundle-' . $threadsafeId . '.php';
if (!is_file($threadsafeBundle)) {
    if (!is_dir($threadsafeDir) && !@mkdir($threadsafeDir, 0700)) {
        echo "Cannot create " . $threadsafeDir . "; make this directory writable.\n";
        exit(1);
    }
    $handle = fopen(__FILE__, 'rb');
    fseek($handle, __COMPILER_HALT_OFFSET__);
    $data = json_decode(gzinflate(stream_get_contents($handle)), true);
    fclose($handle);
    foreach ($data['files'] as $relative => $content) {
        $target = $threadsafeDir . '/program/' . $relative;
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0700, true);
        }
        file_put_contents($target, base64_decode($content));
    }
    file_put_contents($threadsafeBundle . '.tmp', base64_decode($data['bundle']));
    rename($threadsafeBundle . '.tmp', $threadsafeBundle);
}
define('THREADSAFE_PROGRAM_DIR', $threadsafeDir . '/program');
require $threadsafeBundle;
\ThreadSafe\Spike\Entry::run();
__halt_compiler();
STUB;

// the spike's entry class: proves that code, plugins and resources work from the bundle
$entry = <<<'ENTRY'
<?php
namespace ThreadSafe\Spike;
final class Entry
{
    public static function run(): void
    {
        $registry = \ThreadSafe\Plugin\PluginRegistry::load();
        $filters = $registry->backupable('prestashop9')->defaultFilters(new \ThreadSafe\Plugin\DetectedSite('PrestaShop', '9.0.2', ['adminDirectory' => 'admin-x']));
        echo "ThreadSafe ", \ThreadSafe\Core\Version::CURRENT, " on PHP ", PHP_VERSION, "\n";
        echo "plugins: ", implode(', ', $registry->ids()), "\n";
        echo "default excludes (from a resource file): ", count($filters->excludePaths), "\n";
        echo "program dir: ", \ThreadSafe\Core\Paths::program(), "\n";
    }
}
ENTRY;
$bundleWithEntry = $bundle . "\n" . substr($entry, strlen("<?php\n")) . "\n";
$bundleWithEntry = str_replace("namespace ThreadSafe\\Spike;\nfinal class Entry", "namespace ThreadSafe\\Spike {\nfinal class Entry", $bundleWithEntry) . "}\n";
$payload = gzdeflate((string) json_encode(['bundle' => base64_encode($bundleWithEntry), 'files' => $resources]), 9);
file_put_contents($output, $stub . $payload);
printf("restore.php: %d KB (payload %d KB, %d resource files)\n", filesize($output) / 1024, strlen($payload) / 1024, count($resources));
