<?php
/**
 * The page of the module in the back office (PHP 8.4; docs/PHASE15.md 3.4), the counterpart of the page of the WordPress plugin: the state, what to back up
 * (the public keys, what is left out, the size of a part), and the agent (pair, switch on and off, who may ask). The module carries no logic of the
 * backup: it hands everything to ThreadSafe\Web\AgentPlugin, AgentPage and AgentSetup, which the wizard of the tool and its shell command use as well.
 * This file is loaded only when the module has checked PHP and its place, so that a shop on an old PHP gets a message and not a parse error.
 */

declare(strict_types=1);

namespace ThreadSafeConnectorShop;

use ThreadSafe\Web\AgentPage;
use ThreadSafe\Web\AgentPlugin;
use ThreadSafe\Web\AgentSetup;
use ThreadSafe\Web\BackupWorkspace;
use ThreadSafe\Web\Request;

if (!defined('_PS_VERSION_')) {
    exit;
}

const CSRF_TOKEN = 'AdminModules';

const DELETE_ARCHIVES = 'THREADSAFECONNECTOR_DELETE_ARCHIVES';

function h(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

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

/** The fields of a posted form, as plain strings. */
function posted(): array
{
    $post = [];
    foreach ($_POST as $name => $value) {   // phpcs:ignore -- the token is checked by the caller
        if (is_string($name) && is_string($value)) {
            $post[$name] = $value;
        }
    }

    return $post;
}

/**
 * The token of the forms of this page. PrestaShop 9 hands out a new random token for each request (a masked Symfony token), which cannot be compared with
 * one from an earlier request; the token here is a keyed hash of the logged-in employee and their password hash, the same on every request of one login and
 * unknown to anybody else. (The address of the page carries PrestaShop's own `_token` as well: a form posts back to it.)
 */
function token(): string
{
    $employee = \Context::getContext()->employee;
    if (!is_object($employee) || (int) $employee->id <= 0) {
        return '';
    }

    return hash_hmac('sha256', CSRF_TOKEN . '|' . (int) $employee->id . '|' . (string) $employee->passwd, (string) _COOKIE_KEY_);
}

function form(string $inner): string
{
    return '<form method="post" action=""><input type="hidden" name="csrf" value="' . h(token()) . '">' . $inner . '</form>';
}

/** A section that folds: the one that is next (or that a form was just posted in) is open, the ones that are done say in one line what they hold. */
function section(string $title, string $summary, bool $open, string $inner): string
{
    return '<details class="tsc-section"' . ($open ? ' open' : '') . '><summary><span class="tsc-title">' . h($title) . '</span>'
        . ($summary === '' ? '' : '<span class="tsc-sum">' . h($summary) . '</span>') . '</summary><div class="tsc-inner">' . $inner . '</div></details>';
}

/** The address of the door as the central ThreadSafe will reach it: the shop's own address (https when the shop has it) and the module's path. */
function door_address(): string
{
    return rtrim((string) \Tools::getShopDomainSsl(true), '/') . rtrim((string) __PS_BASE_URI__, '/') . '/modules/threadsafeconnector/' . AgentPlugin::ENTRY_FILE;
}

/**
 * Handles what a person posted and returns the page.
 *
 * @param bool     $archives      whether the box "delete the archives when the module is uninstalled" is ticked, as stored
 * @param callable $storeArchives called with the new value of that box when the settings are saved
 */
function render(AgentPlugin $plugin, bool $archives, callable $storeArchives): string
{
    $notice = '';
    $error = '';
    $agent = ['notice' => '', 'error' => '', 'pairing' => ''];
    $touched = '';
    try {
        $post = posted();
        $action = $post['action'] ?? '';
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $action !== '') {
            if (token() === '' || !hash_equals(token(), $post['csrf'] ?? '')) {
                $error = 'The form has expired. Try again.';
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
                    $archives = ($post['delete_archives'] ?? '') === '1';
                    $storeArchives($archives);
                    $notice = 'Saved. The agent makes its backups the way you set them up here.';
                } catch (\InvalidArgumentException | \ThreadSafe\Profile\ProfileException | \RuntimeException $e) {
                    $error = $e->getMessage();
                }
            } elseif (str_starts_with($action, 'agent-') && $plugin->workspace() !== null) {
                $agent = (new AgentPage($plugin->workspace()))->handle($action, new Request('POST', [], $post), '');
            }
        }
        $workspace = $plugin->workspace();
        $body = $workspace === null
            ? '<div class="tsc-box"><p>The module is not set up on this shop yet (or its settings were removed). Disable and enable it again in the module list to set it up.</p></div>'
            : main_body($plugin, $workspace, $agent, $touched, $archives);
    } catch (\Throwable $e) {
        $body = '<p class="tsc-fail">' . h($e->getMessage()) . '</p>';
    }

    return '<div class="tsc">' . styles() . '<h2>ThreadSafe Connector</h2>'
        . ($error !== '' ? '<p class="tsc-fail" role="alert">' . h($error) . '</p>' : '')
        . ($notice !== '' ? '<p class="tsc-ok" role="status">' . h($notice) . '</p>' : '')
        . $body . '</div>';
}

