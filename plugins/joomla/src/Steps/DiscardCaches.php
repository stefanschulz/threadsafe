<?php

declare(strict_types=1);

namespace ThreadSafe\Joomla\Steps;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ThreadSafe\Core\Change;
use ThreadSafe\Core\Context;
use ThreadSafe\Core\Plan;
use ThreadSafe\Core\Step;
use ThreadSafe\Core\StepResult;
use ThreadSafe\Database\Connection;
use ThreadSafe\Joomla\State;

/**
 * Clears what Joomla rebuilds and what holds the old host: the files of `cache`, `administrator/cache` and `tmp` (the `index.html` of
 * each stays), the sessions (they carry the old paths and the logged-in users of the old site) and the cached update information.
 * Nothing that is content is touched.
 */
final class DiscardCaches implements Step
{
    private const array DIRECTORIES = ['cache', 'administrator/cache', 'tmp'];

    /** table (without prefix) => what emptying it means */
    private const array TABLES = [
        'session' => 'end the sessions of the old site',
        'updates' => 'forget the cached update information (it is fetched again)',
    ];

    public function id(): string
    {
        return 'discard-caches';
    }

    public function title(): string
    {
        return 'Clear the caches and the sessions';
    }

    public function plan(Context $context): Plan
    {
        $plan = new Plan();
        $root = State::source($context)->root;
        foreach (self::DIRECTORIES as $relative) {
            $count = count($this->entries($root . '/' . $relative));
            if ($count > 0) {
                $plan->add(Change::delete("dir $relative", 'empty the directory (index.html stays)', count: $count));
            }
        }
        $db = State::connection($context);
        foreach (self::TABLES as $table => $description) {
            $rows = $this->rows($db, $table);
            if ($rows > 0) {
                $plan->add(Change::delete('table ' . $db->table($table), $description, count: $rows));
            }
        }

        return $plan;
    }

    public function apply(Context $context, Plan $plan): StepResult
    {
        $root = State::source($context)->root;
        $applied = 0;
        foreach (self::DIRECTORIES as $relative) {
            $entries = $this->entries($root . '/' . $relative);
            foreach ($entries as $path) {
                $this->remove($path);
            }
            if ($entries !== []) {
                $context->journal->note('directory emptied', ['directory' => $relative, 'entries' => count($entries)]);
                $applied += count($entries);
            }
        }
        $db = State::connection($context);
        foreach (array_keys(self::TABLES) as $table) {
            $rows = $this->rows($db, $table);
            if ($rows > 0) {
                $db->run('TRUNCATE TABLE ' . $db->quote($db->table($table)));
                $context->journal->note('table emptied', ['table' => $db->table($table), 'rows' => $rows]);
                $applied += $rows;
            }
        }

        return new StepResult($applied);
    }

    /**
     * What is in the directory besides its `index.html`: the paths of its first level.
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
            if ($name === '.' || $name === '..' || ($name === 'index.html' && is_file($path) && !is_link($path))) {
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

    private function rows(Connection $db, string $table): int
    {
        $name = $db->table($table);

        return $db->tableExists($name) ? (int) $db->scalar('SELECT COUNT(*) FROM ' . $db->quote($name)) : 0;
    }
}
