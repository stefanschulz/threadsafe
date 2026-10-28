<?php

/**
 * Builds the universal restore tool (docs/PHASE3.md 3.1) from the sources.
 *
 *   php tools/build-restore.php [output]        write the tool (default: dist/dev/threadsafe-restore.php, a development build of version 0.0.0-dev)
 *   php tools/build-restore.php --bundle=FILE   write only the bundled code (for `composer test:bundle`)
 *   php tools/build-restore.php --key           print a random key for threadsafe-restore-key.php
 *
 * The tool is the same for every backup and every website type. Keep a copy with your backups.
 *
 * This is the quick build for development and tests: it has no version number. The file to hand out is dist/release/threadsafe-restore.php, made by
 * `vendor/bin/phing` (tools/build-release.php) with the version of the release, listed in SHA256SUMS.
 */
declare(strict_types=1);

use ThreadSafe\Build\RestoreBuilder;

require dirname(__DIR__) . '/vendor/autoload.php';

$arguments = array_slice($argv, 1);

if (in_array($arguments[0] ?? '', ['-h', '--help'], true)) {
    echo "Usage:\n"
        . "  php tools/build-restore.php [output]        write the restore tool (default: dist/dev/threadsafe-restore.php, a development build, version 0.0.0-dev)\n"
        . "  php tools/build-restore.php --bundle=FILE   write only the bundled code\n"
        . "  php tools/build-restore.php --key           print a random key for threadsafe-restore-key.php\n\n"
        . "To hand out a threadsafe-restore.php use the one of a release: vendor/bin/phing, then dist/release/threadsafe-restore.php.\n";
    exit(0);
}
// an option that is none of ours is an error, not the name of the file to write
if (str_starts_with($arguments[0] ?? '', '-') && !str_starts_with($arguments[0], '--bundle=') && $arguments[0] !== '--key') {
    fwrite(STDERR, "error: unknown option \"{$arguments[0]}\" (try --help)\n");
    exit(1);
}
$builder = new RestoreBuilder(dirname(__DIR__));

if (($arguments[0] ?? '') === '--key') {
    $key = rtrim(strtr(base64_encode(random_bytes(18)), '+/', 'xZ'), '=');
    echo "Put this into a file named threadsafe-restore-key.php next to threadsafe-restore.php:\n\n<?php return '$key';\n\n";
    echo "Keep the key to yourself; the tool asks for it. Delete the file when the restore is done.\n";
    exit(0);
}

try {
    if (isset($arguments[0]) && str_starts_with($arguments[0], '--bundle=')) {
        $target = substr($arguments[0], strlen('--bundle='));
        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0777, true) && !is_dir(dirname($target))) {
            throw new RuntimeException('Cannot create the directory "' . dirname($target) . '".');
        }
        file_put_contents($target, $builder->bundle());
        printf("bundle: %d KB written to %s\n", strlen((string) file_get_contents($target)) / 1024, $target);
        exit(0);
    }
    $output = $arguments[0] ?? dirname(__DIR__) . '/dist/dev/threadsafe-restore.php';
    $result = $builder->build($output);
} catch (RuntimeException $e) {
    fwrite(STDERR, 'error: ' . $e->getMessage() . "\n");
    exit(1);
}

printf("threadsafe-restore.php: %d KB (code %d KB in %d files, %d data files) written to %s\n", $result['bytes'] / 1024, $result['bundleBytes'] / 1024, $result['files'], $result['resources'], $output);
if (!isset($arguments[0])) {
    echo "This is a development build (version 0.0.0-dev). To hand out a threadsafe-restore.php use the one of a release: vendor/bin/phing, then dist/release/threadsafe-restore.php.\n";
}
echo "Upload it together with a threadsafe-restore-key.php and the parts of a backup; php tools/build-restore.php --key makes a key.\n";