/**
 * @param array{notice: string, error: string, pairing: string} $agent   what a posted form of the agent did
 * @param string                                                $touched 'settings' if the form of the settings was just posted
 */
function main_body(AgentPlugin $plugin, BackupWorkspace $workspace, array $agent, string $touched, bool $archives): string
{
    $state = $plugin->state($workspace);
    $settings = $plugin->settings($workspace);
    $config = (new AgentSetup($workspace))->config();
    $done = $settings['recipients'] !== [] && $config->isPaired() && $config->isOn();

    $html = '<p class="tsc-lead">Lets a central ThreadSafe back this shop up by itself. The backups are <strong>encrypted</strong> on this shop for your public key; this shop never holds the secret key.</p>'
        . ($done ? '' : '<ol class="tsc-steps"><li>Paste your <strong>public key</strong> below and press <strong>Save</strong>.</li>'
        . '<li>In the section <strong>The agent</strong>, press <strong>Pair</strong>. You get a <strong>pairing string</strong> once: copy it.</li>'
        . '<li>Give the string to your central ThreadSafe (<code>threadsafe profile add ... --pairing-file=FILE</code>).</li>'
        . '<li>Come back here and press <strong>Switch it on</strong>. Until then the agent answers nobody.</li></ol>');

    $html .= section('State', $state['backups'] . ' backup(s) on this shop', true, '<div class="tsc-box"><dl class="tsc-state">'
        . '<dt>Backups on this shop</dt><dd>' . $state['backups'] . ($state['newest'] !== null ? ', the newest from ' . h(gmdate('Y-m-d H:i', $state['newest'])) . ' UTC' : '') . '</dd>'
        . '<dt>Free space</dt><dd>' . h(size($state['free'])) . ' <span class="tsc-help">(of the volume, not of the quota of your account)</span></dd>'
        . '<dt>Directory of the backups</dt><dd><code>' . h($state['directory']) . '</code></dd>'
        . '<dt>Server time</dt><dd>' . h(gmdate('Y-m-d H:i:s')) . ' UTC <span class="tsc-help">(the central ThreadSafe tells you how far its clock is from this one)</span></dd>'
        . '<dt>PHP</dt><dd>' . h(PHP_VERSION) . ', <code>max_execution_time</code> ' . h((string) ini_get('max_execution_time')) . ' s, <code>memory_limit</code> ' . h((string) ini_get('memory_limit')) . '</dd>'
        . '</dl>' . door_block() . '</div>');

    $inner = form(
        '<label for="tsc-recipients">Public keys</label><textarea id="tsc-recipients" name="recipients" rows="3" spellcheck="false">' . h(implode("\n", $settings['recipients'])) . '</textarea>'
        . '<span class="tsc-help">One per line, like <code>age1...</code> (58 characters after age1). Make a key pair with ThreadSafe (<code>threadsafe keys generate</code>, or the page Keys of its web interface) and paste only the public half here.</span>'
        . '<label for="tsc-exclude">Leave out besides the files of this module</label><textarea id="tsc-exclude" name="exclude" rows="4" spellcheck="false">' . h(implode("\n", $settings['exclude'])) . '</textarea>'
        . '<span class="tsc-help">One pattern per line, from the root of the shop, like <code>/var/logs/</code> or <code>*.log</code>. The cache of PrestaShop and the folder of its own backups are left out by the website type already.</span>'
        . '<label for="tsc-part">Size of a part</label><input id="tsc-part" type="text" name="part_size" value="' . h($settings['partSize'] === null ? '' : (string) (int) ($settings['partSize'] / 1048576) . 'M') . '" placeholder="500M">'
        . '<span class="tsc-help">The archive is cut into parts of about this size, so that a transfer that fails is repeated for one part only. A file is never cut: a part may be larger when one file is.</span>'
        . '<label class="tsc-check"><input type="checkbox" name="delete_archives" value="1"' . ($archives ? ' checked' : '') . '> Delete the backups on this shop when the module is uninstalled</label>'
        . '<span class="tsc-help">Not ticked: uninstalling removes the pairing and the settings and leaves the backup files where they are.</span>'
        . '<p><button name="action" value="connector-save">Save</button></p>',
    );
    $keys = count($settings['recipients']);
    $summary = $keys === 0 ? 'Nothing saved yet' : $keys . ' public key(s), ' . count($settings['exclude']) . ' pattern(s) left out, part size ' . ($settings['partSize'] === null ? 'default' : (int) ($settings['partSize'] / 1048576) . ' MB');
    $html .= section('What to back up', $summary, $keys === 0 || $touched === 'settings', $inner);

    $agentSummary = !$config->isPaired() ? 'Not paired yet' : 'Paired with the profile ' . $config->profile() . ', the agent is ' . ($config->isOn() ? 'on' : 'off');
    $agentOpen = !$config->isPaired() || !$config->isOn() || $agent['pairing'] !== '' || $agent['notice'] !== '' || $agent['error'] !== '';
    $html .= section('The agent', $agentSummary, $agentOpen, (new AgentPage($workspace))->body(token(), door_address(), $agent, true));

    return $html;
}

