<?php

/**
 * Builds the release (docs/PHASE7.md 3.1): threadsafe-X.Y.Z.zip, the web installer threadsafe-install.php (the same package behind a stub), the
 * single-file tools threadsafe-restore.php and threadsafe-backup.php (docs/PHASE12.md) and the file of checksums SHA256SUMS.
 *
 *   php tools/build-release.php [--version=X.Y.Z] [--output=DIR] [--keep] [--composer=PATH]
 *
 *   --version   the version to stamp (a leading "v" is dropped, so a tag can be passed as it is); default: the one in the source
 *   --output    where the files go (default: dist/release)
 *   --keep      keep the staging directory (it holds the tree the package was made from)
 *   --composer  the Composer command (default: composer): a name on the PATH, the path of a file, or a whole command line such as
 *               "php C:	ools\composer.phar"
 *
 * The time of every file is SOURCE_DATE_EPOCH, else the time of the last commit, else now: two builds of one commit give one checksum.
 * The build copies the program to a staging directory, stamps the version into the copy, runs `composer install --no-dev
 * --optimize-autoloader` there (the package needs no Composer on the host), writes the package and checks it against its own manifest.
 */
declare(strict_types=1);

use ThreadSafe\Archive\ZipReader;
use ThreadSafe\Build\BackupBuilder;
use ThreadSafe\Build\InstallBuilder;
use ThreadSafe\Build\ReleaseBuilder;
use ThreadSafe\Build\ReleaseManifest;
use ThreadSafe\Build\RestoreBuilder;
use ThreadSafe\Core\Version;

require dirname(__DIR__) . '/vendor/autoload.php';

$options = ['keep' => false];
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--keep') {
        $options['keep'] = true;
    } elseif (preg_match('/^--(version|output|composer)=(.+)$/', $argument, $m) === 1) {
        $options[$m[1]] = $m[2];
    } else {
        fwrite(STDERR, "error: unknown argument \"$argument\"\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$version = ltrim((string) ($options['version'] ?? Version::CURRENT), 'v');
if (!Version::isValid($version)) {
    fwrite(STDERR, "error: \"$version\" is not a valid version (X.Y.Z or X.Y.Z-suffix).\n");
    exit(1);
}
$output = $options['output'] ?? $root . '/dist/release';

$epoch = getenv('SOURCE_DATE_EPOCH');
if ($epoch === false || $epoch === '' || !ctype_digit($epoch)) {
    $commit = [];
    exec('git -C ' . escapeshellarg($root) . ' log -1 --format=%ct ' . (DIRECTORY_SEPARATOR === '\\' ? '2>NUL' : '2>/dev/null'), $commit, $code);
    $epoch = $code === 0 && isset($commit[0]) && ctype_digit($commit[0]) ? $commit[0] : (string) time();
}
$epoch = (int) $epoch;

$stage = sys_get_temp_dir() . '/threadsafe-release-' . bin2hex(random_bytes(4));
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

try {
    $builder = new ReleaseBuilder($root);
    $builder->stage($stage, $version);

    $composer = $options['composer'] ?? 'composer';
    // A file is quoted; a bare name or a command line is used as it is. cmd.exe runs a quoted "composer" (composer.bat) with the wrong %~dp0 and
    // looks for composer.phar in the current directory.
    $command = (is_file($composer) ? escapeshellarg($composer) : $composer) . ' install --no-dev --optimize-autoloader --no-interaction --no-progress --no-scripts --working-dir=' . escapeshellarg($stage) . ' 2>&1';
    $lines = [];
    exec($command, $lines, $code);
    if ($code !== 0) {
        throw new RuntimeException("Composer failed (exit $code):\n" . implode("\n", $lines));
    }

    $zipPath = rtrim($output, '/') . '/' . ReleaseBuilder::packageName($version);
    $manifest = (new ReleaseBuilder($stage))->write($zipPath, $version, $epoch);

    // the test of the built package against its own manifest
    $zip = ZipReader::open($zipPath);
    $top = ReleaseBuilder::topDirectory($version);
    $first = $zip->entries()->current();
    if ($first === null || $first->name !== $top . ReleaseManifest::FILE) {
        throw new RuntimeException('The manifest is not the first entry of the package.');
    }
    $problems = ReleaseManifest::fromJson($zip->contents($first))->problems($zip, $top);
    if ($problems !== []) {
        throw new RuntimeException("The package does not match its manifest:\n  " . implode("\n  ", $problems));
    }

    // the installer: built from the tree the package was made from, so that its code and the package's code are the same
    $installPath = rtrim($output, '/') . '/threadsafe-install.php';
    (new InstallBuilder($stage))->build($installPath, $zipPath, $version);
    $installed = ZipReader::open($installPath);
    $installFirst = $installed->entries()->current();
    if ($installFirst->name !== $top . ReleaseManifest::FILE || ReleaseManifest::fromJson($installed->contents($installFirst))->problems($installed, $top) !== []) {
        throw new RuntimeException('threadsafe-install.php does not hold the package it was built with.');
    }

    // the generic restore tool of this version, built from the same tree (its Version.php is the stamped one)
    $restorePath = rtrim($output, '/') . '/threadsafe-restore.php';
    (new RestoreBuilder($stage, $version))->build($restorePath);

    // and the backup tool of this version, from the same tree
    $backupPath = rtrim($output, '/') . '/threadsafe-backup.php';
    (new BackupBuilder($stage, $version))->build($backupPath);

    $sums = ReleaseBuilder::checksums([ReleaseBuilder::packageName($version) => $zipPath, 'threadsafe-install.php' => $installPath, 'threadsafe-restore.php' => $restorePath, 'threadsafe-backup.php' => $backupPath]);
    file_put_contents(rtrim($output, '/') . '/SHA256SUMS', $sums);
} catch (RuntimeException $e) {
    fwrite(STDERR, 'error: ' . $e->getMessage() . "\n");
    if (!$options['keep']) {
        $remove($stage);
    }
    exit(1);
}

if ($options['keep']) {
    echo "staging directory kept: $stage\n";
} else {
    $remove($stage);
}
@chmod($zipPath, 0644);
printf("%s: %d files, %.2f MB; threadsafe-install.php: %.2f MB; threadsafe-restore.php: %d KB; threadsafe-backup.php: %d KB (built %s)\n", ReleaseBuilder::packageName($version), count($manifest->files) + 1, filesize($zipPath) / 1048576, filesize($installPath) / 1048576, filesize($restorePath) / 1024, filesize($backupPath) / 1024, gmdate('Y-m-d H:i:s', $epoch) . ' UTC');
echo $sums;
