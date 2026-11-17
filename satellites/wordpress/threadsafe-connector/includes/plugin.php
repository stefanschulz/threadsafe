<?php
/**
 * The work of the plugin ThreadSafe Connector (PHP 8.4; docs/PHASE13.md 9.12): switching it on and off, the page under Tools, and the removal. The plugin
 * carries no logic of the backup. It loads the single file threadsafe-backup.php as a library (the constant THREADSAFE_LIBRARY: the file unpacks itself
 * as usual, loads its classes and returns instead of running its wizard) and hands everything to ThreadSafe\Web\AgentPlugin, AgentPage and AgentSetup,
 * the classes that the wizard of the tool and its shell command use as well. This file only defines functions: it is also loaded by uninstall.php.
 */

declare(strict_types=1);

namespace ThreadSafeConnector;

use ThreadSafe\Web\AgentPage;
use ThreadSafe\Web\AgentPlugin;
use ThreadSafe\Web\AgentSetup;
use ThreadSafe\Web\BackupWorkspace;
use ThreadSafe\Web\Request;

if (!defined('ABSPATH')) {
    exit;
}

const TOOL = 'threadsafe-backup.php';

const NONCE = 'threadsafe-connector';

const FAILURE = 'threadsafe_connector_failure';

/** The directory of the plugin. */
function directory(): string
{
    return dirname(__DIR__);
}

/**
 * Loads the tool as a library, once. The tool unpacks itself next to its file, so the directory of the plugin must be writable for the web server.
 *
 * @throws \RuntimeException the tool is not there or cannot unpack itself
 */
function tool(): void
{
    if (class_exists(AgentPlugin::class, false)) {
        return;
    }
    $file = directory() . '/' . TOOL;
    if (!is_file($file)) {
        throw new \RuntimeException('The file ' . TOOL . ' is missing from the plugin directory. Install the plugin again from its ZIP.');
    }
    if (!is_dir(directory() . '/.threadsafe-backup') && !is_writable(directory())) {
        throw new \RuntimeException('The plugin directory ' . directory() . ' is not writable for the web server: the tool unpacks itself into a hidden directory there, once.');
    }
    (static function () use ($file): void {
        define('THREADSAFE_LIBRARY', true);
        require_once $file;   // the stub returns to here after it has loaded the classes
    })();
    if (!class_exists(AgentPlugin::class, false)) {
        throw new \RuntimeException('The tool ' . TOOL . ' did not load its program.');
    }
}

function agent_plugin(): AgentPlugin
{
    tool();

    return new AgentPlugin(untrailingslashit(ABSPATH), WP_CONTENT_DIR, directory(), null, TOOL);
}

/** Why the layout of this site does not suit the plugin, or null: the entry file finds its pointer by the position of the plugin directory (agent.php). */
function layout_problem(): ?string
{
    $plugins = realpath(WP_PLUGIN_DIR);
    $mine = realpath(directory());
    $content = realpath(WP_CONTENT_DIR);
    if ($plugins === false || $mine === false || $content === false || dirname($mine) !== $plugins || dirname($plugins) !== $content) {
        return 'This site keeps its plugins outside wp-content/plugins (or wp-content elsewhere than next to it). The agent finds its settings by the place of the plugin directory and cannot work here.';
    }

    return null;
}

/** @return array{basedir: string, baseurl: string} */
function uploads(): array
{
    $uploads = wp_upload_dir(null, false);
    if (!empty($uploads['error'])) {
        throw new \RuntimeException('WordPress has no uploads directory: ' . $uploads['error']);
    }

    return ['basedir' => (string) $uploads['basedir'], 'baseurl' => (string) $uploads['baseurl']];
}

/** What went wrong in an activation is shown on the next page of the admin area: a plugin must not end the request with an error then. */
function remember_failure(?string $message): void
{
    if ($message === null) {
        delete_option(FAILURE);

        return;
    }
    update_option(FAILURE, $message, false);
}

function show_failure(): void
{
    $message = get_option(FAILURE);
    if (is_string($message) && $message !== '' && current_user_can('manage_options')) {
        echo '<div class="notice notice-error"><p><strong>ThreadSafe Connector:</strong> ' . esc_html($message) . '</p></div>';
    }
}