/**
 * The test whether the door of the module can be reached from outside, made by the browser of the person (the server cannot ask itself). PrestaShop's
 * own .htaccess denies PHP files in modules/; the module's .htaccess opens the one door. A host that ignores .htaccess answers 403 here, and the page says
 * what to do (docs/PHASE15.md 3.2).
 */
function door_block(): string
{
    $url = json_encode(door_address(), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?: '""';

    return '<p><strong>Can the central ThreadSafe reach the door of the agent?</strong> <span id="tsc-door" role="status">The page is asking the web server ...</span></p>'
        . '<script>(function () {var out = document.getElementById("tsc-door");'
        . 'function say(text, cls) {out.textContent = text; out.className = cls;}'
        . 'fetch(' . $url . ', {credentials: "omit", cache: "no-store"}).then(function (r) {return r.text().then(function (t) {'
        . 'if (r.status === 404 && t.trim() === "Not found.") {say("Yes: the door answers like a file that is not there to anybody without the secret.", "tsc-ok");}'
        . 'else if (r.status === 403) {say("No: the web server refuses the door (403). This host ignores the .htaccess of the module. Ask the host to allow agent.php in modules/threadsafeconnector, or see docs/PHASE15.md 3.2.", "tsc-fail");}'
        . 'else {say("Unexpected answer: HTTP " + r.status + ".", "tsc-fail");}});})'
        . '.catch(function () {say("The browser could not ask (another address or a certificate problem). Test it from the central ThreadSafe with profile test.", "tsc-help");});})();</script>';
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
        . '.tsc button,.tsc a.button{font-weight:600;font-size:.85rem;line-height:1.4;padding:.5rem 1rem;border:1px solid var(--edge);border-radius:4px;background:var(--fill);color:#14191f;cursor:pointer;text-decoration:none;display:inline-block;margin:.3rem .3rem .3rem 0}'
        . '.tsc button.secondary,.tsc a.button.secondary{background:var(--panel2);border-color:var(--strong);color:var(--text)}'
        . '.tsc button:focus-visible,.tsc textarea:focus-visible,.tsc input:focus-visible{outline:2px solid var(--brass);outline-offset:2px}'
        . '.tsc code{background:var(--recess);padding:.05rem .3rem;border-radius:3px;overflow-wrap:anywhere}.tsc pre{background:var(--recess);padding:.7rem;border-radius:4px;overflow-x:auto;white-space:pre-wrap;word-break:break-all}'
        . '.tsc-ok,.tsc .ok{color:var(--ok);font-weight:600}.tsc-fail,.tsc .fail{color:var(--error);font-weight:600}'
        . '@media (max-width:600px){.tsc-state{grid-template-columns:1fr}.tsc-state dt{margin-top:.5rem}}'
        . '</style>';
}
