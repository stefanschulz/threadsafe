<?php

/**
 * Builds the backup tool (docs/PHASE12.md 3.1) from the sources.
 *
 *   php tools/build-backup.php [output]   write the tool (default: dist/dev/threadsafe-backup.php, a development build of version 0.0.0-dev)
 *   php tools/build-backup.php --key      print a random key for threadsafe-backup-key.php
 *
 * The tool has the program of the restore tool in it and a stub of its own. It is the quick build for development and tests; the file to hand out will be the
 * one of a release (work package 7 of phase 12).
 */
declare(strict_types=1);

use ThreadSafe\Build\BackupBuilder;

require dirname(__DIR__) . '/vendor/autoload.php';

$arguments = array_slice($argv, 1);

if (in_array($arguments[0] ?? '', ['-h', '--help'], true)) {
    echo "Usage:\n"
        . "  php tools/build-backup.php [output]   write the backup tool (default: dist/dev/threadsafe-backup.php, a development build, version 0.0.0-dev)\n"
        . "  php tools/build-backup.php --key      print a random key for threadsafe-backup-key.php\n";
    exit(0);
}
if (($arguments[0] ?? '') === '--key') {
    $key = rtrim(strtr(base64_encode(random_bytes(18)), '+/', 'xZ'), '=');
    echo "Put this into a file named threadsafe-backup-key.php next to threadsafe-backup.php:\n\n<?php return '$key';\n\n";
    exit(0);
}
// an option that is none of ours is an error, not the name of the file to write
if (str_starts_with($arguments[0] ?? '', '-')) {
    fwrite(STDERR, "error: unknown option \"{$arguments[0]}\" (try --help)\n");
    exit(1);
}

$output = $arguments[0] ?? dirname(__DIR__) . '/dist/dev/threadsafe-backup.php';
$result = (new BackupBuilder(dirname(__DIR__)))->build($output);
echo sprintf("Wrote %s: %s KB (%d source files, %d plugin files)\n", $output, number_format($result['bytes'] / 1024, 1), $result['files'], $result['resources']);
