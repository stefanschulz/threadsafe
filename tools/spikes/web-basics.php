<?php

/**
 * SPIKE (phase 4, WP 1c, 1d, 1e) - throw-away script, not part of the program.
 *
 * Questions:
 *   c) Is Argon2id there, what does one hash cost in time and PHP memory, and does it fit a small memory_limit?
 *   d) What does building restore.php on request cost (time, peak memory, size)?
 *   e) Can a running SHA-256 be saved between two ticks (serialize a HashContext), what does it cost, how fast is a
 *      verify pass over the bytes of a big archive?
 *
 *   php tools/spikes/web-basics.php [megabytes for the SHA-256 test]
 */
declare(strict_types=1);

use ThreadSafe\Build\RestoreBuilder;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$megabytes = (int) ($argv[1] ?? 512);
echo 'PHP ', PHP_VERSION, ' ', PHP_SAPI, ', memory_limit ', ini_get('memory_limit'), "\n\n";

// ---- c) password hashing
echo "c) password hashing\n";
echo '  algorithms: ', implode(', ', password_algos()), "\n";
foreach (['PASSWORD_ARGON2ID' => PASSWORD_ARGON2ID, 'PASSWORD_BCRYPT' => PASSWORD_BCRYPT] as $label => $algo) {
    $before = memory_get_peak_usage(true);
    $t = microtime(true);
    $hash = password_hash('correct horse battery staple', $algo);
    $hashMs = (microtime(true) - $t) * 1000;
    $t = microtime(true);
    $ok = password_verify('correct horse battery staple', $hash);
    $verifyMs = (microtime(true) - $t) * 1000;
    $bad = password_verify('wrong', $hash);
    printf("  %-18s hash %6.1f ms, verify %6.1f ms, ok=%s bad=%s, peak PHP memory +%d KB, %d chars: %s\n", $label, $hashMs, $verifyMs, var_export($ok, true), var_export($bad, true), (memory_get_peak_usage(true) - $before) / 1024, strlen($hash), substr($hash, 0, 24));
    $info = password_get_info($hash);
    echo '    options: ', json_encode($info['options']), "\n";
}
// does Argon2id work under a tight memory_limit? (it allocates outside the Zend memory manager, so it should)
ini_set('memory_limit', '16M');
$t = microtime(true);
$hash = password_hash('x', PASSWORD_ARGON2ID);
printf("  Argon2id under memory_limit=16M: %s in %.1f ms, needs_rehash(default)=%s\n", password_verify('x', $hash) ? 'works' : 'FAILS', (microtime(true) - $t) * 1000, var_export(password_needs_rehash($hash, PASSWORD_ARGON2ID), true));
ini_set('memory_limit', (string) ini_get('memory_limit') === '16M' ? '512M' : '512M');

// ---- d) building restore.php
echo "\nd) building restore.php on request\n";
$target = sys_get_temp_dir() . '/spike-restore-' . bin2hex(random_bytes(3)) . '.php';
$memBefore = memory_get_usage();
$t = microtime(true);
$result = (new RestoreBuilder(dirname(__DIR__, 2)))->build($target);
$ms = (microtime(true) - $t) * 1000;
printf("  built %d KB (code %d KB, %d files) in %.0f ms, peak memory %.1f MB (process baseline %.1f MB)\n", $result['bytes'] / 1024, $result['bundleBytes'] / 1024, $result['files'] ?? 0, $ms, memory_get_peak_usage() / 1048576, $memBefore / 1048576);
$t = microtime(true);
(new RestoreBuilder(dirname(__DIR__, 2)))->build($target);
printf("  second build %.0f ms\n", (microtime(true) - $t) * 1000);
@unlink($target);

// ---- e) resumable SHA-256
echo "\ne) resumable SHA-256 over $megabytes MB\n";
$file = sys_get_temp_dir() . '/spike-sha-' . bin2hex(random_bytes(3)) . '.bin';
$block = random_bytes(1048576);
$out = fopen($file, 'wb');
for ($i = 0; $i < $megabytes; ++$i) {
    fwrite($out, $block);
}
fclose($out);
$expected = hash_file('sha256', $file);

$t = microtime(true);
hash_file('sha256', $file);
$whole = microtime(true) - $t;
printf("  hash_file: %.2f s = %.0f MB/s\n", $whole, $megabytes / $whole);

// ticks of 64 MB: serialize the context into a string that fits a JSON state file, restore it, go on
$context = hash_init('sha256');
$in = fopen($file, 'rb');
$state = null;
$ticks = 0;
$saveBytes = 0;
$t = microtime(true);
while (!feof($in)) {
    if ($state !== null) {
        $context = unserialize(base64_decode($state));
    }
    for ($i = 0; $i < 64 && !feof($in); ++$i) {
        $data = fread($in, 1048576);
        if ($data === false || $data === '') {
            break;
        }
        hash_update($context, $data);
    }
    $state = base64_encode(serialize($context));
    $json = json_encode(['sha' => $state, 'offset' => ftell($in)], JSON_THROW_ON_ERROR);
    $saveBytes = strlen($json);
    ++$ticks;
}
fclose($in);
$resumed = hash_final(unserialize(base64_decode($state)));
$ticked = microtime(true) - $t;
printf("  in %d ticks with serialize/unserialize of the context: %.2f s = %.0f MB/s, state in JSON %d bytes, result %s\n", $ticks, $ticked, $megabytes / $ticked, $saveBytes, $resumed === $expected ? 'EQUAL to hash_file' : 'DIFFERENT');
@unlink($file);
