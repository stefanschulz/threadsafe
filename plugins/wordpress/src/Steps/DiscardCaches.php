<?php

declare(strict_types=1);

namespace ThreadSafe\WordPress\Steps;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ThreadSafe\Core\Change;
use ThreadSafe\Core\Context;
use ThreadSafe\Core\Notice;
use ThreadSafe\Core\Plan;
use ThreadSafe\Core\Resources;
use ThreadSafe\Core\Step;
use ThreadSafe\Core\StepResult;
use ThreadSafe\Database\Connection;
use ThreadSafe\WordPress\Installation;
use ThreadSafe\WordPress\State;

/**
 * Clears what WordPress rebuilds and what holds the old host (docs/PHASE9.md 3.5, step 5):
 *
 * - the **transients** (the rows `_transient_*` and `_site_transient_*` of the options table: caches that often hold the old address);
 * - the option **`rewrite_rules`**, which WordPress makes again from the permalink settings;
 * - the files of `wp-content/cache` (caching plugins make theirs again) and of `wp-content/upgrade`;
 * - the **drop-ins** `object-cache.php`, `advanced-cache.php` and `db.php`, **renamed to `<name>.threadsafe-off`** unless the request says
 *   `keepDropIns`: a drop-in that serves a service of the old host (Redis, a page cache, a database proxy) takes the whole site down with
 *   an error on every page, and a drop-in is only loaded under its exact name (work package 1, finding 5). The plugin that owns it can
 *   write it again, and the file keeps its content under the new name;
 * - what plugins generate from the content and keep (`resources/generated.json`, field test): the CSS files of Elementor name the address
 *   of the images and are not made again as long as the record `_elementor_css` of the post says they exist, so the directory and the
 *   records go and Elementor makes them when a page is seen. Which plugin writes what is data, not code.
 *
 * Nothing that is content is touched.
 */
final class DiscardCaches implements Step
{
    /** directories whose files go (the directory stays) */
    private const array DIRECTORIES = ['wp-content/cache', 'wp-content/upgrade'];

    /** the ending of a drop-in that is switched off */
    public const string OFF = '.threadsafe-off';

    public function id(): string
    {
        return 'discard-caches';
    }

    public function title(): string
    {
        return 'Clear the caches and switch off the drop-ins';
    }

    public function plan(Context $context): Plan
    {
        $plan = new Plan();
        $source = State::source($context);
        $request = State::request($context);
        $db = State::connection($context);

        $transients = $this->transients($db);
        if ($transients > 0) {
            $plan->add(Change::delete('table ' . $db->table('options'), 'delete the transients (they are caches)', count: $transients));
        }
        if ($this->hasRewriteRules($db)) {
            $plan->add(Change::delete('option rewrite_rules', 'forget the rewrite rules (WordPress makes them again)'));
        }
        foreach ($this->directories() as $relative) {
            $count = count($this->entries($source->root . '/' . $relative));
            if ($count > 0) {
                $plan->add(Change::delete("dir $relative", 'empty the directory', count: $count));
            }
        }
        $meta = $this->generatedMeta($db);
        if ($meta > 0) {
            $plan->add(Change::delete('table ' . $db->table('postmeta'), 'delete the records of generated files (' . implode(', ', $this->generated('postMeta')) . '); the plugin makes them again', count: $meta));
        }
        $generatedOptions = $this->generatedOptions($db);
        if ($generatedOptions > 0) {
            $plan->add(Change::delete('table ' . $db->table('options'), 'delete the records of generated files (' . implode(', ', $this->generated('options')) . '); the plugin makes them again', count: $generatedOptions));
        }
        foreach ($this->dropIns($source->root) as $name) {
            if ($request->options['keepDropIns'] ?? false) {
                $plan->notice(Notice::warning("The drop-in wp-content/$name is kept; if it serves something that this host does not have (Redis, a page cache), every page answers with an error.", $name));
            } else {
                $plan->add(Change::update("file wp-content/$name", 'switch the drop-in off (it may serve something the old host had)', $name, $name . self::OFF));
            }
        }

        return $plan;
    }

