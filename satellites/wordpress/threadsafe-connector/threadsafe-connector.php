<?php
/**
 * Plugin Name:       ThreadSafe Connector
 * Description:       Lets a central ThreadSafe back up this site by itself: encrypted backups, started and fetched by the schedule of your own server.
 * Version:           0.0.0-dev
 * Requires at least: 6.0
 * Requires PHP:      8.4
 * Author:            ThreadSafe
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       threadsafe-connector
 *
 * A thin wrapper (docs/PHASE13.md 9.12): everything that backs up lies in the single file threadsafe-backup.php next to this one. This file is written
 * for old PHP versions on purpose: a host below PHP 8.4 gets a notice in the admin area, not a fatal error.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (version_compare(PHP_VERSION, '8.4.0', '<')) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p><strong>ThreadSafe Connector</strong> needs PHP 8.4 or newer; this site runs PHP ' . esc_html(PHP_VERSION)
            . '. The plugin does nothing until the host offers a newer PHP version.</p></div>';
    });

    return;
}

define('THREADSAFE_CONNECTOR_FILE', __FILE__);

require __DIR__ . '/includes/plugin.php';
require __DIR__ . '/includes/boot.php';
