<?php
/**
 * SPIKE (phase 7, WP 1) - what a release package would be, and whether a ZIP behind a PHP stub is usable.
 *
 * It answers (docs/PHASE7.md section 8): how many files and bytes the package has with and without the user documentation, how
 * long and how much memory its extraction takes with our own ZipReader, and whether `unzip` and `ZipReader` open a file that is
 * a PHP stub followed by a ZIP whose offsets were shifted by the length of the stub (what `install.php` would be).
 *
 *   php tools/spikes/install-package.php [--work=DIR] [--vendor=DIR]
 *
 * It reads the repository (git ls-files), writes only below --work (default: a temporary directory) and removes it.
 * `--vendor=DIR` is a `vendor/` made by `composer install --no-dev --optimize-autoloader` (the autoloader only).
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use ThreadSafe\Archive\ZipReader;
use ThreadSafe\Archive\ZipWriter;

$options = [];
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--([a-z]+)=(.*)$/', $argument, $m)) {
        $options[$m[1]] = $m[2];
    }
}
$root = dirname(__DIR__, 2);
$work = $options['work'] ?? sys_get_temp_dir() . '/threadsafe-spike-package-' . getmypid();
@mkdir($work, 0777, true);
$fixedTime = 1761955200; // 2025-11-01 00:00:00 UTC, stands for SOURCE_DATE_EPOCH

/** @return list<string> */
function tracked(string $root, array $paths): array
{
    $out = [];
    exec('cd ' . escapeshellarg($root) . ' && git ls-files -- ' . implode(' ', array_map('escapeshellarg', $paths)), $out);
    sort($out);

    return $out;
}

/** @param list<string> $files */
function build(string $root, array $files, string $zip, int $time, string $vendor): array
{
    @unlink($zip);
    $writer = ZipWriter::create($zip, 9);
    $bytes = 0;
    $count = 0;
    foreach ($files as $relative) {
        $path = str_starts_with($relative, 'vendor/') ? $vendor . '/' . substr($relative, 7) : $root . '/' . $relative;
        $content = (string) file_get_contents($path);
        $mode = str_starts_with($relative, 'bin/') ? 0100755 : 0100644;
        $writer->addString('threadsafe-0.1.0/' . $relative, $content, $time, $mode);
        $bytes += strlen($content);
        ++$count;
    }
    $writer->close();

    return ['files' => $count, 'bytes' => $bytes, 'zip' => filesize($zip)];
}

echo "== package contents\n";
$program = tracked($root, ['bin', 'plugins', 'public', 'src', 'composer.json', 'LICENSE', 'README.md']);
$docs = array_values(array_filter(tracked($root, ['docs']), static fn (string $f): bool => in_array(basename($f), ['BACKUP.md', 'RESTORE.md', 'WEB.md', 'SCHEDULE.md', 'ENCRYPTION.md', 'MIGRATE.md', 'JOOMLA.md'], true)));
$vendorFiles = [];
if (isset($options['vendor']) && is_dir($options['vendor'])) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($options['vendor'], FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $vendorFiles[] = 'vendor/' . substr(str_replace('\\', '/', $file->getPathname()), strlen(rtrim($options['vendor'], '/')) + 1);
        }
    }
    sort($vendorFiles);
}
$vendor = $options['vendor'] ?? '';
$small = build($root, array_merge($program, $vendorFiles), $work . '/small.zip', $fixedTime, $vendor);
$large = build($root, array_merge($program, $docs, $vendorFiles), $work . '/large.zip', $fixedTime, $vendor);
printf("without docs: %d files, %.2f MB, zip %.2f MB (vendor files: %d)\n", $small['files'], $small['bytes'] / 1e6, $small['zip'] / 1e6, count($vendorFiles));
printf("with docs:    %d files, %.2f MB, zip %.2f MB\n", $large['files'], $large['bytes'] / 1e6, $large['zip'] / 1e6);

