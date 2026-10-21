<?php
/**
 * SPIKE (phase 3, WP 1f) - a read-only probe of a web host, for the owner to run where backups will be restored.
 *
 * It answers: which PHP version and limits, which functions are disabled (exec, proc_open ...), whether files can be created
 * and how fast, whether a request may run long enough, and - from the command line, with a password file - what the database
 * server is. It writes only a temporary directory next to itself and removes it. It prints no secret.
 *
 * This file is written for old PHP versions too (no short arrays, no namespaces, no ::class): it must run on the host that
 * may be too old for ThreadSafe.
 *
 *   command line:  php host-probe.php [--db-host=H --db-name=N --db-user=U --db-password-file=FILE] [--busy=SECONDS]
 *   web:           put the file in the document root, set $WEB_TOKEN below, open  host-probe.php?token=...  [&busy=SECONDS]
 *                  and DELETE THE FILE afterwards.
 */

$WEB_TOKEN = '';   // web mode refuses to run while this is empty

$cli = (PHP_SAPI === 'cli');
if (!$cli) {
    header('Content-Type: text/plain; charset=utf-8');
    if ($WEB_TOKEN === '' || !isset($_GET['token']) || $_GET['token'] !== $WEB_TOKEN) {
        header('HTTP/1.1 403 Forbidden');
        echo "Set \$WEB_TOKEN in this file and call it with ?token=...\n";
        exit;
    }
}

function probe_line($label, $value)
{
    echo str_pad($label, 34) . $value . "\n";
    if (PHP_SAPI !== 'cli') {
        flush();
    }
}

function probe_ini($name)
{
    $v = @ini_get($name);

    return $v === false || $v === '' ? '(not set)' : $v;
}

function probe_function($name)
{
    if (!function_exists($name)) {
        return 'missing';
    }
    $disabled = array_map('trim', explode(',', (string) @ini_get('disable_functions')));

    return in_array($name, $disabled) ? 'DISABLED' : 'available';
}

function probe_remove($path)
{
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                probe_remove($path . '/' . $entry);
            }
        }
        @rmdir($path);
    } else {
        @unlink($path);
    }
}

echo "ThreadSafe host probe - read-only, writes a temporary directory next to this file and removes it\n";
echo str_repeat('=', 70) . "\n";

