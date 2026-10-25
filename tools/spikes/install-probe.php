<?php
/**
 * SPIKE (phase 7, WP 1) - a probe of a web host for what the layout of docs/PHASE7.md 3.2 needs, for the owner to run on the
 * host of the field test (and here, in the Docker stack).
 *
 * It answers: can PHP write next to its document root (where "home" would go); does rename() work for directories and does it
 * replace a file atomically (the pointer); what do file modes do; which limits apply to an upload (upload_max_filesize,
 * post_max_size, max_file_uploads); is open_basedir set; how does opcache check files (a new path is always a new file, but
 * validate_timestamps=0 matters for a rewritten pointer); can a file be served from a directory outside the document root;
 * how long do 400 small files take to write, hash and read back (the cost of an extraction).
 *
 * Written for old PHP versions too (no short arrays, no namespaces): it must run on a host that may be too old for ThreadSafe.
 * It writes only below one directory it makes next to itself (and one next to the document root, which it removes) and prints
 * no secret.
 *
 *   command line:  php install-probe.php
 *   web:           put the file in the document root, set $WEB_TOKEN, open  install-probe.php?token=...  and DELETE THE FILE.
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

function say($label, $value)
{
    echo str_pad($label, 52) . $value . "\n";
}

function yes($flag)
{
    return $flag ? 'yes' : 'NO';
}

function rrmdir($dir)
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') {
            continue;
        }
        $p = $dir . '/' . $f;
        if (is_dir($p) && !is_link($p)) {
            rrmdir($p);
        } else {
            @unlink($p);
        }
    }
    @rmdir($dir);
}

echo "== php\n";
say('version / sapi', PHP_VERSION . ' / ' . PHP_SAPI);
say('os', PHP_OS);
say('open_basedir', ini_get('open_basedir') === '' || ini_get('open_basedir') === false ? '(none)' : ini_get('open_basedir'));
say('disable_functions', ini_get('disable_functions') === '' ? '(none)' : ini_get('disable_functions'));
say('max_execution_time / memory_limit', ini_get('max_execution_time') . ' s / ' . ini_get('memory_limit'));
say('file_uploads', yes(ini_get('file_uploads')));
say('upload_max_filesize / post_max_size', ini_get('upload_max_filesize') . ' / ' . ini_get('post_max_size'));
say('max_file_uploads', ini_get('max_file_uploads'));
say('upload_tmp_dir / sys_get_temp_dir', (ini_get('upload_tmp_dir') ?: '(default)') . ' / ' . sys_get_temp_dir());
say('opcache loaded / enabled', yes(extension_loaded('Zend OPcache')) . ' / ' . yes(ini_get('opcache.enable')));
say('opcache.validate_timestamps / revalidate_freq', ini_get('opcache.validate_timestamps') . ' / ' . ini_get('opcache.revalidate_freq'));
say('opcache.restrict_api', (string) ini_get('opcache.restrict_api'));
$extensions = array('zlib', 'mbstring', 'json', 'pdo_mysql', 'mysqli', 'sodium', 'zip', 'xml', 'gd', 'intl', 'curl');
$have = array();
foreach ($extensions as $e) {
    $have[] = $e . '=' . (extension_loaded($e) ? 'y' : 'n');
}
say('extensions', implode(' ', $have));

echo "\n== where home could go\n";
$here = __DIR__;
say('this directory (the document root?)', $here);
say('writable', yes(is_writable($here)));
$parent = dirname($here);
say('parent directory', $parent);
say('parent writable', yes(is_writable($parent)));
$home = $parent . '/threadsafe-probe-' . getmypid();
$made = @mkdir($home, 0700);
say('mkdir next to the document root (0700)', yes($made));
$inside = $here . '/threadsafe-probe-in-' . getmypid();
say('mkdir inside the document root (0700)', yes(@mkdir($inside, 0700)));
say('fileperms of that directory', is_dir($inside) ? substr(sprintf('%o', fileperms($inside)), -4) : '-');
say('umask', sprintf('%04o', umask()));

echo "\n== rename, and the pointer\n";
$base = $made ? $home : (is_dir($inside) ? $inside : sys_get_temp_dir());
say('directory used for the tests', $base);
@mkdir($base . '/releases');
@mkdir($base . '/releases/0.1.0');
file_put_contents($base . '/releases/0.1.0/marker.php', "<?php return '0.1.0';\n");
say('rename directory to a new name', yes(@rename($base . '/releases/0.1.0', $base . '/releases/0.1.0-x')));
say('rename it back', yes(@rename($base . '/releases/0.1.0-x', $base . '/releases/0.1.0')));
@mkdir($base . '/releases/0.2.0');
say('rename a directory over an empty one', yes(@rename($base . '/releases/0.1.0', $base . '/releases/0.2.0')));
@mkdir($base . '/releases/0.1.0');
$pointer = $base . '/current.php';
file_put_contents($pointer, "<?php return array('release' => '0.1.0');\n");
file_put_contents($pointer . '.new', "<?php return array('release' => '0.2.0');\n");
say('rename a file over an existing one (atomic)', yes(@rename($pointer . '.new', $pointer)));
$read = include $pointer;
say('the pointer now says', is_array($read) ? $read['release'] : '?');
chmod($pointer, 0444);
file_put_contents($pointer . '.new', "<?php return array('release' => '0.3.0');\n");
say('rename over a read-only (0444) file', yes(@rename($pointer . '.new', $pointer)));
chmod($pointer, 0444);
say('file_put_contents over a read-only file', yes(@file_put_contents($pointer, 'x') !== false));
say('chmod 0444 then 0644 again', yes(@chmod($pointer, 0444) && @chmod($pointer, 0644)));
say('symlink (not needed, only noted)', yes(@symlink($base . '/releases/0.1.0', $base . '/link')));
if (function_exists('opcache_invalidate')) {
    say('opcache_invalidate available', yes(@opcache_invalidate($pointer, true)));
}

echo "\n== a file outside the document root can be read and included by a script inside it\n";
say('include of a file next to the document root', $made ? yes((include $home . '/releases/0.2.0/marker.php') !== false) : 'not tested (no directory there)');
say('is_readable of the parent of the document root', yes(is_readable($parent)));

echo "\n== the cost of an extraction: 400 files of 5 KB\n";
$dir = $base . '/bulk';
@mkdir($dir);
$start = microtime(true);
$data = str_repeat('0123456789abcdef', 320);
for ($i = 0; $i < 400; $i++) {
    @mkdir($dir . '/d' . ($i % 20));
    file_put_contents($dir . '/d' . ($i % 20) . '/f' . $i . '.php', $data);
}
$written = microtime(true) - $start;
$start = microtime(true);
$sum = '';
for ($i = 0; $i < 400; $i++) {
    $sum = md5($sum . hash_file('sha256', $dir . '/d' . ($i % 20) . '/f' . $i . '.php'));
}
$hashed = microtime(true) - $start;
say('write 400 files', sprintf('%.3f s', $written));
say('hash them again', sprintf('%.3f s', $hashed));
$start = microtime(true);
rrmdir($dir);
say('remove them', sprintf('%.3f s', microtime(true) - $start));

echo "\n== a request that runs 20 seconds (the number to compare with max_execution_time) is not tried here\n";

rrmdir($home);
rrmdir($inside);
echo "\nremoved what the probe made: " . yes(!file_exists($home) && !file_exists($inside)) . "\n";
