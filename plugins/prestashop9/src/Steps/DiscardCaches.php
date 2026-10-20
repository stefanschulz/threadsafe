<?php

declare(strict_types=1);

namespace ThreadSafe\PrestaShop9\Steps;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use ThreadSafe\Core\Change;
use ThreadSafe\Core\Context;
use ThreadSafe\Core\Notice;
use ThreadSafe\Core\Plan;
use ThreadSafe\Core\Resources;
use ThreadSafe\Core\Step;
use ThreadSafe\Core\StepResult;
use ThreadSafe\PrestaShop9\Modules\FileRule;
use ThreadSafe\PrestaShop9\State;

/**
 * Throws away everything PrestaShop caches about the old environment: the cache directories and the cache tables in
 * the database. Without this the shop keeps running on the old configuration (the copy of parameters.php in
 * var/cache/<env>/appParameters.php even holds the old database password) or works only in debug mode.
 *
 * Protection files (index.php, .htaccess, CACHEDIR.TAG …) at the top of a cache directory stay.
 */
final class DiscardCaches implements Step
{
    public function id(): string
    {
        return 'discard-caches';
    }

    public function title(): string
    {
        return 'Discard caches (directories and cache tables)';
    }

    public function plan(Context $context): Plan
    {
        $plan = new Plan();
        $config = Resources::json('prestashop9/caches.json');
        $keep = Resources::strings('prestashop9/caches.json', 'keep');
        $sensitive = Resources::strings('prestashop9/caches.json', 'sensitiveFiles');
        $source = State::source($context);

        foreach (Resources::strings('prestashop9/caches.json', 'directories') as $relative) {
            $directory = $this->resolve($source->root, $relative);
            if ($directory === null) {
                continue;
            }
            $files = $this->countFiles($directory, $keep);
            if ($files > 0) {
                $plan->add(Change::delete("dir $relative", 'remove cached files', count: $files));
            }
            foreach ($sensitive as $name) {
                foreach (glob($directory . '/*/' . $name) ?: [] as $found) {
                    $plan->notice(Notice::info('Cache file with a copy of the old settings (incl. database password) will be removed.', $relative . '/' . basename(dirname($found)) . '/' . $name));
                }
            }
        }

        foreach ($this->moduleFiles($context) as [$module, $glob, $paths]) {
            $files = 0;
            foreach ($paths as $path) {
                $files += is_dir($path) && !is_link($path) ? $this->countFiles($path, $keep) : 1;
            }
            if ($files > 0) {
                $plan->add(Change::delete("files $glob", "remove files of module $module that are rebuilt (module rule)", count: $files));
            }
        }

        $db = State::connection($context);
        foreach (Resources::strings('prestashop9/caches.json', 'tables') as $name) {
            $table = $db->table($name);
            if (!$db->tableExists($table)) {
                continue;
            }
            $rows = (int) $db->scalar('SELECT COUNT(*) FROM ' . $db->quote($table));
            if ($rows > 0) {
                $plan->add(Change::delete("db $table", 'empty cache table', count: $rows));
            }
        }
        unset($config);

        return $plan;
    }

    public function apply(Context $context, Plan $plan): StepResult
    {
        $keep = Resources::strings('prestashop9/caches.json', 'keep');
        $source = State::source($context);
        $db = State::connection($context);
        $applied = 0;

        foreach (Resources::strings('prestashop9/caches.json', 'directories') as $relative) {
            $directory = $this->resolve($source->root, $relative);
            if ($directory === null) {
                continue;
            }
            $removed = $this->emptyDirectory($directory, $keep);
            if ($removed > 0) {
                $context->journal->note('cache directory emptied', ['directory' => $relative, 'files' => $removed]);
                $applied += $removed;
            }
        }

        foreach ($this->moduleFiles($context) as [$module, $glob, $paths]) {
            $removed = 0;
            foreach ($paths as $path) {
                if (is_dir($path) && !is_link($path)) {
                    $removed += $this->emptyDirectory($path, $keep);
                } elseif (@unlink($path)) {
                    ++$removed;
                } else {
                    throw new RuntimeException("Cannot delete \"$path\".");
                }
            }
            if ($removed > 0) {
                $context->journal->note('module files removed', ['module' => $module, 'glob' => $glob, 'files' => $removed]);
                $applied += $removed;
            }
        }

        foreach (Resources::strings('prestashop9/caches.json', 'tables') as $name) {
            $table = $db->table($name);
            if (!$db->tableExists($table)) {
                continue;
            }
            $rows = (int) $db->scalar('SELECT COUNT(*) FROM ' . $db->quote($table));
            if ($rows > 0) {
                $db->execute('TRUNCATE TABLE ' . $db->quote($table));
                $context->journal->note('cache table emptied', ['table' => $table, 'rows' => $rows]);
                $applied += $rows;
            }
        }

        return new StepResult($applied);
    }

