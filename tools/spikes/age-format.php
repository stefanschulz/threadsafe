<?php

/**
 * SPIKE (phase 5, WP 1a) - throw-away script, not part of the program.
 *
 * Questions:
 *   1. Can the `age` v1 format (X25519 recipients, chunked ChaCha20-Poly1305) be written and read in PHP with ext-sodium so that the
 *      real `age` tool opens what we make and we open what it makes - at every awkward size?
 *   2. How fast is it (MB/s)?
 *   3. Can a seekable decrypting stream wrapper be read by ZipReader without a decrypted copy, and what does that cost?
 *
 *   php tools/spikes/age-format.php [megabytes for the speed test]
 *
 * Needs the tools container (php with sodium, `age`, `age-keygen`).
 */
declare(strict_types=1);

use ThreadSafe\Archive\ZipReader;
use ThreadSafe\Archive\ZipWriter;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

final class Bech32
{
    private const CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';

    /** @param string $data raw bytes */
    public static function encode(string $hrp, string $data): string
    {
        $values = self::convert(array_values(unpack('C*', $data) ?: []), 8, 5, true);
        $checksum = self::checksum($hrp, $values);
        $out = $hrp . '1';
        foreach ([...$values, ...$checksum] as $v) {
            $out .= self::CHARSET[$v];
        }

        return $out;
    }

    /** @return array{string, string} hrp (lower case) and raw bytes */
    public static function decode(string $text): array
    {
        $text = strtolower($text);
        $split = strrpos($text, '1');
        $hrp = substr($text, 0, $split);
        $values = [];
        foreach (str_split(substr($text, $split + 1)) as $c) {
            $values[] = strpos(self::CHARSET, $c);
        }
        if (self::polymod([...self::hrpExpand($hrp), ...$values]) !== 1) {
            throw new RuntimeException('bad bech32 checksum');
        }
        $bytes = self::convert(array_slice($values, 0, -6), 5, 8, false);

        return [$hrp, pack('C*', ...$bytes)];
    }

    private static function polymod(array $values): int
    {
        $generator = [0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3];
        $chk = 1;
        foreach ($values as $v) {
            $top = $chk >> 25;
            $chk = (($chk & 0x1ffffff) << 5) ^ $v;
            for ($i = 0; $i < 5; ++$i) {
                if ((($top >> $i) & 1) !== 0) {
                    $chk ^= $generator[$i];
                }
            }
        }

        return $chk;
    }

    private static function hrpExpand(string $hrp): array
    {
        $out = [];
        foreach (str_split($hrp) as $c) {
            $out[] = ord($c) >> 5;
        }
        $out[] = 0;
        foreach (str_split($hrp) as $c) {
            $out[] = ord($c) & 31;
        }

        return $out;
    }

    private static function checksum(string $hrp, array $values): array
    {
        $mod = self::polymod([...self::hrpExpand($hrp), ...$values, 0, 0, 0, 0, 0, 0]) ^ 1;
        $out = [];
        for ($i = 0; $i < 6; ++$i) {
            $out[] = ($mod >> (5 * (5 - $i))) & 31;
        }

        return $out;
    }

    private static function convert(array $data, int $from, int $to, bool $pad): array
    {
        $acc = 0;
        $bits = 0;
        $out = [];
        $max = (1 << $to) - 1;
        foreach ($data as $v) {
            $acc = ($acc << $from) | $v;
            $bits += $from;
            while ($bits >= $to) {
                $bits -= $to;
                $out[] = ($acc >> $bits) & $max;
            }
            $acc &= (1 << $bits) - 1;
        }
        if ($pad && $bits > 0) {
            $out[] = ($acc << ($to - $bits)) & $max;
        }

        return $out;
    }
}

final class Age
{
    public const CHUNK = 65536;

    /** @return array{string, string} secret key ("AGE-SECRET-KEY-1...") and recipient ("age1...") */
    public static function generate(): array
    {
        $secret = random_bytes(32);

        return [strtoupper(Bech32::encode('age-secret-key-', $secret)), Bech32::encode('age', sodium_crypto_scalarmult_base($secret))];
    }

