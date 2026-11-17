<?php
/**
 * What the plugin registers with WordPress when it is loaded (PHP 8.4). The work is in plugin.php; nothing here loads the tool.
 */

declare(strict_types=1);

namespace ThreadSafeConnector;

if (!defined('ABSPATH')) {
    exit;
}

register_activation_hook(THREADSAFE_CONNECTOR_FILE, __NAMESPACE__ . '\\activate');
register_deactivation_hook(THREADSAFE_CONNECTOR_FILE, __NAMESPACE__ . '\\deactivate');

add_action('admin_menu', static function (): void {
    add_management_page('ThreadSafe Connector', 'ThreadSafe', 'manage_options', 'threadsafe-connector', __NAMESPACE__ . '\\render_page');
});

add_action('admin_notices', __NAMESPACE__ . '\\show_failure');
