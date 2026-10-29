<?php

declare(strict_types=1);

namespace ThreadSafe\WordPress\Steps;

use RuntimeException;
use ThreadSafe\Core\AtomicFile;
use ThreadSafe\Core\Change;
use ThreadSafe\Core\Context;
use ThreadSafe\Core\Notice;
use ThreadSafe\Core\Plan;
use ThreadSafe\Core\Step;
use ThreadSafe\Core\StepResult;
use ThreadSafe\Database\Connection;
use ThreadSafe\WordPress\Backup\OptionStrings;
use ThreadSafe\WordPress\State;

/**
 * Dev mode, a small first cut (docs/PHASE9.md 3.6): a copy for development or staging that sends no mail and keeps search engines out.
 *
 *  - the option **`blog_public`** is set to 0 ("discourage search engines": WordPress then adds the robots meta tag and a `Disallow: /` to its
 *    own `robots.txt`; a physical `robots.txt` is not made, because the site's own would be hidden by it);
 *  - a block at the top of **`.htaccess`** sends `X-Robots-Tag: noindex, nofollow` with every response (a page that a search engine already
 *    knows is only dropped by a noindex); `.htaccess` is made if the site has none;
 *  - the **must-use plugin** `wp-content/mu-plugins/threadsafe-dev.php` stops `wp_mail()` (the filter `pre_wp_mail`) and shows a notice in the
 *    administration that this is a copy. There is no setting for outgoing mail, and a must-use plugin needs no knowledge of the mail plugins
 *    that send through `wp_mail()`; a plugin that talks to a mail service on its own is not stopped.
 *
 * The step says in words what it does not do: payment gateways in live mode and the keys of other services are plugin knowledge, and nothing
 * is switched off by guess. In a migration that is not dev mode, a copy that carries the must-use plugin is reported (a backup of a dev copy
 * holds it, and a restore of that backup in production would go on keeping mail back).
 */
final class ApplyDevMode implements Step
{
    private const string START = '# BEGIN ThreadSafe dev mode';

    private const string END = '# END ThreadSafe dev mode';

    public const string HEADER = 'noindex, nofollow';

    /** The file of the must-use plugin, relative to the site directory. */
    public const string PLUGIN = 'wp-content/mu-plugins/threadsafe-dev.php';

    public const string PLUGIN_MARKER = 'ThreadSafe dev mode';

    public function id(): string
    {
        return 'apply-dev-mode';
    }

    public function title(): string
    {
        return 'Dev mode: no mail, search engines kept out';
    }

    public function plan(Context $context): Plan
    {
        $plan = new Plan();
        $root = State::source($context)->root;
        if (!State::request($context)->isDevMode()) {
            if (is_file($root . '/' . self::PLUGIN) && str_contains((string) file_get_contents($root . '/' . self::PLUGIN), self::PLUGIN_MARKER)) {
                $plan->notice(Notice::warning('This site carries the must-use plugin ' . self::PLUGIN . ' of a dev mode copy: it keeps every mail back and shows "this is a copy" in the administration. Delete it if this is the live site.', self::PLUGIN));
            }

            return $plan;
        }
        $db = State::connection($context);
        if ($this->blogPublic($db) !== '0') {
            $plan->add(Change::update('option blog_public', 'discourage search engines', $this->blogPublic($db) ?? '(none)', '0'));
        }
        $htaccess = $this->htaccess($root);
        if ($htaccess[0] !== $htaccess[1]) {
            $plan->add($htaccess[0] === null ? Change::create('file .htaccess', 'create it with the header that keeps search engines out') : Change::update('file .htaccess', 'send X-Robots-Tag: ' . self::HEADER));
        }
        $plugin = $this->plugin($root);
        if ($plugin[0] !== $plugin[1]) {
            $plan->add($plugin[0] === null ? Change::create('file ' . self::PLUGIN, 'stop outgoing mail and say in the administration that this is a copy') : Change::update('file ' . self::PLUGIN, 'stop outgoing mail'));
        }
        $plan->notice(Notice::info('Outgoing mail through wp_mail() is stopped (it reports success and sends nothing). A plugin that sends through a mail service of its own is not stopped.'));
        $plan->notice(Notice::info('On a web server other than Apache the header is not sent: the robots meta tag and robots.txt of WordPress keep search engines out; send "X-Robots-Tag: ' . self::HEADER . '" from the web server too.'));
        if ($this->hasWooCommerce($db)) {
            $plan->notice(Notice::warning('WooCommerce is active: payment gateways in live mode, webhooks and the keys of other services are NOT switched off by dev mode. Check the payment methods before anybody orders on this copy.'));
        }

        return $plan;
    }

