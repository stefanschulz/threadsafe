<?php
/**
 * The door of the agent (docs/PHASE13.md 9.2, 9.12): the address that the central ThreadSafe asks. It does not load WordPress. It reads the pointer file
 * in wp-content, which names the workspace and says whether the plugin is switched on, defines THREADSAFE_WORKSPACE and hands over to the tool, which
 * checks the signature of the request itself. Everything else, a request for the page of the wizard included, is answered like a file that is not
 * there. Written for old PHP versions, so that the answer comes from any PHP.
 */

function threadsafe_connector_not_found($why = '')
{
    if ($why !== '') {
        error_log('ThreadSafe Connector: ' . $why);
    }
    if (!headers_sent()) {
        header('HTTP/1.1 404 Not Found');
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo 'Not found.';
    exit;
}

if (version_compare(PHP_VERSION, '8.4.0', '<') || !isset($_GET['api']) || !is_string($_GET['api'])) {
    threadsafe_connector_not_found();
}

// plugins/threadsafe-connector/agent.php -> wp-content: an update of the plugin replaces this directory and leaves the pointer where it is
$threadsafePointer = dirname(dirname(dirname(__FILE__))) . '/threadsafe-agent.php';
$threadsafePrefix = "<?php http_response_code(404); exit; ?>\n";
$threadsafeText = is_file($threadsafePointer) && !is_link($threadsafePointer) ? @file_get_contents($threadsafePointer) : false;
if (!is_string($threadsafeText) || strpos($threadsafeText, $threadsafePrefix) !== 0) {
    threadsafe_connector_not_found();   // not switched on: the plugin is not active, or was never set up
}
if (DIRECTORY_SEPARATOR === '/' && ((int) @fileperms($threadsafePointer) & 0007) !== 0) {
    threadsafe_connector_not_found('the pointer file wp-content/threadsafe-agent.php can be read by others and is refused (mode 0600 is expected)');
}
$threadsafeData = json_decode(substr($threadsafeText, strlen($threadsafePrefix)), true);
if (!is_array($threadsafeData) || !isset($threadsafeData['workspace']) || !is_string($threadsafeData['workspace'])) {
    threadsafe_connector_not_found('the pointer file wp-content/threadsafe-agent.php is damaged');
}
if (!isset($threadsafeData['on']) || $threadsafeData['on'] !== true) {
    threadsafe_connector_not_found();   // the plugin is deactivated
}

// the tool checks the path as it checks every path (no stream wrapper, absolute, no "..", not inside this directory) and refuses a bad one
define('THREADSAFE_WORKSPACE', $threadsafeData['workspace']);
unset($threadsafePointer, $threadsafePrefix, $threadsafeText, $threadsafeData);

require dirname(__FILE__) . '/threadsafe-backup.php';
