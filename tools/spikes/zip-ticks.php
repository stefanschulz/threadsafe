<?php

/**
 * SPIKE (phase 2, WP 1a) - throw-away script, not part of the program.
 *
 * Question: can a ZIP archive be written over several PHP processes ("ticks") with a single entry spanning ticks,
 * with CRC32 and SHA-256 state carried in serialised hash contexts, and does the result pass `unzip -t` -
 * including the ZIP64 record layout - and is it byte-identical however the ticks are cut?
 *
 *   php tools/spikes/zip-ticks.php run <workdir> <chunks-per-tick> [zip64]
 *
 * Needs `unzip` in the PATH. Deterministic: fixed timestamps, fixed chunk size.
 */
declare(strict_types=1);

const CHUNK = 262144;
const DOS_TIME = 0;
const DOS_DATE = (46 << 9) | (1 << 5) | 1; // 2026-01-01

function u16(int $v): string { return pack('v', $v); }
function u32(int $v): string { return pack('V', $v); }
function u64(int $v): string { return pack('P', $v); }

/** @return array<string, mixed> */
function loadState(string $file): array
{
    $state = json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);

    return $state;
}

function saveState(string $file, array $state): void
{
    file_put_contents($file . '.tmp', json_encode($state, JSON_THROW_ON_ERROR));
    rename($file . '.tmp', $file);
}

function localHeader(string $name, bool $zip64): string
{
    $extra = $zip64 ? u16(0x0001) . u16(16) . u64(0) . u64(0) : '';

    return u32(0x04034b50) . u16($zip64 ? 45 : 20) . u16(0x0808) . u16(8) . u16(DOS_TIME) . u16(DOS_DATE)
        . u32(0) . u32($zip64 ? 0xFFFFFFFF : 0) . u32($zip64 ? 0xFFFFFFFF : 0) . u16(strlen($name)) . u16(strlen($extra)) . $name . $extra;
}

function dataDescriptor(int $crc, int $compressed, int $size, bool $zip64): string
{
    return u32(0x08074b50) . u32($crc) . ($zip64 ? u64($compressed) . u64($size) : u32($compressed) . u32($size));
}

function centralRecord(string $name, int $crc, int $compressed, int $size, int $offset, bool $zip64): string
{
    $extra = $zip64 ? u16(0x0001) . u16(24) . u64($size) . u64($compressed) . u64($offset) : '';

    return u32(0x02014b50) . u16((3 << 8) | 45) . u16($zip64 ? 45 : 20) . u16(0x0808) . u16(8) . u16(DOS_TIME) . u16(DOS_DATE)
        . u32($crc) . u32($zip64 ? 0xFFFFFFFF : $compressed) . u32($zip64 ? 0xFFFFFFFF : $size)
        . u16(strlen($name)) . u16(strlen($extra)) . u16(0) . u16(0) . u16(0) . u32((0100644 << 16)) . u32($zip64 ? 0xFFFFFFFF : $offset)
        . $name . $extra;
}

function endRecords(int $entries, int $cdSize, int $cdOffset, bool $zip64): string
{
    $out = '';
    if ($zip64) {
        $out .= u32(0x06064b50) . u64(44) . u16((3 << 8) | 45) . u16(45) . u32(0) . u32(0) . u64($entries) . u64($entries) . u64($cdSize) . u64($cdOffset);
        $out .= u32(0x07064b50) . u32(0) . u64($cdOffset + $cdSize) . u32(1);
    }

    return $out . u32(0x06054b50) . u16(0) . u16(0) . u16($zip64 ? 0xFFFF : $entries) . u16($zip64 ? 0xFFFF : $entries)
        . u32($zip64 ? 0xFFFFFFFF : $cdSize) . u32($zip64 ? 0xFFFFFFFF : $cdOffset) . u16(0);
}

