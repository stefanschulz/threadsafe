<?php
/**
 * SPIKE (phase 7, WP 1) - what it costs to serve the static files of the interface (app.css, app.js, the fonts) through a PHP stub
 * instead of letting the web server do it (docs/PHASE7.md 3.2, risk 3).
 *
 * It starts PHP's built-in web server twice on the files of `public/` - once plain (the server sends the files), once with a stub
 * as router that does what the real stub would do (read the pointer, whitelist, headers, ETag, 304) - and times N requests of
 * each with one curl process and keep-alive. The built-in server is single-threaded and is no Apache, so the numbers say what the
 * PHP part costs, not what a host does.
 *
 *   php tools/spikes/install-assets.php [N]
 */

declare(strict_types=1);

$n = (int) ($argv[1] ?? 500);
$root = dirname(__DIR__, 2);
$work = sys_get_temp_dir() . '/threadsafe-spike-assets-' . getmypid();
mkdir($work . '/home/releases/0.1.0/public', 0777, true);
mkdir($work . '/docroot', 0777, true);
exec('cp -r ' . escapeshellarg($root . '/public') . '/. ' . escapeshellarg($work . '/home/releases/0.1.0/public') . '/');
file_put_contents($work . '/home/current.php', "<?php return ['release' => '0.1.0', 'path' => '" . $work . "/home/releases/0.1.0'];\n");

file_put_contents($work . '/docroot/index.php', <<<'STUB'
<?php
$pointer = require __DIR__ . '/../home/current.php';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('#^/(app\.css|app\.js|fonts/[A-Za-z0-9._-]+)$#', $path, $m)) {
    $file = $pointer['path'] . '/public/' . $m[1];
    if (is_file($file)) {
        $etag = '"' . $pointer['release'] . '-' . filesize($file) . '"';
        header('ETag: ' . $etag);
        header('Cache-Control: public, max-age=31536000, immutable');
        $types = ['css' => 'text/css', 'js' => 'text/javascript', 'woff2' => 'font/woff2', 'woff' => 'font/woff', 'ttf' => 'font/ttf'];
        header('Content-Type: ' . ($types[pathinfo($file, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
            http_response_code(304);
            exit;
        }
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }
}
echo "page\n";
STUB);

// plain: the same files in the document root
exec('cp -r ' . escapeshellarg($root . '/public') . '/. ' . escapeshellarg($work . '/plain/') . ' 2>/dev/null || (mkdir ' . escapeshellarg($work . '/plain') . ' && cp -r ' . escapeshellarg($root . '/public') . '/. ' . escapeshellarg($work . '/plain/') . ')');

$sizes = [];
foreach (['app.css', 'app.js'] as $f) {
    $sizes[$f] = filesize($root . '/public/' . $f);
}
$fonts = glob($root . '/public/fonts/*') ?: [];
printf("files: app.css %d B, app.js %d B, %d font files, %d B in all\n", $sizes['app.css'], $sizes['app.js'], count($fonts), array_sum(array_map('filesize', $fonts)));

function run(string $name, string $dir, ?string $router, int $port, int $n, string $url): void
{
    $command = 'php -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($dir) . ($router !== null ? ' ' . escapeshellarg($router) : '') . ' >/dev/null 2>&1 & echo $!';
    $pid = (int) shell_exec($command);
    usleep(500000);
    $urls = implode(' ', array_fill(0, $n, escapeshellarg('http://127.0.0.1:' . $port . $url)));
    $start = microtime(true);
    exec('curl -s -o /dev/null ' . $urls);
    $elapsed = microtime(true) - $start;
    exec('kill ' . $pid);
    printf("%-34s %4d requests in %.3f s = %.2f ms each, %.0f/s\n", $name, $n, $elapsed, $elapsed / $n * 1000, $n / $elapsed);
}

foreach (['/app.css', '/app.js'] as $i => $url) {
    run('built-in server, file ' . $url, $work . '/plain', null, 18080 + $i, $n, $url);
    run('built-in server, stub ' . $url, $work . '/docroot', $work . '/docroot/index.php', 18090 + $i, $n, $url);
}

// the memory a request through the stub needs
$out = [];
exec('php -r ' . escapeshellarg('$_SERVER["REQUEST_URI"]="/app.css"; $_SERVER["HTTP_IF_NONE_MATCH"]=""; ob_start(); require "' . $work . '/docroot/index.php"; ob_end_clean(); echo memory_get_peak_usage(), "\n";') . ' 2>&1', $out);
echo 'peak memory of one request through the stub: ' . ((int) end($out)) . " bytes\n";
exec('rm -rf ' . escapeshellarg($work));
