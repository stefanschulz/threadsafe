<?php

/**
 * The test of a built release on a clean PHP (docs/PHASE7.md 3.7): nothing of the repository, no Composer, only the files that
 * `tools/build-release.php` wrote. The release workflow runs it before it publishes anything.
 *
 *   php tools/test-release.php <directory with the release files> <version>
 *
 * It checks that the checksums in SHA256SUMS are those of the files, that `unzip` opens the package and the installer, that the
 * unpacked package runs (`bin/threadsafe --version`, `doctor`), that `install.php` installs it through its own wizard into a new document
 * root (key, check, install, remove the installer, hand over to the setup), that the installed command and stub run, and that
 * `restore.php` carries the version. This file uses no class of ThreadSafe and needs no `vendor/`.
 */
declare(strict_types=1);

if ($argc < 3) {
    fwrite(STDERR, "usage: php tools/test-release.php <directory with the release files> <version>\n");
    exit(2);
}
$dir = rtrim(str_replace('\\', '/', $argv[1]), '/');
$version = ltrim($argv[2], 'v');
$failures = [];
$check = static function (string $what, bool $ok, string $detail = '') use (&$failures): void {
    echo ($ok ? 'ok      ' : 'FAILED  ') . $what . ($ok || $detail === '' ? '' : ' - ' . $detail) . "\n";
    if (!$ok) {
        $failures[] = $what;
    }
};
$run = static function (string $command, ?string $input = null): array {
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        return [127, '', 'cannot start'];
    }
    fwrite($pipes[0], $input ?? '');
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);

    return [proc_close($process), $out, $err];
};
$remove = static function (string $path) use (&$remove): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $remove($path . '/' . $entry);
            }
        }
        @rmdir($path);
    } else {
        @unlink($path);
    }
};
$php = escapeshellarg(PHP_BINARY);
$package = "threadsafe-$version.zip";

echo "PHP " . PHP_VERSION . ", release files in $dir, version $version\n\n";