    public static function publicOf(string $secretKey): string
    {
        return Bech32::encode('age', sodium_crypto_scalarmult_base(self::secretBytes($secretKey)));
    }

    public static function secretBytes(string $secretKey): string
    {
        [, $bytes] = Bech32::decode($secretKey);

        return $bytes;
    }

    private static function b64(string $raw): string
    {
        return rtrim(base64_encode($raw), '=');
    }

    private static function unb64(string $text): string
    {
        return (string) base64_decode($text . str_repeat('=', (4 - strlen($text) % 4) % 4), true);
    }

    /**
     * @param list<string> $recipients age1... public keys
     *
     * @return array{string, string} the header text and the file key
     */
    public static function header(array $recipients, ?string $fileKey = null): array
    {
        $fileKey ??= random_bytes(16);
        $text = "age-encryption.org/v1\n";
        foreach ($recipients as $recipient) {
            [, $public] = Bech32::decode($recipient);
            $ephemeral = random_bytes(32);
            $share = sodium_crypto_scalarmult_base($ephemeral);
            $shared = sodium_crypto_scalarmult($ephemeral, $public);
            $wrap = hash_hkdf('sha256', $shared, 32, 'age-encryption.org/v1/X25519', $share . $public);
            $body = sodium_crypto_aead_chacha20poly1305_ietf_encrypt($fileKey, '', str_repeat("\0", 12), $wrap);
            $text .= '-> X25519 ' . self::b64($share) . "\n";
            $encoded = self::b64($body);
            foreach (str_split($encoded, 64) as $line) {
                $text .= $line . "\n";
            }
            if (strlen($encoded) % 64 === 0) {
                $text .= "\n";
            }
        }
        $text .= '---';
        $mac = hash_hmac('sha256', $text, hash_hkdf('sha256', $fileKey, 32, 'header'), true);

        return [$text . ' ' . self::b64($mac) . "\n", $fileKey];
    }

    /** @return string the whole encrypted file for a plaintext (for the size tests; the speed test streams) */
    public static function encryptString(string $plain, array $recipients): string
    {
        [$header, $fileKey] = self::header($recipients);
        $nonce = random_bytes(16);
        $key = hash_hkdf('sha256', $fileKey, 32, 'payload', $nonce);
        $out = $header . $nonce;
        $length = strlen($plain);
        $chunks = max(1, intdiv($length + self::CHUNK - 1, self::CHUNK));
        for ($i = 0; $i < $chunks; ++$i) {
            $out .= sodium_crypto_aead_chacha20poly1305_ietf_encrypt(substr($plain, $i * self::CHUNK, self::CHUNK), '', self::nonce($i, $i === $chunks - 1), $key);
        }

        return $out;
    }

    /** @param resource $in @param resource $out */
    public static function encryptStream($in, $out, array $recipients): void
    {
        [$header, $fileKey] = self::header($recipients);
        $nonce = random_bytes(16);
        $key = hash_hkdf('sha256', $fileKey, 32, 'payload', $nonce);
        fwrite($out, $header . $nonce);
        $i = 0;
        $current = (string) fread($in, self::CHUNK);
        while (true) {
            $next = $current === '' || strlen($current) < self::CHUNK ? '' : (string) fread($in, self::CHUNK);
            $last = $next === '';
            fwrite($out, sodium_crypto_aead_chacha20poly1305_ietf_encrypt($current, '', self::nonce($i, $last), $key));
            if ($last) {
                break;
            }
            $current = $next;
            ++$i;
        }
    }

    public static function nonce(int $counter, bool $last): string
    {
        return "\0\0\0" . pack('J', $counter) . ($last ? "\1" : "\0");
    }

