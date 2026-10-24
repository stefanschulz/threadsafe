<?php

declare(strict_types=1);

namespace ThreadSafe\Joomla\Steps;

use RuntimeException;
use ThreadSafe\Core\AtomicFile;
use ThreadSafe\Core\Change;
use ThreadSafe\Core\Context;
use ThreadSafe\Core\Plan;
use ThreadSafe\Core\Step;
use ThreadSafe\Core\StepResult;
use ThreadSafe\Joomla\State;

/**
 * Makes the directories that Joomla writes to, which a backup leaves out: `tmp`, `cache`, `administrator/cache` and
 * `administrator/logs`, each with the `index.html` placeholder that Joomla ships. Pages work without them, but everything that writes
 * there (uploads, the cache, the update, the logs) fails. A new directory gets the mode of its parent and, if ThreadSafe runs as root,
 * its owner. Nothing that exists is changed.
 */
final class EnsureSkeleton implements Step
{
    /** the directories, relative to the site root */
    public const array DIRECTORIES = ['tmp', 'cache', 'administrator/cache', 'administrator/logs'];

    /** what Joomla's own placeholder says */
    public const string PLACEHOLDER = "<!DOCTYPE html><title></title>\n";

    public function id(): string
    {
        return 'ensure-skeleton';
    }

    public function title(): string
    {
        return 'Create the directories Joomla writes to';
    }

    public function plan(Context $context): Plan
    {
        $plan = new Plan();
        $root = State::source($context)->root;
        foreach (self::DIRECTORIES as $relative) {
            if (!is_dir($root . '/' . $relative)) {
                $plan->add(Change::create("dir $relative", 'create the directory (with its index.html)'));
            }
        }

        return $plan;
    }

    public function apply(Context $context, Plan $plan): StepResult
    {
        $root = State::source($context)->root;
        $applied = 0;
        foreach (self::DIRECTORIES as $relative) {
            $path = $root . '/' . $relative;
            if (is_dir($path)) {
                continue;
            }
            $parent = dirname($path);
            if (!is_dir($parent) && !@mkdir($parent, 0755, true) && !is_dir($parent)) {
                throw new RuntimeException("Cannot create \"$parent\".");
            }
            $mode = fileperms($parent) & 0777;
            if (!@mkdir($path, $mode) && !is_dir($path)) {
                throw new RuntimeException("Cannot create \"$path\".");
            }
            @chmod($path, $mode);
            AtomicFile::adoptOwner($path, $parent);
            $context->journal->file($path, 'create');
            if (@file_put_contents($path . '/index.html', self::PLACEHOLDER) === false) {
                throw new RuntimeException("Cannot write \"$path/index.html\".");
            }
            @chmod($path . '/index.html', 0644);
            AtomicFile::adoptOwner($path . '/index.html', $path);
            ++$applied;
        }

        return new StepResult($applied);
    }
}