function activate(): void
{
    try {
        if (($problem = layout_problem()) !== null) {
            throw new \RuntimeException($problem);
        }
        agent_plugin()->activate(uploads()['basedir']);
        remember_failure(null);
    } catch (\Throwable $e) {
        remember_failure($e->getMessage());
    }
}

function deactivate(): void
{
    try {
        agent_plugin()->deactivate();
    } catch (\Throwable $e) {
        // the entry file answers nobody if it cannot read the pointer; a pointer that cannot be changed is reported on the page
        remember_failure($e->getMessage());
    }
}

/** Called by uninstall.php: the pointer, the state and, if asked, the archives. */
function uninstall(bool $archives): void
{
    try {
        agent_plugin()->uninstall($archives);
    } catch (\Throwable) {
        @unlink(WP_CONTENT_DIR . '/' . AgentPlugin::POINTER_FILE);
    }
}

// ------------------------------------------------------------------------------------------------- the page

function size(float|int|null $bytes): string
{
    if ($bytes === null) {
        return 'unknown';
    }
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $value = (float) $bytes;
    while ($value >= 1024 && $i < count($units) - 1) {
        $value /= 1024;
        ++$i;
    }

    return ($i === 0 ? (string) (int) $value : number_format($value, 1)) . ' ' . $units[$i];
}

/** The fields of a posted form, as plain strings (WordPress adds slashes to what it receives). */
function posted(): array
{
    $post = [];
    foreach (wp_unslash($_POST) as $name => $value) {   // phpcs:ignore WordPress.Security.NonceVerification -- checked by the caller
        if (is_string($name) && is_string($value)) {
            $post[$name] = $value;
        }
    }

    return $post;
}

function render_page(): void
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html('You are not allowed to open this page.'));
    }
    $notice = '';
    $error = '';
    $agent = ['notice' => '', 'error' => '', 'pairing' => ''];
    $touched = '';   // the section a form was just posted in stays open
    try {
        $plugin = agent_plugin();
        $post = posted();
        $action = $post['action'] ?? '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== '') {
            if (!wp_verify_nonce($post['csrf'] ?? '', NONCE)) {
                $error = 'The form has expired. Try again.';
            } elseif ($action === 'connector-setup') {
                if (($problem = layout_problem()) !== null) {
                    throw new \RuntimeException($problem);
                }
                $plugin->activate(uploads()['basedir']);
                remember_failure(null);
                $notice = 'Set up.';
            } elseif ($action === 'connector-leave-out' && ($touched = 'settings') !== '' && ($workspace = $plugin->workspace()) !== null) {
                try {
                    $count = $plugin->leaveOutOtherBackups($workspace);
                    $notice = $count === 0 ? 'Nothing to add.' : 'Saved: ' . $count . ' folder(s) of other backup plugins are left out now.';
                } catch (\InvalidArgumentException | \ThreadSafe\Profile\ProfileException | \RuntimeException $e) {
                    $error = $e->getMessage();
                }
            } elseif ($action === 'connector-save' && ($touched = 'settings') !== '' && ($workspace = $plugin->workspace()) !== null) {
                try {
                    $plugin->save($workspace, $post['recipients'] ?? '', $post['exclude'] ?? '', $post['part_size'] ?? '');
                    update_option('threadsafe_connector_delete_archives', ($post['delete_archives'] ?? '') === '1' ? '1' : '0', false);
                    $notice = 'Saved. The agent makes its backups the way you set them up here.';
                } catch (\InvalidArgumentException | \ThreadSafe\Profile\ProfileException | \RuntimeException $e) {
                    $error = $e->getMessage();
                }
            } elseif (str_starts_with($action, 'agent-') && $plugin->workspace() !== null) {
                $agent = (new AgentPage($plugin->workspace()))->handle($action, new Request('POST', [], $post), '');
            }
        }
        $workspace = $plugin->workspace();
        $body = $workspace === null ? setup_body($plugin) : main_body($plugin, $workspace, $agent, $touched);
    } catch (\Throwable $e) {
        $body = '<p class="tsc-fail">' . esc_html($e->getMessage()) . '</p>';
    }

    echo '<div class="wrap tsc"><h1>ThreadSafe Connector</h1>' . styles();
    if ($error !== '') {
        echo '<p class="tsc-fail" role="alert">' . esc_html($error) . '</p>';
    }
    if ($notice !== '') {
        echo '<p class="tsc-ok" role="status">' . esc_html($notice) . '</p>';
    }
    echo $body;   // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts
    echo '</div>';
}