    /**
     * Parses a header and finds the file key with a secret key.
     *
     * @return array{string, int} the payload key material (file key) and the offset of the payload nonce
     */
    public static function open(string $head, string $secretKey): array
    {
        $end = strpos($head, "\n--- ");
        if ($end === false) {
            throw new RuntimeException('no end of header');
        }
        $lineEnd = strpos($head, "\n", $end + 1);
        $lines = explode("\n", substr($head, 0, $lineEnd));
        $secret = self::secretBytes($secretKey);
        $mine = sodium_crypto_scalarmult_base($secret);
        $fileKey = null;
        for ($i = 1; $i < count($lines) - 1; ++$i) {
            if (!str_starts_with($lines[$i], '-> ')) {
                continue;
            }
            $args = explode(' ', substr($lines[$i], 3));
            $body = '';
            while (isset($lines[$i + 1]) && !str_starts_with($lines[$i + 1], '-> ') && !str_starts_with($lines[$i + 1], '--- ')) {
                $body .= $lines[++$i];
                if (strlen($lines[$i]) < 64) {
                    break;
                }
            }
            if ($args[0] !== 'X25519' || $fileKey !== null) {
                continue;
            }
            $share = self::unb64($args[1]);
            $shared = sodium_crypto_scalarmult($secret, $share);
            $wrap = hash_hkdf('sha256', $shared, 32, 'age-encryption.org/v1/X25519', $share . $mine);
            $try = sodium_crypto_aead_chacha20poly1305_ietf_decrypt(self::unb64($body), '', str_repeat("\0", 12), $wrap);
            if ($try !== false) {
                $fileKey = $try;
            }
        }
        if ($fileKey === null) {
            throw new RuntimeException('no recipient matches this key');
        }
        $macInput = substr($head, 0, $end + 4);
        $mac = self::unb64(substr($lines[count($lines) - 1], 4));
        if (!hash_equals(hash_hmac('sha256', $macInput, hash_hkdf('sha256', $fileKey, 32, 'header'), true), $mac)) {
            throw new RuntimeException('bad header MAC');
        }

        return [$fileKey, $lineEnd + 1];
    }

    public static function decryptString(string $file, string $secretKey): string
    {
        [$fileKey, $offset] = self::open(substr($file, 0, 8192), $secretKey);
        $key = hash_hkdf('sha256', $fileKey, 32, 'payload', substr($file, $offset, 16));
        $payload = substr($file, $offset + 16);
        $total = strlen($payload);
        $step = self::CHUNK + 16;
        $chunks = max(1, intdiv($total + $step - 1, $step));
        $out = '';
        for ($i = 0; $i < $chunks; ++$i) {
            $plain = sodium_crypto_aead_chacha20poly1305_ietf_decrypt(substr($payload, $i * $step, $step), '', self::nonce($i, $i === $chunks - 1), $key);
            if ($plain === false) {
                throw new RuntimeException("chunk $i does not authenticate");
            }
            $out .= $plain;
        }

        return $out;
    }
}

/** A read-only, seekable stream over an age file: agedec:///path with the context option agedec[identity]. */
final class AgeDecryptStream
{
    /** @var resource|null */
    public $context;

    /** @var resource */
    private $file;

    private string $key = '';

    private int $payload = 0;

    private int $size = 0;

    private int $position = 0;

    private int $cachedIndex = -1;

    private string $cached = '';

    private int $chunks = 0;

    public static function register(): void
    {
        stream_wrapper_register('agedec', self::class);
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        $file = fopen(substr($path, strlen('agedec://')), 'rb');
        if ($file === false) {
            return false;
        }
        $this->file = $file;
        $identity = (string) (stream_context_get_options($this->context)['agedec']['identity'] ?? '');
        [$fileKey, $offset] = Age::open((string) fread($file, 8192), $identity);
        fseek($file, $offset);
        $this->key = hash_hkdf('sha256', $fileKey, 32, 'payload', (string) fread($file, 16));
        $this->payload = $offset + 16;
        $total = (int) fstat($file)['size'] - $this->payload;
        $step = Age::CHUNK + 16;
        $full = intdiv($total, $step);
        $rest = $total % $step;
        if ($rest !== 0 && $rest < 16) {
            return false;
        }
        $this->chunks = $full + ($rest > 0 ? 1 : 0);
        $this->size = $full * Age::CHUNK + ($rest > 0 ? $rest - 16 : 0);

        return true;
    }

