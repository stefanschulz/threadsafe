<?php

declare(strict_types=1);

namespace ThreadSafe\PrestaShop9\Steps;

use RuntimeException;
use ThreadSafe\Core\AtomicFile;
use ThreadSafe\Core\Change;
use ThreadSafe\Core\Context;
use ThreadSafe\Core\Plan;
use ThreadSafe\Core\Resources;
use ThreadSafe\Core\Step;
use ThreadSafe\Core\StepResult;
use ThreadSafe\PrestaShop9\State;

/**
 * Makes sure the directories PrestaShop writes to exist and are usable. A backup usually leaves out `var/cache`, logs
 * and sessions, and a restored tree can have lost directory permissions. New directories get the mode of their parent
 * and, if ThreadSafe runs as root, the owner of their parent, so that the web server can use them.
 *
 * No broad permissions are granted: existing directories only get the owner's write bit if it is missing.
 */
final class EnsureSkeleton implements Step
{
    public function id(): string
    {
        return 'ensure-skeleton';
    }

    public function title(): string
    {
        return 'Create missing directories and make them writable';
    }

    public function plan(Context $context): Plan
    {
        $plan = new Plan();
        $root = State::source($context)->root;

        foreach (Resources::strings('prestashop9/skeleton.json', 'create') as $relative) {
            if (!is_dir($root . '/' . $relative)) {
                $plan->add(Change::create("dir $relative", 'create the directory'));
            }
        }
        foreach (Resources::strings('prestashop9/skeleton.json', 'writable') as $relative) {
            $path = $root . '/' . $relative;
            if (is_dir($path) && (fileperms($path) & 0200) === 0) {
                $plan->add(Change::update("dir $relative", 'make the directory writable for its owner', null, null));
            }
        }

        return $plan;
    }

    public function apply(Context $context, Plan $plan): StepResult
    {
        $root = State::source($context)->root;
        $applied = 0;

        foreach (Resources::strings('prestashop9/skeleton.json', 'create') as $relative) {
            $applied += $this->create($context, $root, $relative);
        }
        foreach (Resources::strings('prestashop9/skeleton.json', 'writable') as $relative) {
            $path = $root . '/' . $relative;
            if (is_dir($path) && (fileperms($path) & 0200) === 0) {
                if (!chmod($path, (fileperms($path) & 0777) | 0200)) {
                    throw new RuntimeException("Cannot make \"$path\" writable.");
                }
                $context->journal->note('directory made writable', ['directory' => $relative]);
                ++$applied;
            }
        }

        return new StepResult($applied);
    }

    /** Creates a directory and any missing parents; returns the number of directories made. */
    private function create(Context $context, string $root, string $relative): int
    {
        $missing = [];
        $path = $root . '/' . $relative;
        while (!is_dir($path) && $path !== $root && strlen($path) > strlen($root)) {
            array_unshift($missing, $path);
            $path = dirname($path);
        }

        foreach ($missing as $directory) {
            $parent = dirname($directory);
            $mode = (fileperms($parent) & 0777) | 0700;
            if (!@mkdir($directory, $mode) && !is_dir($directory)) {
                throw new RuntimeException("Cannot create \"$directory\".");
            }
            @chmod($directory, $mode);
            AtomicFile::adoptOwner($directory, $parent);
            $context->journal->file($directory, 'create');
        }

        return count($missing);
    }
}