function form(string $inner): string
{
    return '<form method="post"><input type="hidden" name="csrf" value="' . esc_attr(wp_create_nonce(NONCE)) . '">' . $inner . '</form>';
}

function setup_body(AgentPlugin $plugin): string
{
    return '<div class="tsc-box"><p>The plugin is not set up on this site yet (or its settings were removed). Setting up makes a directory for the backups in the uploads folder, closed to the web, '
        . 'and the file <code>wp-content/threadsafe-agent.php</code> that tells the plugin where it is.</p>' . form('<p><button name="action" value="connector-setup">Set up</button></p>') . '</div>';
}

/**
 * @param array{notice: string, error: string, pairing: string} $agent what a posted form of the agent did
 */
/**
 * A section that folds: the one that is next (or that a form was just posted in) is open, the ones that are done are closed and say in one line what they hold.
 */
function section(string $title, string $summary, bool $open, string $inner): string
{
    return '<details class="tsc-section"' . ($open ? ' open' : '') . '><summary><span class="tsc-title">' . esc_html($title) . '</span>'
        . ($summary === '' ? '' : '<span class="tsc-sum">' . esc_html($summary) . '</span>') . '</summary><div class="tsc-inner">' . $inner . '</div></details>';
}

/**
 * @param array{notice: string, error: string, pairing: string} $agent   what a posted form of the agent did
 * @param string                                                $touched 'settings' if the form of the settings was just posted
 */