    public function apply(Context $context, Plan $plan): StepResult
    {
        if (!State::request($context)->isDevMode()) {
            return new StepResult(0);
        }
        $root = State::source($context)->root;
        $db = State::connection($context);
        $applied = 0;

        $current = $this->blogPublic($db);
        if ($current !== '0') {
            $table = $db->quote($db->table('options'));
            if ($current === null) {
                $db->execute("INSERT INTO $table (option_name, option_value, autoload) VALUES ('blog_public', '0', 'yes')");
            } else {
                $db->execute("UPDATE $table SET option_value = '0' WHERE option_name = 'blog_public'");
            }
            $context->journal->database($db->table('options'), ['option_name' => 'blog_public'], 'option_value', $current, '0');
            ++$applied;
        }

        $files = ['.htaccess' => $this->htaccess($root), self::PLUGIN => $this->plugin($root)];
        foreach ($files as $name => [$old, $new]) {
            if ($old === $new) {
                continue;
            }
            $path = $root . '/' . $name;
            $directory = dirname($path);
            if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new RuntimeException("Cannot create the directory \"$directory\".");
            }
            $backup = $context->journal->backupFile($path);
            AtomicFile::write($path, $new);
            $context->journal->file($path, $old === null ? 'create' : 'update', $backup);
            ++$applied;
        }

        return new StepResult($applied);
    }

    /** The `.htaccess` content with exactly one header block at the very top. */
    public static function withBlock(string $content): string
    {
        $block = self::START . " - written by ThreadSafe; delete the block to let search engines in.\n"
            . "<IfModule mod_headers.c>\n"
            . '    Header set X-Robots-Tag "' . self::HEADER . "\"\n"
            . "</IfModule>\n"
            . self::END . "\n";
        $without = preg_replace('/' . preg_quote(self::START, '/') . '.*?' . preg_quote(self::END, '/') . "\r?\n?/s", '', $content) ?? $content;

        return $without === '' ? $block : $block . "\n" . ltrim($without, "\r\n");
    }

    /** The must-use plugin: a few lines, no dependence on anything but WordPress. */
    public static function pluginSource(): string
    {
        return <<<'PHP'
<?php
/**
 * Plugin Name: ThreadSafe dev mode
 * Description: Written by ThreadSafe when this site was restored as a development copy. Stops outgoing mail and says so in the administration. Delete this file to send mail again.
 */

if (!defined('ABSPATH')) {
    exit;
}

// wp_mail() reports success and sends nothing
add_filter('pre_wp_mail', '__return_true');

add_action('admin_notices', static function (): void {
    echo '<div class="notice notice-warning"><p><strong>This is a development copy.</strong> ThreadSafe stopped outgoing mail (wp_mail) and asked search engines to stay away. Delete wp-content/mu-plugins/threadsafe-dev.php to send mail again.</p></div>';
});

PHP;
    }

    /** @return array{string|null, string} current and wanted content of `.htaccess` (current null: no file) */
    private function htaccess(string $root): array
    {
        $current = is_file($root . '/.htaccess') ? file_get_contents($root . '/.htaccess') : false;

        return [$current === false ? null : $current, self::withBlock($current === false ? '' : $current)];
    }

    /** @return array{string|null, string} current and wanted content of the must-use plugin */
    private function plugin(string $root): array
    {
        $path = $root . '/' . self::PLUGIN;
        $current = is_file($path) ? file_get_contents($path) : false;

        return [$current === false ? null : $current, self::pluginSource()];
    }

    private function blogPublic(Connection $db): ?string
    {
        $value = $db->scalar('SELECT option_value FROM ' . $db->quote($db->table('options')) . " WHERE option_name = 'blog_public'");

        return $value === null || $value === false ? null : (string) $value;
    }

    private function hasWooCommerce(Connection $db): bool
    {
        $plugins = $db->scalar('SELECT option_value FROM ' . $db->quote($db->table('options')) . " WHERE option_name = 'active_plugins'");
        foreach (is_string($plugins) ? OptionStrings::list($plugins) : [] as $plugin) {
            if (str_starts_with($plugin, 'woocommerce/')) {
                return true;
            }
        }

        return false;
    }
}
