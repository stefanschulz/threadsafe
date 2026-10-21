<?php

/**
 * Prepended to the unit tests by `composer test:bundle`: the tests then run against the bundled code (build/bundle.php, made by
 * tools/build-restore.php) instead of the files of src/, which proves that the bundle behaves like the original.
 */
declare(strict_types=1);

define('THREADSAFE_PROGRAM_DIR', dirname(__DIR__));
require dirname(__DIR__) . '/build/bundle.php';