    public function apply(Context $context, Plan $plan): StepResult
    {
        $source = State::source($context);
        $request = State::request($context);
        $db = State::connection($context);
        $applied = 0;

        $options = $db->quote($db->table('options'));
        if ($this->transients($db) > 0) {
            $rows = $db->execute("DELETE FROM $options WHERE BINARY LEFT(option_name, 11) = '_transient_' OR BINARY LEFT(option_name, 16) = '_site_transient_'");
            $context->journal->note('transients deleted', ['rows' => $rows]);
            $applied += $rows;
        }
        if ($this->hasRewriteRules($db)) {
            $applied += $db->execute("DELETE FROM $options WHERE option_name = 'rewrite_rules'");
            $context->journal->note('option deleted', ['option' => 'rewrite_rules']);
        }
        foreach ($this->directories() as $relative) {
            $entries = $this->entries($source->root . '/' . $relative);
            foreach ($entries as $path) {
                $this->remove($path);
            }
            if ($entries !== []) {
                $context->journal->note('directory emptied', ['directory' => $relative, 'entries' => count($entries)]);
                $applied += count($entries);
            }
        }
        if ($this->generatedMeta($db) > 0) {
            [$marks, $keys] = $this->in($this->generated('postMeta'));
            $rows = $db->execute('DELETE FROM ' . $db->quote($db->table('postmeta')) . " WHERE BINARY meta_key IN ($marks)", $keys);
            $context->journal->note('post meta deleted', ['keys' => implode(', ', $this->generated('postMeta')), 'rows' => $rows]);
            $applied += $rows;
        }
        if ($this->generatedOptions($db) > 0) {
            [$marks, $names] = $this->in($this->generated('options'));
            $rows = $db->execute("DELETE FROM $options WHERE BINARY option_name IN ($marks)", $names);
            $context->journal->note('options deleted', ['options' => implode(', ', $this->generated('options')), 'rows' => $rows]);
            $applied += $rows;
        }
        if (!($request->options['keepDropIns'] ?? false)) {
            foreach ($this->dropIns($source->root) as $name) {
                $from = $source->root . '/wp-content/' . $name;
                $to = $from . self::OFF;
                if (is_file($to)) {
                    @unlink($to);   // an earlier run's
                }
                if (!@rename($from, $to)) {
                    throw new RuntimeException("Cannot switch off the drop-in \"$from\".");
                }
                $context->journal->note('drop-in switched off', ['file' => 'wp-content/' . $name]);
                ++$applied;
            }
        }

        return new StepResult($applied);
    }

    /** @return list<string> the names of the drop-ins that are there */
    private function dropIns(string $root): array
    {
        return Installation::dropIns($root);
    }

    private function transients(Connection $db): int
    {
        return (int) $db->scalar("SELECT COUNT(*) FROM {$db->quote($db->table('options'))} WHERE BINARY LEFT(option_name, 11) = '_transient_' OR BINARY LEFT(option_name, 16) = '_site_transient_'");
    }

    /** @return list<string> the directories to empty: those of WordPress and those that plugins generate (`resources/generated.json`) */
    private function directories(): array
    {
        return [...self::DIRECTORIES, ...$this->generated('directories')];
    }

    /**
     * What plugins generate from the content: `directories`, `postMeta` keys and `options` names (`resources/generated.json`).
     *
     * @return list<string>
     */
    private function generated(string $kind): array
    {
        return Resources::strings('wordpress/generated.json', $kind);
    }

    /**
     * @param list<string> $values
     *
     * @return array{0: string, 1: list<string>} the placeholders for an IN list, and the values
     */
    private function in(array $values): array
    {
        return [implode(', ', array_fill(0, count($values), '?')), $values];
    }

    private function generatedMeta(Connection $db): int
    {
        if ($this->generated('postMeta') === []) {
            return 0;
        }
        [$marks, $keys] = $this->in($this->generated('postMeta'));

        return (int) $db->scalar('SELECT COUNT(*) FROM ' . $db->quote($db->table('postmeta')) . " WHERE BINARY meta_key IN ($marks)", $keys);
    }

    private function generatedOptions(Connection $db): int
    {
        if ($this->generated('options') === []) {
            return 0;
        }
        [$marks, $names] = $this->in($this->generated('options'));

        return (int) $db->scalar('SELECT COUNT(*) FROM ' . $db->quote($db->table('options')) . " WHERE BINARY option_name IN ($marks)", $names);
    }

    private function hasRewriteRules(Connection $db): bool
    {
        return (int) $db->scalar("SELECT COUNT(*) FROM {$db->quote($db->table('options'))} WHERE option_name = 'rewrite_rules'") > 0;
    }

    /**
     * What is in the directory besides its `index.php`: the paths of its first level.
     *
     * @return list<string>
     */
    private function entries(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }
        $paths = [];
        foreach (scandir($directory) ?: [] as $name) {
            $path = $directory . '/' . $name;
            if ($name === '.' || $name === '..' || ($name === 'index.php' && is_file($path) && !is_link($path))) {
                continue;
            }
            $paths[] = $path;
        }

        return $paths;
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            if (!@unlink($path)) {
                throw new RuntimeException("Cannot remove \"$path\".");
            }

            return;
        }
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $ok = $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            if (!$ok) {
                throw new RuntimeException('Cannot remove "' . $item->getPathname() . '".');
            }
        }
        if (!@rmdir($path)) {
            throw new RuntimeException("Cannot remove \"$path\".");
        }
    }
}
