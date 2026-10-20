<?php

/**
 * Run by ThreadSafe's Finalize step in a child process: boots PrestaShop and calls its own routines. A separate
 * process, because PrestaShop's code and ThreadSafe's must not share one PHP process.
 *
 * Argument 1: JSON {"root": "...", "host": "...", "regenerate": bool}
 * Output: one JSON line {"done": [...]} on success; an error message on stderr and exit code 1 otherwise.
 *
 * License: GPL-3.0-or-later
 */
declare(strict_types=1);

$fail = static function (string $message): never {
    fwrite(STDERR, $message . "\n");
    exit(1);
};

$options = json_decode($argv[1] ?? '', true);
if (!is_array($options) || !is_string($options['root'] ?? null) || !is_string($options['host'] ?? null)) {
    $fail('Invalid options.');
}
$root = $options['root'];
$config = $root . '/config/config.inc.php';
if (!is_file($config)) {
    $fail("$config not found.");
}

// As root, PrestaShop would create cache files only root can write and the web server would stop working: continue as
// the owner of the shop.
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    $owner = fileowner($root);
    $group = filegroup($root);
    if ($group !== false && $group !== 0) {
        posix_setgid($group);
    }
    if ($owner !== false && $owner !== 0) {
        posix_setuid($owner);
    }
}

$_SERVER['HTTP_HOST'] = $options['host'];
$_SERVER['SERVER_NAME'] = $options['host'];
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';

chdir($root);
ob_start();
try {
    require $config;

    $done = [];
    Tools::clearAllCache();
    $done[] = 'cache cleared';
    if (($options['regenerate'] ?? false) === true) {
        if (!Tools::generateHtaccess()) {
            throw new RuntimeException('Tools::generateHtaccess() failed.');
        }
        $done[] = '.htaccess regenerated';
        if (($options['robots'] ?? true) === true) {
            if (!Tools::generateRobotsFile()) {
                throw new RuntimeException('Tools::generateRobotsFile() failed.');
            }
            $done[] = 'robots.txt regenerated';
        }
    }
} catch (Throwable $e) {
    ob_end_clean();
    $fail(get_class($e) . ': ' . $e->getMessage());
}
ob_end_clean();

echo json_encode(['done' => $done]), "\n";