function main_body(AgentPlugin $plugin, BackupWorkspace $workspace, array $agent, string $touched = ''): string
{
    $state = $plugin->state($workspace);
    $settings = $plugin->settings($workspace);
    $csrf = wp_create_nonce(NONCE);
    $e = static fn (string $text): string => esc_html($text);
    $config = (new AgentSetup($workspace))->config();
    $done = $settings['recipients'] !== [] && $config->isPaired() && $config->isOn();

    $html = '<p class="tsc-lead">Lets a central ThreadSafe back this site up by itself. The backups are <strong>encrypted</strong> on this site for your public key; this site never holds the secret key.</p>'
        . ($done ? '' : '<ol class="tsc-steps"><li>Paste your <strong>public key</strong> below and press <strong>Save</strong>.</li>'
        . '<li>In the section <strong>The agent</strong>, press <strong>Pair</strong>. You get a <strong>pairing string</strong> once: copy it.</li>'
        . '<li>Give the string to your central ThreadSafe (<code>threadsafe profile add ... --pairing-file=FILE</code>).</li>'
        . '<li>Come back here and press <strong>Switch it on</strong>. Until then the agent answers nobody.</li></ol>');

    $html .= section('State', $state['backups'] . ' backup(s) on this site', true, '<div class="tsc-box"><dl class="tsc-state">'
        . '<dt>Backups on this site</dt><dd>' . $state['backups'] . ($state['newest'] !== null ? ', the newest from ' . $e(gmdate('Y-m-d H:i', $state['newest'])) . ' UTC' : '') . '</dd>'
        . '<dt>Free space</dt><dd>' . $e(size($state['free'])) . ' <span class="tsc-help">(of the volume, not of the quota of your account)</span></dd>'
        . '<dt>Directory of the backups</dt><dd><code>' . $e($state['directory']) . '</code></dd>'
        . '<dt>Server time</dt><dd>' . $e(gmdate('Y-m-d H:i:s')) . ' UTC <span class="tsc-help">(the central ThreadSafe tells you how far its clock is from this one)</span></dd>'
        . '<dt>PHP</dt><dd>' . $e(PHP_VERSION) . ', <code>max_execution_time</code> ' . $e((string) ini_get('max_execution_time')) . ' s, <code>memory_limit</code> ' . $e((string) ini_get('memory_limit')) . '</dd>'
        . '</dl>' . probe_block($plugin, $workspace) . '</div>');

    $others = $plugin->otherBackups($workspace);
    $inner = '';
    if ($others !== []) {
        $list = '';
        foreach ($others as $pattern) {
            $list .= '<li><code>' . $e($pattern) . '</code></li>';
        }
        $inner .= '<div class="tsc-box"><p><strong>Archives of other backup plugins found on this site.</strong> They are backups already and can be many gigabytes: '
            . 'a backup of them every night makes yours large and slow. Nothing is left out unless you say so:</p><ul class="tsc-steps">' . $list . '</ul>'
            . ($settings['recipients'] === []
                ? '<p class="tsc-help">Save the settings below with a public key first; then you can leave these out with one click.</p>'
                : form('<p><button name="action" value="connector-leave-out">Leave these out</button></p>'))
            . '</div>';
    }
    $inner .= form(
        '<label for="tsc-recipients">Public keys</label><textarea id="tsc-recipients" name="recipients" rows="3" spellcheck="false">' . $e(implode("\n", $settings['recipients'])) . '</textarea>'
        . '<span class="tsc-help">One per line, like <code>age1...</code> (58 characters after age1). Make a key pair with ThreadSafe (<code>threadsafe keys generate</code>, or the page Keys of its web interface) and paste only the public half here.</span>'
        . '<label for="tsc-exclude">Leave out besides the files of this plugin</label><textarea id="tsc-exclude" name="exclude" rows="4" spellcheck="false">' . $e(implode("\n", $settings['exclude'])) . '</textarea>'
        . '<span class="tsc-help">One pattern per line, from the root of the site, like <code>/wp-content/backups/</code> (the archives of other backup plugins are large: leave them out) or <code>*.log</code>.</span>'
        . '<label for="tsc-part">Size of a part</label><input id="tsc-part" type="text" name="part_size" value="' . $e($settings['partSize'] === null ? '' : (string) (int) ($settings['partSize'] / 1048576) . 'M') . '" placeholder="500M">'
        . '<span class="tsc-help">The archive is cut into parts of about this size, so that a transfer that fails is repeated for one part only. A file is never cut: a part may be larger when one file is.</span>'
        . '<label class="tsc-check"><input type="checkbox" name="delete_archives" value="1"' . (get_option('threadsafe_connector_delete_archives') === '1' ? ' checked' : '') . '> Delete the backups on this site when the plugin is deleted</label>'
        . '<span class="tsc-help">Not ticked: deleting the plugin removes the pairing and the settings and leaves the backup files where they are.</span>'
        . '<p><button name="action" value="connector-save">Save</button></p>',
    );

    $keys = count($settings['recipients']);
    $summary = $keys === 0 ? 'Nothing saved yet' : $keys . ' public key(s), ' . count($settings['exclude']) . ' pattern(s) left out, part size ' . ($settings['partSize'] === null ? 'default' : (int) ($settings['partSize'] / 1048576) . ' MB');
    $html .= section('What to back up', $summary . ($others !== [] ? ', other backup plugins found' : ''), $keys === 0 || $others !== [] || $touched === 'settings', $inner);

    $agentSummary = !$config->isPaired() ? 'Not paired yet' : 'Paired with the profile ' . $config->profile() . ', the agent is ' . ($config->isOn() ? 'on' : 'off');
    $agentOpen = !$config->isPaired() || !$config->isOn() || $agent['pairing'] !== '' || $agent['notice'] !== '' || $agent['error'] !== '';
    $html .= section('The agent', $agentSummary, $agentOpen, (new AgentPage($workspace))->body($csrf, plugins_url('agent.php', THREADSAFE_CONNECTOR_FILE), $agent, true));

    return $html;
}