// ---------------------------------------------------------------------------------------------------- the files and their checksums
foreach ([$package, 'install.php', 'restore.php', 'SHA256SUMS'] as $name) {
    $check("$name is there", is_file("$dir/$name"));
}
if ($failures !== []) {
    exit(1);
}
$listed = [];
foreach (file("$dir/SHA256SUMS", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    if (preg_match('/^([0-9a-f]{64})  (\S+)$/', $line, $m) === 1) {
        $listed[$m[2]] = $m[1];
    }
}
foreach ([$package, 'install.php', 'restore.php'] as $name) {
    $check("SHA256SUMS lists $name with its checksum", ($listed[$name] ?? '') === hash_file('sha256', "$dir/$name"));
}

// ---------------------------------------------------------------------------------------------------- unzip opens the package and the installer
$haveUnzip = $run('command -v unzip')[1] !== '';
if ($haveUnzip) {
    foreach ([$package, 'install.php'] as $name) {
        [$code, $out] = $run('unzip -tq ' . escapeshellarg("$dir/$name"));
        $check("unzip -t $name", $code === 0, trim($out));
    }
}

// ---------------------------------------------------------------------------------------------------- the unpacked package runs
$work = sys_get_temp_dir() . '/threadsafe-release-test-' . bin2hex(random_bytes(4));
mkdir($work . '/unpacked', 0777, true);
$unpack = $haveUnzip ? 'unzip -q ' . escapeshellarg("$dir/$package") . ' -d ' . escapeshellarg($work . '/unpacked') : '';
[$code] = $unpack !== '' ? $run($unpack) : [1, '', ''];
$tree = "$work/unpacked/threadsafe-$version";
$check('the package unpacks into threadsafe-' . $version . '/', $code === 0 && is_file("$tree/release.json"));
if (is_file("$tree/release.json")) {
    $manifest = json_decode((string) file_get_contents("$tree/release.json"), true);
    $check('release.json names the version', is_array($manifest) && ($manifest['version'] ?? null) === $version);
    $bad = [];
    foreach ((array) ($manifest['files'] ?? []) as $path => $file) {
        if (!is_file("$tree/$path") || hash_file('sha256', "$tree/$path") !== ($file['sha256'] ?? '')) {
            $bad[] = $path;
        }
    }
    $check('every file matches release.json', $bad === [] && count((array) ($manifest['files'] ?? [])) > 300, implode(', ', array_slice($bad, 0, 5)));
    foreach (['tests', 'tools', '.docker', '.github', 'composer.lock'] as $absent) {
        $check("the package has no $absent", !file_exists("$tree/$absent"));
    }
    [$code, $out, $err] = $run("$php $tree/bin/threadsafe --version");
    $check('bin/threadsafe --version says ' . $version, $code === 0 && trim($out) === "threadsafe $version", trim($out . $err));
    [$code, $out, $err] = $run("$php $tree/bin/threadsafe doctor --release=$tree/release.json");
    $check('doctor accepts this host for the release', $code === 0 && str_contains($out, 'meets its requirements'), trim($out . $err));
}

// ---------------------------------------------------------------------------------------------------- the installer installs it
$docroot = "$work/site/public_html";
mkdir($docroot, 0777, true);
copy("$dir/install.php", "$docroot/install.php");
[$code, $out] = $run($php . ' -r ' . escapeshellarg('$_SERVER["REQUEST_METHOD"] = "GET"; include $argv[1];') . ' ' . escapeshellarg("$docroot/install.php"));
$check('without install-key.php the installer only explains', str_contains($out, 'does nothing until you give it a key') && !is_dir("$docroot/.threadsafe-install"));
[$code, $out, $err] = $run("$php " . escapeshellarg("$docroot/install.php"));
$check('install.php in a shell says to use a browser or unzip', $code === 1 && str_contains($err, 'unzip install.php'), trim($err));
file_put_contents("$docroot/install-key.php", "<?php return 'a long secret key for the test';");
$run($php . ' -r ' . escapeshellarg('$_SERVER["REQUEST_METHOD"] = "GET"; $_SERVER["REMOTE_ADDR"] = "127.0.0.1"; $_SERVER["REQUEST_URI"] = "/install.php"; include $argv[1];') . ' ' . escapeshellarg("$docroot/install.php"));
$bundles = glob("$docroot/.threadsafe-install/bundle-*.php") ?: [];
$check('the installer unpacks its code next to itself', count($bundles) === 1);
if (count($bundles) === 1) {
    $flow = <<<'PHP'
require $argv[1];
$wizard = new ThreadSafe\Install\Wizard($argv[2]);
$request = static fn (string $method, array $query, array $post, ?string $token) => new ThreadSafe\Web\Request($method, $query, $post, $token === null ? [] : [ThreadSafe\Install\Wizard::COOKIE => $token], false, '127.0.0.1', '/install.php');
$out = [];
$login = $wizard->handle($request('POST', [], ['action' => 'login', 'key' => 'a long secret key for the test'], null));
preg_match('/threadsafe_install=([0-9a-f]{64})/', (string) $login->header('Set-Cookie'), $m);
$token = $m[1] ?? '';
$page = $wizard->handle($request('GET', ['step' => 'check'], [], $token));
preg_match('/name="csrf" value="([0-9a-f]+)"/', $page->body, $m);
$csrf = $m[1] ?? '';
$out['check'] = [$page->status, str_contains($page->body, 'value="install"')];
$install = $wizard->handle($request('POST', [], ['csrf' => $csrf, 'action' => 'install', 'place' => 'outside'], $token));
$out['install'] = $install->status;
$ticks = 0;
do {
    $answer = json_decode($wizard->handle($request('POST', [], ['csrf' => $csrf, 'action' => 'tick', 'format' => 'json'], $token))->body, true);
    $ticks++;
} while (($answer['status'] ?? '') === 'running' && $ticks < 300);
$out['ticks'] = $answer['status'] ?? '?';
$clean = $wizard->handle($request('POST', [], ['csrf' => $csrf, 'action' => 'cleanup'], $token));
preg_match('/name="key" value="([0-9a-f]+)"/', $clean->body, $m);
$out['key'] = strlen($m[1] ?? '');
$out['error'] = $answer['error'] ?? '';
echo json_encode($out);
PHP;
    [$code, $out, $err] = $run($php . ' -r ' . escapeshellarg($flow) . ' ' . escapeshellarg($bundles[0]) . ' ' . escapeshellarg("$docroot/install.php"));
    $result = json_decode($out, true);
    $check('the wizard checks, installs, and hands over', is_array($result) && ($result['check'] ?? null) === [200, true] && ($result['install'] ?? null) === 303 && ($result['ticks'] ?? null) === 'finished' && ($result['key'] ?? 0) === 24, trim($out . $err));
    $home = "$work/site/threadsafe";
    $check('the installer is gone, the stub is there', array_values(array_diff(scandir($docroot) ?: [], ['.', '..'])) === ['.htaccess', 'index.php']);
    $pointer = (string) @file_get_contents("$home/current.php");
    $check('the pointer names the version, and was written', str_contains($pointer, '"release":"' . $version . '"'));
    $check('the setup key is in the data directory', is_file("$home/data/setup-key.php") && is_file("$home/data/version.json"));
    [$code, $out, $err] = $run("$php $home/bin/threadsafe --version");
    $check('the installed command runs the installed release', $code === 0 && trim($out) === "threadsafe $version", trim($out . $err));
    [$code, $out, $err] = $run("$php $home/bin/threadsafe doctor");
    $check('doctor of the installed command finds the home', $code === 0 && str_contains($out, 'from a release; the home is'), trim($out . $err));
    [$code, $out, $err] = $run($php . ' -r ' . escapeshellarg('$_SERVER["REQUEST_METHOD"] = "GET"; $_SERVER["REMOTE_ADDR"] = "127.0.0.1"; $_SERVER["REQUEST_URI"] = "/"; $_SERVER["SCRIPT_NAME"] = "/index.php"; include $argv[1];') . ' ' . escapeshellarg("$docroot/index.php"));
    $check('the stub starts the interface (switched off: not found)', $code === 0 && $err === '' && stripos($out, 'fatal') === false, trim(substr($out . $err, 0, 200)));
}

// ---------------------------------------------------------------------------------------------------- restore.php
$head = (string) file_get_contents("$dir/restore.php", false, null, 0, 200);
$check('restore.php carries the version', str_contains($head, "ThreadSafe restore tool $version"), trim(strtok($head, "\n") . ' / ' . strtok("\n")));
[$code, $out, $err] = $run("$php $dir/restore.php restore");
$check('restore.php runs as the restore command in a shell', $code === 1 && str_contains($err . $out, 'archive'), trim(substr($out . $err, 0, 200)));

$remove($work);
echo "\n" . ($failures === [] ? "The release is in order.\n" : count($failures) . " check(s) failed.\n");
exit($failures === [] ? 0 : 1);
