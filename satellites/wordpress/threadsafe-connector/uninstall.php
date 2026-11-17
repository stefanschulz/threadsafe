<?php
/**
 * Runs when the plugin is deleted in the admin area (not when it is deactivated): removes the pointer, the pairing and the state; the archives stay on
 * the host unless the box "delete the archives with the plugin" was ticked.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$threadsafeArchives = get_option('threadsafe_connector_delete_archives') === '1';
delete_option('threadsafe_connector_delete_archives');

if (version_compare(PHP_VERSION, '8.4.0', '>=')) {
    require_once __DIR__ . '/includes/plugin.php';
    ThreadSafeConnector\uninstall($threadsafeArchives);
} else {
    @unlink(WP_CONTENT_DIR . '/threadsafe-agent.php');
}