echo "\n== reproducible? (a second build of the same input)\n";
$again = build($root, array_merge($program, $docs, $vendorFiles), $work . '/again.zip', $fixedTime, $vendor);
echo hash_file('sha256', $work . '/large.zip') === hash_file('sha256', $work . '/again.zip') ? "same bytes\n" : "DIFFERENT bytes\n";

echo "\n== a PHP stub followed by the ZIP, offsets shifted by the length of the stub\n";
$stub = "<?php\nif (PHP_VERSION_ID < 80400) { echo 'ThreadSafe needs PHP 8.4'; exit; }\n// the installer would run here\n__halt_compiler();";
$stub .= str_repeat(' ', 16) . "\n";
$zip = (string) file_get_contents($work . '/large.zip');
$shift = strlen($stub);
// the end record: the offset of the central directory (4 bytes at 16) and, in each central header, the local header offset (at 42)
$eocd = strrpos($zip, "PK\x05\x06");
$cdOffset = unpack('V', substr($zip, $eocd + 16, 4))[1];
$cdSize = unpack('V', substr($zip, $eocd + 12, 4))[1];
$entries = unpack('v', substr($zip, $eocd + 10, 2))[1];
$position = $cdOffset;
for ($i = 0; $i < $entries; ++$i) {
    $local = unpack('V', substr($zip, $position + 42, 4))[1];
    $zip = substr_replace($zip, pack('V', $local + $shift), $position + 42, 4);
    $position += 46 + unpack('v', substr($zip, $position + 28, 2))[1] + unpack('v', substr($zip, $position + 30, 2))[1] + unpack('v', substr($zip, $position + 32, 2))[1];
}
$zip = substr_replace($zip, pack('V', $cdOffset + $shift), $eocd + 16, 4);
file_put_contents($work . '/install.php', $stub . $zip);
printf("stub %d bytes, install.php %d bytes, %d entries\n", $shift, filesize($work . '/install.php'), $entries);

$out = [];
exec('unzip -tq ' . escapeshellarg($work . '/install.php') . ' 2>&1', $out, $code);
echo 'unzip -tq install.php: exit ' . $code . ' - ' . implode(' | ', $out) . "\n";
$out = [];
exec('unzip -tq ' . escapeshellarg($work . '/large.zip') . ' 2>&1', $out, $code);
echo 'unzip -tq (no stub):  exit ' . $code . ' - ' . implode(' | ', $out) . "\n";
exec('php ' . escapeshellarg($work . '/install.php') . ' 2>&1', $out2, $code2);
echo 'php install.php runs the stub (exit ' . $code2 . '): ' . implode(' | ', $out2) . "\n";

$reader = ZipReader::open($work . '/install.php');
$verify = $reader->verify();
printf("ZipReader on install.php: %d entries, verify %s\n", $reader->count(), $verify->ok() ? 'ok' : 'FAILED');

echo "\n== extraction with ZipReader (time and peak memory; run with -d memory_limit=64M to see the limit hold)\n";
$target = $work . '/extracted';
@mkdir($target);
$start = microtime(true);
$files = 0;
$bytes = 0;
$dirs = [];
foreach ($reader->entries() as $entry) {
    $name = $entry->name;
    if (str_ends_with($name, '/')) {
        continue;
    }
    $path = $target . '/' . $name;
    if (!isset($dirs[dirname($path)])) {
        @mkdir(dirname($path), 0777, true);
        $dirs[dirname($path)] = true;
    }
    $handle = fopen($path, 'wb');
    $reader->extract($entry, static function (string $block) use ($handle): void {
        fwrite($handle, $block);
    });
    fclose($handle);
    ++$files;
    $bytes += $entry->size;
}
printf("%d files, %.2f MB in %.2f s, peak memory %.1f MB\n", $files, $bytes / 1e6, microtime(true) - $start, memory_get_peak_usage(true) / 1048576);

if (!isset($options['work'])) {
    exec('rm -rf ' . escapeshellarg($work));
}