    public function stream_read(int $count): string|false
    {
        $out = '';
        while ($count > 0 && $this->position < $this->size) {
            $index = intdiv($this->position, Age::CHUNK);
            if ($index !== $this->cachedIndex) {
                fseek($this->file, $this->payload + $index * (Age::CHUNK + 16));
                $plain = sodium_crypto_aead_chacha20poly1305_ietf_decrypt((string) fread($this->file, Age::CHUNK + 16), '', Age::nonce($index, $index === $this->chunks - 1), $this->key);
                if ($plain === false) {
                    return false;
                }
                $this->cached = $plain;
                $this->cachedIndex = $index;
            }
            $from = $this->position - $index * Age::CHUNK;
            $piece = substr($this->cached, $from, $count);
            $out .= $piece;
            $this->position += strlen($piece);
            $count -= strlen($piece);
        }

        return $out;
    }

    public function stream_eof(): bool
    {
        return $this->position >= $this->size;
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    public function stream_seek(int $offset, int $whence): bool
    {
        $target = match ($whence) {
            SEEK_SET => $offset,
            SEEK_CUR => $this->position + $offset,
            SEEK_END => $this->size + $offset,
            default => -1,
        };
        if ($target < 0) {
            return false;
        }
        $this->position = $target;

        return true;
    }

    /** @return array<string, int> */
    public function stream_stat(): array
    {
        return ['size' => $this->size, 'mode' => 0100644];
    }

    public function stream_close(): void
    {
        fclose($this->file);
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return false;
    }
}

// ------------------------------------------------------------------------------------------------ the checks

function run(string $command): array
{
    exec($command . ' 2>&1', $lines, $code);

    return [$code, implode("\n", $lines)];
}

$dir = sys_get_temp_dir() . '/spike-age-' . bin2hex(random_bytes(3));
mkdir($dir);
echo 'PHP ', PHP_VERSION, ', sodium ', SODIUM_LIBRARY_VERSION, ', ', run('age --version')[1], "\n\n";

// 0. bech32 and the keys against age-keygen
[$code, $out] = run('age-keygen -o ' . escapeshellarg($dir . '/key.txt'));
$keyFile = (string) file_get_contents($dir . '/key.txt');
preg_match('/^AGE-SECRET-KEY-1[A-Z0-9]+$/m', $keyFile, $m);
$secret = $m[0];
preg_match('/public key: (age1[a-z0-9]+)/', $keyFile, $m);
$public = $m[1];
echo "0. keys: public key of the secret key ", Age::publicOf($secret) === $public ? 'EQUALS' : 'DIFFERS FROM', " the one age-keygen wrote\n";
[$mySecret, $myPublic] = Age::generate();
file_put_contents($dir . '/mine.txt', "# created: spike\n# public key: $myPublic\n$mySecret\n");
[$code, $out] = run('age-keygen -y ' . escapeshellarg($dir . '/mine.txt'));
echo '   age-keygen -y on a key we made prints ', trim($out) === $myPublic ? 'the same public key' : "SOMETHING ELSE ($out)", "\n";

// 1. sizes, both directions
$sizes = [0, 1, 15, 16, 17, 65535, 65536, 65537, 131072, 131073, 3 * 65536, 200_001];
$bad = [];
foreach ($sizes as $size) {
    $plain = $size === 0 ? "" : random_bytes($size);
    file_put_contents($dir . '/plain.bin', $plain);

    // we encrypt, age decrypts (for the key age made, and for one of two recipients)
    file_put_contents($dir . '/ours.age', Age::encryptString($plain, [$myPublic, $public]));
    foreach (['key.txt' => 'age-keygen key', 'mine.txt' => 'our key'] as $file => $label) {
        [$code, $out] = run('age -d -i ' . escapeshellarg($dir . '/' . $file) . ' -o ' . escapeshellarg($dir . '/back.bin') . ' ' . escapeshellarg($dir . '/ours.age'));
        if ($code !== 0 || (string) file_get_contents($dir . '/back.bin') !== $plain) {
            $bad[] = "size $size: age could not open ours with the $label: $out";
        }
    }
    // age encrypts, we decrypt
    run('age -r ' . escapeshellarg($public) . ' -o ' . escapeshellarg($dir . '/theirs.age') . ' ' . escapeshellarg($dir . '/plain.bin'));
    try {
        if (Age::decryptString((string) file_get_contents($dir . '/theirs.age'), $secret) !== $plain) {
            $bad[] = "size $size: we decrypted age's file to something else";
        }
    } catch (Throwable $e) {
        $bad[] = "size $size: we could not open age's file: " . $e->getMessage();
    }
    // the streaming writer makes the same thing as the string writer
    $in = fopen($dir . '/plain.bin', 'rb');
    $out = fopen($dir . '/stream.age', 'wb');
    Age::encryptStream($in, $out, [$public]);
    fclose($in);
    fclose($out);
    if (Age::decryptString((string) file_get_contents($dir . '/stream.age'), $secret) !== $plain) {
        $bad[] = "size $size: the streaming writer's file does not decrypt";
    }
}
echo '1. sizes ', implode(', ', $sizes), ': ', $bad === [] ? 'all open in both directions' : "PROBLEMS:\n   " . implode("\n   ", $bad), "\n";

// 2. a flipped bit, a truncation, a wrong key must be refused
$plain = random_bytes(200_000);
$file = Age::encryptString($plain, [$public]);
$refused = [];
foreach (['flipped bit in the payload' => substr($file, 0, 300) . chr(ord($file[300]) ^ 1) . substr($file, 301), 'cut after a whole chunk' => substr($file, 0, strlen($file) - (200_000 % 65536) - 16), 'cut in the last chunk' => substr($file, 0, -5)] as $label => $broken) {
    try {
        Age::decryptString($broken, $secret);
        $refused[] = "NOT REFUSED: $label";
    } catch (Throwable) {
        $refused[] = "refused: $label";
    }
}
try {
    Age::decryptString($file, $mySecret);
    $refused[] = 'NOT REFUSED: wrong key';
} catch (Throwable) {
    $refused[] = 'refused: wrong key';
}
echo "2. ", implode('; ', $refused), "\n";

// 3. speed
$megabytes = (int) ($argv[1] ?? 128);
$source = $dir . '/big.bin';
$handle = fopen($source, 'wb');
$block = random_bytes(1048576);
for ($i = 0; $i < $megabytes; ++$i) {
    fwrite($handle, $block);
}
fclose($handle);
$in = fopen($source, 'rb');
$out = fopen($dir . '/big.age', 'wb');
$t = microtime(true);
Age::encryptStream($in, $out, [$public]);
fclose($in);
fclose($out);
$encrypt = microtime(true) - $t;
$t = microtime(true);
[$code, $o] = run('age -d -i ' . escapeshellarg($dir . '/key.txt') . ' -o ' . escapeshellarg($dir . '/big.back') . ' ' . escapeshellarg($dir . '/big.age'));
$ageDecrypt = microtime(true) - $t;
printf("3. %d MB: we encrypt %.2f s = %.0f MB/s; age decrypts it in %.2f s (%s)\n", $megabytes, $encrypt, $megabytes / $encrypt, $ageDecrypt, $code === 0 && hash_file('sha256', $dir . '/big.back') === hash_file('sha256', $source) ? 'identical' : 'DIFFERENT');
$t = microtime(true);
$in = fopen($dir . '/big.age', 'rb');
$head = (string) fread($in, 8192);
[$fileKey, $offset] = Age::open($head, $secret);
fseek($in, $offset);
$key = hash_hkdf('sha256', $fileKey, 32, 'payload', (string) fread($in, 16));
$i = 0;
$bytes = 0;
$total = filesize($dir . '/big.age') - $offset - 16;
$chunks = intdiv($total + Age::CHUNK + 15, Age::CHUNK + 16);
while (!feof($in) && $i < $chunks) {
    $plain = sodium_crypto_aead_chacha20poly1305_ietf_decrypt((string) fread($in, Age::CHUNK + 16), '', Age::nonce($i, $i === $chunks - 1), $key);
    $bytes += strlen((string) $plain);
    ++$i;
}
$decrypt = microtime(true) - $t;
printf("   we decrypt it in %.2f s = %.0f MB/s (%d MB)\n", $decrypt, $megabytes / $decrypt, $bytes / 1048576);

// 4. a ZIP read through the seekable stream
$zip = $dir . '/site.zip';
$writer = ZipWriter::create($zip, 6);
for ($f = 0; $f < 200; ++$f) {
    $content = str_repeat("line of text $f\n", 2000) . random_bytes(100_000);
    $writer->addString("files/f$f.bin", $content, time());
}
$writer->close();
$zipSize = filesize($zip);
$in = fopen($zip, 'rb');
$out = fopen($zip . '.age', 'wb');
Age::encryptStream($in, $out, [$public]);
fclose($in);
fclose($out);
AgeDecryptStream::register();
$context = stream_context_create(['agedec' => ['identity' => $secret]]);
$t = microtime(true);
$plainResult = ZipReader::open($zip)->verify();
$plainTime = microtime(true) - $t;

// ZipReader opens by path; hand it the wrapper's URL through a context default
stream_context_set_default(['agedec' => ['identity' => $secret]]);
// does the stream equal the file? (size, head, tail, a few random places)
$h = fopen('agedec://' . $zip . '.age', 'rb', false, $context);
$original = fopen($zip, 'rb');
$same = (int) fstat($h)['size'] === $zipSize;
foreach ([0, 1, 65535, 65536, 65537, 1000003, $zipSize - 4096, $zipSize - 22, $zipSize - 1] as $at) {
    fseek($h, $at);
    fseek($original, $at);
    $same = $same && stream_get_contents($h, 10000) === stream_get_contents($original, 10000);
}
printf("   the stream equals the plain file (size %d vs %d, heads, tails, random places): %s
", fstat($h)['size'], $zipSize, $same ? 'yes' : 'NO');
fclose($h);
fclose($original);
$t = microtime(true);
$reader = ZipReader::open('agedec://' . $zip . '.age');
$entries = $reader->count();
$openTime = microtime(true) - $t;
$t = microtime(true);
$result = $reader->verify();
$encryptedTime = microtime(true) - $t;
printf("4. a ZIP of %.1f MB (%d entries) read through the decrypting stream: open %.3f s, verify %.2f s (the plain file: %.2f s); problems: %s\n", $zipSize / 1048576, $entries, $openTime, $encryptedTime, $plainTime, $result->problems === [] ? 'none' : implode('; ', $result->problems));
$entry = $reader->find('files/f150.bin');
$sink = '';
$t = microtime(true);
$reader->extract($entry, static function (string $chunk) use (&$sink): void {
    $sink .= $chunk;
});
printf("   one entry from the middle: %.4f s, %d bytes, equal to the original: %s\n", microtime(true) - $t, strlen($sink), md5($sink) === md5(ZipReaderContents($zip, 'files/f150.bin')) ? 'yes' : 'NO');

function ZipReaderContents(string $zip, string $name): string
{
    $reader = ZipReader::open($zip);
    $entry = $reader->find($name);
    $out = '';
    $reader->extract($entry, static function (string $chunk) use (&$out): void {
        $out .= $chunk;
    });

    return $out;
}

exec('rm -rf ' . escapeshellarg($dir));
