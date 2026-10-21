<?php

/**
 * SPIKE (phase 3, WP 1c) - throw-away script, not part of the program.
 *
 * Questions: how fast does the pure PHP reader extract (inflate and write) - so how much fits into one tick - and what does
 * walking the central directory of an archive with 70,000 entries cost per tick?
 *
 *   php tools/spikes/inflate-speed.php [megabytes of text] [entries]
 */
declare(strict_types=1);

use ThreadSafe\Archive\ZipReader;
use ThreadSafe\Archive\ZipWriter;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$megabytes = (int) ($argv[1] ?? 400);
$entries = (int) ($argv[2] ?? 70000);
$directory = sys_get_temp_dir() . '/spike-inflate-' . bin2hex(random_bytes(3));
mkdir($directory);
register_shutdown_function(static function () use ($directory): void {
    foreach (glob($directory . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($directory);
});

// text that compresses like source code or SQL (about 4:1), not like a run of one letter
$words = explode(' ', 'select insert update table values where the quick brown fox jumps over lazy dog product category customer order price name description 12345 67890 id_lang id_shop active date_add');
mt_srand(1);
$unit = '';
while (strlen($unit) < 1048576) {
    $unit .= $words[mt_rand(0, count($words) - 1)] . (mt_rand(0, 9) === 0 ? ",\n" : ' ') . mt_rand(0, 99999) . ' ';
}

$archive = $directory . '/big.zip';
$t = microtime(true);
$writer = ZipWriter::create($archive, 6);
$writer->beginFile('big.sql', time(), 0100644, $megabytes * 1048576);
$buffer = '';
for ($mb = 0; $mb < $megabytes; ++$mb) {
    $buffer .= substr($unit, mt_rand(0, 1000), 1048000) . "\n";
    while (strlen($buffer) >= ZipWriter::BLOCK) {
        $writer->writeBlock(substr($buffer, 0, ZipWriter::BLOCK));
        $buffer = substr($buffer, ZipWriter::BLOCK);
    }
}
$writer->endFile($buffer);
$info = $writer->close();
printf("archive: %d MB of text -> %.0f MB (ratio %.1f), written at %.0f MB/s\n", $megabytes, $info->size / 1048576, $megabytes * 1048576 / $info->size, $megabytes / (microtime(true) - $t));

$reader = ZipReader::open($archive);
$entry = $reader->find('big.sql');
foreach (['to /dev/null' => null, 'to a file' => $directory . '/out.sql'] as $label => $target) {
    $handle = $target === null ? null : fopen($target, 'wb');
    $t = microtime(true);
    $bytes = 0;
    $reader->extract($entry, static function (string $chunk) use (&$bytes, $handle): void {
        $bytes += strlen($chunk);
        if ($handle !== null) {
            fwrite($handle, $chunk);
        }
    });
    if ($handle !== null) {
        fclose($handle);
    }
    $seconds = microtime(true) - $t;
    printf("extract %s: %.0f MB in %.1fs = %.0f MB/s (%.1f GB per 60 s), peak memory %.0f MB\n", $label, $bytes / 1048576, $seconds, $bytes / 1048576 / $seconds, $bytes / 1048576 / $seconds * 60 / 1024, memory_get_peak_usage(true) / 1048576);
}
@unlink($directory . '/out.sql');
@unlink($archive);

// many small entries
$many = $directory . '/many.zip';
$t = microtime(true);
$writer = ZipWriter::create($many, 6);
for ($i = 0; $i < $entries; ++$i) {
    $writer->addString('files/modules/mod' . intdiv($i, 100) . '/file' . $i . '.php', "<?php // file $i\n" . str_repeat('x', 200), 1_700_000_000);
}
$writer->close();
printf("archive with %d entries: %.1f MB, written in %.1fs\n", $entries, filesize($many) / 1048576, microtime(true) - $t);

$reader = ZipReader::open($many);
$t = microtime(true);
$n = 0;
foreach ($reader->entries() as $e) {
    ++$n;
}
printf("walking the central directory: %d entries in %.2fs (that is the cost of one tick that starts at the beginning)\n", $n, microtime(true) - $t);

$t = microtime(true);
$files = 0;
foreach ($reader->entries() as $e) {
    $target = $directory . '/x' . ($files % 50);
    if (!is_dir($target)) {
        mkdir($target);
    }
    $out = fopen($target . '/f' . $files, 'wb');
    $reader->extract($e, static function (string $chunk) use ($out): void {
        fwrite($out, $chunk);
    });
    fclose($out);
    ++$files;
}
$seconds = microtime(true) - $t;
printf("extracting %d small files: %.1fs = %.0f files/s (%.0f s for 70,000 files)\n", $files, $seconds, $files / $seconds, 70000 / ($files / $seconds));