/** The test whether the web server hands out what is in the directory of the backups, made by the browser of the person (the server cannot ask itself). */
function probe_block(AgentPlugin $plugin, BackupWorkspace $workspace): string
{
    $uploads = uploads();
    $probe = $plugin->probe($workspace, $uploads['basedir'], $uploads['baseurl']);
    if ($probe === null) {
        return '<p class="tsc-help">The directory of the backups lies outside the uploads folder. Make sure that it is not inside the document root of the site.</p>';
    }
    $urls = wp_json_encode($probe['urls'], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?: '[]';
    $token = wp_json_encode($probe['token']) ?: '""';

    return '<p><strong>Can the web server hand out the backups?</strong> <span id="tsc-probe" role="status">The page is asking the web server ...</span></p>'
        . '<script>(function () {var out = document.getElementById("tsc-probe"), token = ' . $token . ', urls = ' . $urls . ';'
        . 'Promise.all(urls.map(function (u) {return fetch(u, {credentials: "omit", cache: "no-store"}).then(function (r) {return r.ok ? r.text() : "";})'
        . '.then(function (t) {return t.indexOf(token) >= 0;}).catch(function () {return false;});})).then(function (found) {'
        . 'if (found.indexOf(true) >= 0) {out.textContent = "Yes: a test file can be fetched. Ask the host to close this directory, or choose a directory outside the document root."; out.className = "tsc-fail";}'
        . 'else {out.textContent = "No: the web server does not hand the test files out."; out.className = "tsc-ok";}});})();</script>';
}

function styles(): string
{
    return '<style>'
        . '.tsc{--text:#14191f;--soft:#434e58;--panel:#fff;--panel2:#f6f7f8;--recess:#e5e8eb;--border:#cdd3d9;--strong:#7f8a95;--brass:#8a5a08;--fill:#e0a13d;--edge:#a8740f;--ok:#1a7339;--error:#b3382c;max-width:56rem;color:var(--text)}'
        . '.tsc h2{margin:1.6rem 0 .6rem;font-size:1.15rem}.tsc h3{margin:1.4rem 0 .4rem}.tsc-steps{margin:.2rem 0 .6rem 1.2rem;color:var(--soft)}.tsc-steps li{margin:.25rem 0}.tsc-section{background:var(--panel);border:1px solid var(--border);border-radius:6px;margin:.8rem 0}.tsc-section>summary{cursor:pointer;padding:.7rem 1rem;display:flex;flex-wrap:wrap;gap:.2rem 1rem;align-items:baseline;list-style:none}.tsc-section>summary::-webkit-details-marker{display:none}.tsc-section>summary::before{content:"\25B8";color:var(--soft)}.tsc-section[open]>summary::before{content:"\25BE"}.tsc-section>summary:focus-visible{outline:2px solid var(--brass);outline-offset:2px}.tsc-title{font-size:1.1rem;font-weight:600}.tsc-sum{color:var(--soft);font-size:.9rem}.tsc-inner{padding:0 1rem 1rem;border-top:1px solid var(--border)}.tsc-inner .tsc-box{margin-top:.8rem}.tsc-inner h3:first-child{margin-top:1rem}.tsc-lead{color:var(--soft);font-size:1rem}'
        . '.tsc-box,.tsc .box{background:var(--panel);border:1px solid var(--border);border-radius:6px;padding:.9rem 1.1rem;margin:.6rem 0}'
        . '.tsc-state{display:grid;grid-template-columns:max-content 1fr;gap:.35rem 1.2rem;margin:0}.tsc-state dt{color:var(--soft)}.tsc-state dd{margin:0;overflow-wrap:anywhere}'
        . '.tsc-help,.tsc .help{display:block;color:var(--soft);font-size:.9rem;margin:.2rem 0 .8rem}'
        . '.tsc label{display:block;font-weight:600;margin:.8rem 0 .25rem}.tsc .tsc-check{font-weight:400}'
        . '.tsc textarea,.tsc input[type=text]{width:100%;max-width:100%;background:var(--panel);color:var(--text);border:1px solid var(--strong);border-radius:4px;padding:.45rem .6rem;font-family:ui-monospace,Consolas,monospace;font-size:.9rem}'
        . '.tsc button,.tsc a.button{font:600 .85rem/1.4 inherit;padding:.5rem 1rem;border:1px solid var(--edge);border-radius:4px;background:var(--fill);color:#14191f;cursor:pointer;text-decoration:none;display:inline-block;margin:.3rem .3rem .3rem 0}'
        . '.tsc button.secondary,.tsc a.button.secondary{background:var(--panel2);border-color:var(--strong);color:var(--text)}'
        . '.tsc button:focus-visible,.tsc textarea:focus-visible,.tsc input:focus-visible{outline:2px solid var(--brass);outline-offset:2px}'
        . '.tsc code{background:var(--recess);padding:.05rem .3rem;border-radius:3px;overflow-wrap:anywhere}.tsc pre{background:var(--recess);padding:.7rem;border-radius:4px;overflow-x:auto;white-space:pre-wrap;word-break:break-all}'
        . '.tsc-ok,.tsc .ok{color:var(--ok);font-weight:600}.tsc-fail,.tsc .fail{color:var(--error);font-weight:600}'
        . '@media (max-width:600px){.tsc-state{grid-template-columns:1fr}.tsc-state dt{margin-top:.5rem}}'
        . '</style>';
}
