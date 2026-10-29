<?php

declare(strict_types=1);

namespace ThreadSafe\WordPress\Steps;

use RuntimeException;
use ThreadSafe\Core\AtomicFile;
use ThreadSafe\Core\Change;
use ThreadSafe\Core\Context;
use ThreadSafe\Core\Plan;
use ThreadSafe\Core\Step;
use ThreadSafe\Core\StepResult;
use ThreadSafe\WordPress\State;

/**
 * Makes the directories that WordPress writes to and a backup leaves out: `wp-content/upgrade` (the updates of core, plugins and themes) and
 * `wp-content/uploads`. A new directory gets the mode of its parent and, if ThreadSafe runs as root, its owner. Nothing that exists is changed.
 * The cache directories of the caching plugins are not made: each plugin makes its own when it runs.
 */
final class EnsureSkeleton implements Step
{
    /** the directories, relative to the site root */
    public const array DIRECTORIES = ['wp-content/uploads', 'wp-content/upgrade'];

    public function id(): string
    {
        return 'ensure-skeleton';
    }

    public function title(): string
    {
        return 'Create the directories WordPress writes to';
    }

    public function plan(Context $context): Plan
    {
        $plan = new Plan();
        $root = State::source($context)->root;
        foreach (self::DIRECTORIES as $relative) {
            if (!is_dir($root . '/' . $relative)) {
                $plan->add(Change::create("dir $relative", 'create the directory'));
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
            ++$applied;
        }

        return new StepResult($applied);
    }
}