/** One tick: continue the archive for at most $maxChunks chunks; returns true when the archive is complete. */
function tick(string $stateFile, int $maxChunks): bool
{
    $s = loadState($stateFile);
    if ($s['done']) {
        return true;
    }
    $zip64 = $s['zip64'];

    $fh = fopen($s['archive'], 'c+b');
    ftruncate($fh, $s['offset']);          // forget anything a dead process wrote after the last saved state
    fseek($fh, 0, SEEK_END);
    $sha = unserialize(base64_decode($s['sha']));
    $central = fopen($s['central'], 'c+b');
    ftruncate($central, $s['centralSize']);
    fseek($central, 0, SEEK_END);

    $write = function (string $data) use ($fh, $sha, &$s): void {
        fwrite($fh, $data);
        hash_update($sha, $data);
        $s['offset'] += strlen($data);
    };

    $chunks = 0;
    while ($chunks < $maxChunks) {
        if ($s['current'] === null) {
            if ($s['next'] >= count($s['entries'])) {
                // all entries done: central directory, end records
                $cd = (string) stream_get_contents(fopen($s['central'], 'rb'));
                $cdOffset = $s['offset'];
                $write($cd);
                $write(endRecords(count($s['entries']), strlen($cd), $cdOffset, $zip64));
                $s['sha'] = hash_final($sha);
                $s['done'] = true;
                break;
            }
            $entry = $s['entries'][$s['next']];
            $s['current'] = ['name' => $entry['name'], 'source' => $entry['source'], 'pos' => 0, 'crc' => base64_encode(serialize(hash_init('crc32b'))),
                'compressed' => 0, 'size' => 0, 'headerOffset' => $s['offset']];
            $write(localHeader($entry['name'], $zip64));
        }

        $c = &$s['current'];
        $src = fopen($c['source'], 'rb');
        fseek($src, $c['pos']);
        $data = (string) fread($src, CHUNK);
        fclose($src);
        $last = strlen($data) < CHUNK;                    // a full chunk is followed by another (maybe empty) one
        $crc = unserialize(base64_decode($c['crc']));
        hash_update($crc, $data);
        $deflate = deflate_init(ZLIB_ENCODING_RAW, ['level' => 6]);
        $packed = deflate_add($deflate, $data, $last ? ZLIB_FINISH : ZLIB_FULL_FLUSH);
        $write($packed);
        $c['crc'] = base64_encode(serialize($crc));
        $c['pos'] += strlen($data);
        $c['size'] += strlen($data);
        $c['compressed'] += strlen($packed);
        ++$chunks;

        if ($last) {
            $crcValue = hexdec(hash_final($crc));
            $write(dataDescriptor($crcValue, $c['compressed'], $c['size'], $zip64));
            fwrite($central, centralRecord($c['name'], $crcValue, $c['compressed'], $c['size'], $c['headerOffset'], $zip64));
            $s['centralSize'] = (int) ftell($central);
            unset($c);
            $s['current'] = null;
            ++$s['next'];
        }
        unset($c);
    }

    fflush($fh);
    fflush($central);
    $s['centralSize'] = (int) ftell($central);
    if (!$s['done']) {
        $s['sha'] = base64_encode(serialize($sha));
    }
    saveState($stateFile, $s);

    return $s['done'];
}

if (($argv[1] ?? '') === 'tick') {
    exit(tick($argv[2], (int) $argv[3]) ? 0 : 10);
}

if (($argv[1] ?? '') !== 'run') {
    fwrite(STDERR, "usage: zip-ticks.php run <workdir> <chunks-per-tick> [zip64]\n");
    exit(1);
}

[$work, $perTick, $zip64] = [rtrim($argv[2], '/'), (int) $argv[3], ($argv[4] ?? '') === 'zip64'];
@mkdir($work, 0777, true);

// ---- sources (deterministic) ------------------------------------------------------------------------------
mt_srand(42);
$big = '';
for ($i = 0; $i < 40; ++$i) {
    $big .= str_repeat("line $i of text that compresses well\n", 4000);
    $big .= pack('N*', ...array_map(static fn (): int => mt_rand(), range(1, 20000)));
}
file_put_contents($work . '/big.bin', $big);
file_put_contents($work . '/exact.bin', str_repeat('x', CHUNK * 2));  // an exact multiple of the chunk size
file_put_contents($work . '/one.txt', 'y');
file_put_contents($work . '/empty.txt', '');
$entries = [
    ['name' => 'files/big.bin', 'source' => $work . '/big.bin'],
    ['name' => 'files/exact.bin', 'source' => $work . '/exact.bin'],
    ['name' => 'files/empty.txt', 'source' => $work . '/empty.txt'],
    ['name' => 'files/ümläut ✓ & space.txt', 'source' => $work . '/one.txt'],
    ['name' => 'db/0001_product.sql', 'source' => $work . '/one.txt'],
];

$archive = $work . "/out-$perTick" . ($zip64 ? '-z64' : '') . '.zip';
@unlink($archive);
$stateFile = $work . '/state.json';
file_put_contents($work . '/central.bin', '');
saveState($stateFile, ['archive' => $archive, 'central' => $work . '/central.bin', 'centralSize' => 0, 'offset' => 0, 'zip64' => $zip64, 'done' => false,
    'sha' => base64_encode(serialize(hash_init('sha256'))), 'entries' => $entries, 'next' => 0, 'current' => null]);

$ticks = 0;
do {
    $process = proc_open([PHP_BINARY, __FILE__, 'tick', $stateFile, (string) $perTick], [], $pipes);
    $code = proc_close($process);
    ++$ticks;
    if ($code !== 0 && $code !== 10) {
        fwrite(STDERR, "tick failed with $code\n");
        exit(2);
    }
} while ($code === 10);

$final = loadState($stateFile);
echo sprintf("%-22s ticks=%-4d size=%-9d sha256 incremental=%s\n", basename($archive), $ticks, filesize($archive), substr($final['sha'], 0, 16));
echo '  sha256 of the file       ', $final['sha'] === hash_file('sha256', $archive) ? 'equal' : 'DIFFERENT', "\n";