    /**
     * The files that module rules say to delete: module, glob and the matching paths, all inside the shop root.
     *
     * @return list<array{string, string, list<string>}>
     */
    private function moduleFiles(Context $context): array
    {
        $root = realpath(State::source($context)->root);
        $keep = Resources::strings('prestashop9/caches.json', 'keep');
        $found = [];
        foreach (State::moduleRules($context)->all() as $rule) {
            foreach ($rule->files as $file) {
                if ($file->action !== FileRule::DELETE || $root === false) {
                    continue;
                }
                $paths = [];
                foreach (glob($root . '/' . $file->glob) ?: [] as $path) {
                    if (in_array(basename($path), $keep, true)) {
                        continue; // protection files (index.php, .htaccess …) stay, like in the cache directories
                    }
                    $real = is_link($path) ? $path : realpath($path);
                    // A link is deleted itself (never followed); anything else must really be inside the shop.
                    if ($real !== false && (is_link($path) || str_starts_with($real . '/', $root . '/')) && $real !== $root) {
                        $paths[] = $path;
                    }
                }
                if ($paths !== []) {
                    $found[] = [$rule->module, $file->glob, $paths];
                }
            }
        }

        return $found;
    }

    /** The absolute path of a cache directory, or null if it does not exist or leads out of the shop root. */
    private function resolve(string $root, string $relative): ?string
    {
        $path = realpath($root . '/' . $relative);
        $base = realpath($root);
        if ($path === false || $base === false || !is_dir($path)) {
            return null;
        }
        if (!str_starts_with($path . '/', rtrim($base, '/\\') . '/') || $path === $base) {
            throw new RuntimeException("Refusing to clean \"$path\": it is not inside the shop root.");
        }

        return $path;
    }

    /**
     * @param list<string> $keep
     */
    private function countFiles(string $directory, array $keep): int
    {
        $count = 0;
        foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $entry) {
            /** @var SplFileInfo $entry */
            if (in_array($entry->getFilename(), $keep, true)) {
                continue;
            }
            if ($entry->isDir() && !$entry->isLink()) {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($entry->getPathname(), FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::LEAVES_ONLY,
                );
                foreach ($iterator as $leaf) {
                    /** @var SplFileInfo $leaf */
                    if (!$leaf->isDir() || $leaf->isLink()) {
                        ++$count;
                    }
                }
            } else {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * @param list<string> $keep
     *
     * @return int number of files removed
     */
    private function emptyDirectory(string $directory, array $keep): int
    {
        $removed = $this->countFiles($directory, $keep);
        foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $entry) {
            /** @var SplFileInfo $entry */
            if (!in_array($entry->getFilename(), $keep, true)) {
                $this->remove($entry);
            }
        }

        return $removed;
    }

    /** Deletes a file, a link (never followed) or a directory tree. */
    private function remove(SplFileInfo $entry): void
    {
        if ($entry->isLink() || !$entry->isDir()) {
            if (!@unlink($entry->getPathname())) {
                throw new RuntimeException('Cannot delete "' . $entry->getPathname() . '".');
            }

            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($entry->getPathname(), FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            /** @var SplFileInfo $item */
            $ok = $item->isLink() || !$item->isDir() ? @unlink($item->getPathname()) : @rmdir($item->getPathname());
            if (!$ok) {
                throw new RuntimeException('Cannot delete "' . $item->getPathname() . '".');
            }
        }
        if (!@rmdir($entry->getPathname())) {
            throw new RuntimeException('Cannot delete "' . $entry->getPathname() . '".');
        }
    }
}
