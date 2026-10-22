<?php

/**
 * SPIKE (phase 5, WP 1a) - throw-away script. Does an age file with a scrypt passphrase stanza that we make (scrypt in PHP,
 * tools/spikes/scrypt-php.php) open with the real `age` tool, and does the file the real tool makes open with ours?
 *
 *   php tools/spikes/age-passphrase.php [log2(N), default 15]
 *
 * `age` asks for a passphrase on the terminal only, so it is run in a pseudo terminal with `script`.
 */
declare(strict_types=1);

// the functions of the other spikes, without their output
ob_start();
$wanted = (int) ($argv[1] ?? 15);
$argv = [__FILE__, "10"];
require __DIR__ . '/scrypt-php.php';
ob_end_clean();

function b64(string $raw): string
{
    return rtrim(base64_encode($raw), '=');
}

$log = $wanted;
$passphrase = 'kr7m-xq3d-hp9t-w4nc-2vfe';
$dir = sys_get_temp_dir() . '/spike-agepw-' . bin2hex(random_bytes(3));
mkdir($dir);
$plain = random_bytes(200_000);
file_put_contents($dir . '/plain.bin', $plain);

// we make the file
$fileKey = random_bytes(16);
$salt = random_bytes(16);
$t = microtime(true);
$wrapKey = scrypt($passphrase, 'age-encryption.org/v1/scrypt' . $salt, 1 << $log, 8, 1, 32);
$kdf = microtime(true) - $t;
$body = b64(sodium_crypto_aead_chacha20poly1305_ietf_encrypt($fileKey, '', str_repeat("\0", 12), $wrapKey));
$header = "age-encryption.org/v1\n-> scrypt " . b64($salt) . " $log\n$body\n---";
$header .= ' ' . b64(hash_hmac('sha256', $header, hash_hkdf('sha256', $fileKey, 32, 'header'), true)) . "\n";
$nonce = random_bytes(16);
$key = hash_hkdf('sha256', $fileKey, 32, 'payload', $nonce);
$out = $header . $nonce;
$chunks = max(1, intdiv(strlen($plain) + 65535, 65536));
for ($i = 0; $i < $chunks; ++$i) {
    $out .= sodium_crypto_aead_chacha20poly1305_ietf_encrypt(substr($plain, $i * 65536, 65536), '', "\0\0\0" . pack('J', $i) . ($i === $chunks - 1 ? "\1" : "\0"), $key);
}
file_put_contents($dir . '/ours.age', $out);
printf("scrypt N=2^%d in PHP: %.2f s\n", $log, $kdf);

// age opens it: the passphrase is typed on a pseudo terminal
file_put_contents($dir . '/typed.txt', $passphrase . "\n");
exec('script -qec ' . escapeshellarg('age -d -o ' . $dir . '/back.bin ' . $dir . '/ours.age') . ' /dev/null < ' . escapeshellarg($dir . '/typed.txt') . ' 2>&1', $lines, $code);
echo '1. age -d opens our passphrase file: ', is_file($dir . '/back.bin') && file_get_contents($dir . '/back.bin') === $plain ? 'yes, identical' : "NO (exit $code): " . implode(' | ', array_map('trim', $lines)), "\n";

// we open the file age makes (age -p asks twice, or can generate; we type the passphrase twice)
file_put_contents($dir . '/typed2.txt', $passphrase . "\n" . $passphrase . "\n");
exec('script -qec ' . escapeshellarg('age -p -o ' . $dir . '/theirs.age ' . $dir . '/plain.bin') . ' /dev/null < ' . escapeshellarg($dir . '/typed2.txt') . ' 2>&1', $lines2, $code2);
if (!is_file($dir . '/theirs.age')) {
    echo '2. age -p did not make a file (exit ', $code2, '): ', implode(' | ', array_map('trim', $lines2)), "\n";
    exit(0);
}
$file = (string) file_get_contents($dir . '/theirs.age');
$end = strpos($file, "\n--- ");
$lineEnd = strpos($file, "\n", $end + 1);
$headerLines = explode("\n", substr($file, 0, $lineEnd));
preg_match('/^-> scrypt (\S+) (\d+)$/', $headerLines[1], $m);
$theirSalt = base64_decode($m[1] . '==');
$theirLog = (int) $m[2];
printf("   age's own work factor: N=2^%d\n", $theirLog);
$t = microtime(true);
$theirWrap = scrypt($passphrase, 'age-encryption.org/v1/scrypt' . $theirSalt, 1 << $theirLog, 8, 1, 32);
$theirKdf = microtime(true) - $t;
$theirFileKey = sodium_crypto_aead_chacha20poly1305_ietf_decrypt(base64_decode($headerLines[2] . str_repeat('=', (4 - strlen($headerLines[2]) % 4) % 4)), '', str_repeat("\0", 12), $theirWrap);
printf("2. we open age's passphrase file: file key %s, scrypt N=2^%d took %.1f s, peak memory %.0f MB\n", $theirFileKey === false ? 'WRONG' : 'right', $theirLog, $theirKdf, memory_get_peak_usage(true) / 1048576);

exec('rm -rf ' . escapeshellarg($dir));