echo "\nPHP\n";
probe_line('version', PHP_VERSION . ' (' . PHP_SAPI . ', ' . PHP_OS . ')');
probe_line('meets ThreadSafe (8.4+)', version_compare(PHP_VERSION, '8.4.0', '>=') ? 'yes' : 'NO - ThreadSafe and restore.php need PHP 8.4');
foreach (array('memory_limit', 'max_execution_time', 'max_input_time', 'post_max_size', 'upload_max_filesize', 'open_basedir', 'allow_url_fopen', 'pcre.jit', 'pcre.backtrack_limit', 'zlib.output_compression', 'output_buffering', 'default_charset', 'date.timezone') as $name) {
    probe_line($name, probe_ini($name));
}
probe_line('disable_functions', probe_ini('disable_functions'));
probe_line('opcache', function_exists('opcache_get_status') ? 'available' : 'not loaded');
probe_line('https', (!$cli && (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')) ? 'yes' : ($cli ? 'n/a (command line)' : 'no - a key typed into a form would cross the network in clear text'));

echo "\nExtensions\n";
foreach (array('zlib', 'hash', 'json', 'mbstring', 'pdo_mysql', 'mysqli', 'tokenizer', 'ctype', 'openssl', 'curl', 'zip', 'fileinfo', 'posix', 'iconv') as $extension) {
    probe_line($extension, extension_loaded($extension) ? 'loaded' : 'not loaded');
}

echo "\nFunctions\n";
foreach (array('exec', 'proc_open', 'shell_exec', 'passthru', 'popen', 'system', 'set_time_limit', 'ini_set', 'symlink', 'chmod', 'touch', 'fsync', 'inflate_init', 'gzinflate', 'password_hash', 'random_bytes', 'mail') as $function) {
    probe_line($function, probe_function($function));
}

echo "\nFile system (directory of this file)\n";
$base = dirname(__FILE__);
probe_line('directory', is_writable($base) ? 'writable' : 'NOT WRITABLE');
$free = @disk_free_space($base);
probe_line('free space', $free === false ? 'unknown' : round($free / 1048576) . ' MB');
$dir = $base . '/.threadsafe-probe-' . substr(md5(uniqid('', true)), 0, 8);
if (@mkdir($dir, 0700)) {
    probe_line('mkdir 0700', sprintf('ok (mode %04o)', fileperms($dir) & 0777));
    $t = microtime(true);
    for ($i = 0; $i < 2000; $i++) {
        file_put_contents($dir . '/f' . $i . '.php', '<?php // ' . $i . str_repeat('x', 200));
    }
    $seconds = microtime(true) - $t;
    probe_line('create 2000 small files', sprintf('%.2f s (%d files/s; 70,000 files take about %d s)', $seconds, 2000 / max($seconds, 0.001), 70000 / (2000 / max($seconds, 0.001))));
    $t = microtime(true);
    $handle = fopen($dir . '/big.bin', 'wb');
    $chunk = str_repeat('0123456789abcdef', 65536);   // 1 MB
    for ($i = 0; $i < 50; $i++) {
        fwrite($handle, $chunk);
    }
    fclose($handle);
    $seconds = microtime(true) - $t;
    probe_line('write 50 MB', sprintf('%.2f s (%.0f MB/s)', $seconds, 50 / max($seconds, 0.001)));
    $t = microtime(true);
    $handle = fopen($dir . '/big.bin', 'rb');
    while (!feof($handle)) {
        fread($handle, 1048576);
    }
    fclose($handle);
    $seconds = microtime(true) - $t;
    probe_line('read 50 MB', sprintf('%.2f s (%.0f MB/s)', $seconds, 50 / max($seconds, 0.001)));
    if (function_exists('gzdeflate') && function_exists('gzinflate')) {
        $text = '';
        for ($i = 0; $i < 20000; $i++) {
            $text .= 'insert into product values (' . $i . ", 'name " . ($i * 7) . "', " . ($i / 3) . ");\n";
        }
        $text = str_repeat($text, 10);
        $packed = gzdeflate($text, 6);
        $t = microtime(true);
        for ($i = 0; $i < 5; $i++) {
            gzinflate($packed);
        }
        $seconds = microtime(true) - $t;
        probe_line('inflate speed', sprintf('%.0f MB/s', 5 * strlen($text) / 1048576 / max($seconds, 0.001)));
    }
    @chmod($dir . '/f1.php', 0600);
    probe_line('chmod 0600', sprintf('mode %04o', fileperms($dir . '/f1.php') & 0777));
    @touch($dir . '/f1.php', 1700000000);
    clearstatcache();
    probe_line('touch (set mtime)', filemtime($dir . '/f1.php') === 1700000000 ? 'ok' : 'ignored');
    $linked = function_exists('symlink') && @symlink($dir . '/f1.php', $dir . '/link');
    probe_line('symlink', $linked ? 'ok' : 'not possible');
    $self = @unlink($dir . '/f2.php');
    probe_line('delete a file', $self ? 'ok' : 'FAILED');
    $code = '<?php return "included";';
    file_put_contents($dir . '/inc.php', $code);
    probe_line('include a file written now', @include $dir . '/inc.php');
    probe_remove($dir);
    probe_line('cleanup', is_dir($dir) ? 'FAILED - remove ' . $dir . ' by hand' : 'ok');
} else {
    probe_line('mkdir', 'FAILED - the restore tool could not create its state directory here');
}

echo "\nProcesses\n";
if ($cli || probe_function('exec') === 'available') {
    $out = array();
    $code = -1;
    if (probe_function('exec') === 'available') {
        @exec('echo ok 2>&1', $out, $code);
    }
    probe_line('exec("echo")', $code === 0 ? 'works' : 'does not work');
}
$binary = defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : '(unknown)';
probe_line('PHP binary', $binary);
probe_line('php in PATH', trim((string) @shell_exec('command -v php 2>/dev/null')) !== '' ? trim((string) @shell_exec('command -v php 2>/dev/null')) : 'not found / shell_exec unavailable');

$busy = 0;
if ($cli) {
    foreach ($argv as $argument) {
        if (strpos($argument, '--busy=') === 0) {
            $busy = (int) substr($argument, 7);
        }
    }
} elseif (isset($_GET['busy'])) {
    $busy = (int) $_GET['busy'];
}
if ($busy > 0) {
    echo "\nRequest time ($busy s): a line every 5 s - note where the output stops\n";
    @set_time_limit($busy + 30);
    $start = time();
    while (time() - $start < $busy) {
        sleep(5);
        probe_line('  running for', (time() - $start) . ' s');
    }
    echo "  done: the request may run $busy s\n";
} else {
    echo "\n(add ?busy=60 or --busy=60 to find out how long a request may run before something cuts it)\n";
}

$host = $name = $user = $file = null;
if ($cli) {
    foreach ($argv as $argument) {
        if (strpos($argument, '--db-host=') === 0) {
            $host = substr($argument, 10);
        } elseif (strpos($argument, '--db-name=') === 0) {
            $name = substr($argument, 10);
        } elseif (strpos($argument, '--db-user=') === 0) {
            $user = substr($argument, 10);
        } elseif (strpos($argument, '--db-password-file=') === 0) {
            $file = substr($argument, 19);
        }
    }
}
if ($host !== null && $name !== null && $user !== null && $file !== null) {
    echo "\nDatabase\n";
    $password = is_file($file) ? rtrim((string) file_get_contents($file), "\r\n") : '';
    try {
        if (extension_loaded('pdo_mysql')) {
            $pdo = new PDO('mysql:host=' . $host . ';dbname=' . $name . ';charset=utf8mb4', $user, $password, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
            $one = function ($sql) use ($pdo) {
                $row = $pdo->query($sql)->fetch(PDO::FETCH_NUM);

                return $row ? $row[0] : null;
            };
            probe_line('interface', 'pdo_mysql');
        } elseif (extension_loaded('mysqli')) {
            $link = new mysqli($host, $user, $password, $name);
            $one = function ($sql) use ($link) {
                $row = $link->query($sql)->fetch_row();

                return $row ? $row[0] : null;
            };
            probe_line('interface', 'mysqli');
        } else {
            throw new Exception('neither pdo_mysql nor mysqli is loaded');
        }
        probe_line('server', $one('SELECT VERSION()'));
        probe_line('max_allowed_packet', round($one('SELECT @@max_allowed_packet') / 1048576) . ' MB');
        probe_line('sql_mode', $one('SELECT @@sql_mode') ?: '(empty)');
        probe_line('character_set_database', $one('SELECT @@character_set_database') . ' / ' . $one('SELECT @@collation_database'));
        probe_line('lower_case_table_names', $one('SELECT @@lower_case_table_names'));
        probe_line('tables in the database', $one('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'));
        probe_line('consistent snapshot', 'not tested here');
    } catch (Exception $e) {
        probe_line('connection', 'FAILED: ' . preg_replace('/password[^,]*/i', 'password ***', $e->getMessage()));
    }
} elseif ($cli) {
    echo "\n(add --db-host=H --db-name=N --db-user=U --db-password-file=FILE to test the database)\n";
}

echo "\nDelete this file when you are done.\n";
