<?php
/**
 * Object cache drop-in of the test stack (docs/PHASE9.md 4.2). It behaves like a Redis drop-in that was set up for one host: it works for the site
 * it was made for and throws for every other host name, as a cache server that cannot be reached would. WP-CLI has no host name and is left alone.
 * Name of the site it serves: __SEEDED_HOST__.
 */
if (PHP_SAPI !== 'cli' && ($_SERVER['HTTP_HOST'] ?? '') !== '__SEEDED_HOST__') {
    throw new RuntimeException('The cache server of this drop-in cannot be reached from ' . ($_SERVER['HTTP_HOST'] ?? '?'));
}
