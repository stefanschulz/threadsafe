<?php

declare(strict_types=1);

namespace ThreadSafe\PrestaShop9\Steps;

use RuntimeException;
use ThreadSafe\Core\Change;
use ThreadSafe\Core\Context;
use ThreadSafe\Core\Plan;
use ThreadSafe\Core\Resources;
use ThreadSafe\Core\Step;
use ThreadSafe\Core\StepResult;
use ThreadSafe\PrestaShop9\State;

/**
 * Makes the links of the back office again: PrestaShop links `<admin>/bundles/<name>` to the public files of a bundle in `vendor/`, and a backup
 * does not keep symbolic links, so a restored shop has the directory `bundles/` and nothing in it (field test of the PrestaShop module,
 * docs/PHASE15.md 8). The list of links is a data file (`bundle-links.json`).
 *
 * A link is made only where its target exists and its name is free, and it is relative (`../../vendor/...`), so it holds when the shop is moved. Where
 * the host does not allow symbolic links, the files are copied instead.
 */
final class RestoreBundleLinks implements Step
{
    public function id(): string
    {
        return 'restore-bundle-links';
    }

    public function title(): string
    {
        return 'Make the links of the back office to the files of the bundles again';
    }

    public function plan(Context $context): Plan
    {
        $plan = new Plan();
        foreach ($this->missing($context) as $name => $target) {
            $plan->add(Change::create("link bundles/$name", "link to $target"));
        }

        return $plan;
    }

    public function apply(Context $context, Plan $plan): StepResult
    {
        $source = State::source($context);
        $admin = $source->adminDirectory;
        $applied = 0;
        foreach ($this->missing($context) as $name => $target) {
            $directory = $source->root . '/' . $admin . '/bundles';
            if (!is_dir($directory) && !@mkdir($directory, 0755) && !is_dir($directory)) {
                throw new RuntimeException("Cannot create \"$directory\".");
            }
            $link = $directory . '/' . $name;
            if (@symlink('../../' . $target, $link)) {
                $context->journal->file($link, 'create');
            } else {
                $this->copy($source->root . '/' . $target, $link);
                $context->journal->file($link, 'create');
                $context->journal->note('the host does not allow a symbolic link: the files were copied', ['link' => "$admin/bundles/$name"]);
            }
            ++$applied;
        }

        return new StepResult($applied);
    }

    /**
     * @return array<string, string> name => target (relative to the root) of the links that are missing
     */
    private function missing(Context $context): array
    {
        $source = State::source($context);
        if ($source->adminDirectory === null) {
            return [];
        }
        $missing = [];
        $links = Resources::json('prestashop9/bundle-links.json')['links'] ?? [];
        foreach (is_array($links) ? $links : [] as $name => $target) {
            $link = $source->root . '/' . $source->adminDirectory . '/bundles/' . $name;
            if (!is_link($link) && !file_exists($link) && is_dir($source->root . '/' . $target)) {
                $missing[(string) $name] = (string) $target;
            }
        }

        return $missing;
    }

    private function copy(string $from, string $to): void
    {
        if (!@mkdir($to, 0755, true) && !is_dir($to)) {
            throw new RuntimeException("Cannot create \"$to\".");
        }
        foreach (scandir($from) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || is_link($from . '/' . $entry)) {
                continue;
            }
            if (is_dir($from . '/' . $entry)) {
                $this->copy($from . '/' . $entry, $to . '/' . $entry);
            } elseif (!@copy($from . '/' . $entry, $to . '/' . $entry)) {
                throw new RuntimeException("Cannot copy \"$from/$entry\".");
            }
        }
    }
}
