<?php

declare(strict_types=1);

namespace ThreadSafe\PrestaShop9\Steps;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use ThreadSafe\Core\AtomicFile;
use ThreadSafe\Core\Change;
use ThreadSafe\Core\Context;
use ThreadSafe\Core\Notice;
use ThreadSafe\Core\Plan;
use ThreadSafe\Core\Resources;
use ThreadSafe\Core\Step;
use ThreadSafe\Core\StepResult;
use ThreadSafe\PrestaShop9\PhpIniRewriter;
use ThreadSafe\PrestaShop9\State;
use ThreadSafe\Replace\Stats;

/**
 * Fixes the files outside the database that carry the old host or server: absolute paths in `php.ini` and
 * `.user.ini`, the domain in `robots.txt`, the sitemaps and the generated mail templates. The list of files is in
 * resources/prestashop9/host-bound-files.json.
 *
 * Paths below the old base path in `robots.txt` (`Disallow: /old/…`) are not rewritten; the step points out that the
 * file should be regenerated instead.
 */
final class RewriteHostBoundFiles implements Step
{
    public function id(): string
    {
        return 'rewrite-host-bound-files';
    }

    public function title(): string
    {
        return 'Fix files that carry the old host or server path';
    }

    public function plan(Context $context): Plan
    {
        $plan = new Plan();
        $request = State::request($context);

        foreach ($this->phpIniFiles($context) as $path => $rewrite) {
            $relative = $this->relative($context, $path);
            foreach ($rewrite->changes as $change) {
                $plan->add(Change::update("file $relative {$change['directive']}", 'point to the path on this server', $change['before'], $change['after']));
            }
            foreach ($rewrite->warnings as $warning) {
                $plan->notice(Notice::warning($warning, $relative));
            }
        }

        foreach ($this->textGroups($context) as $label => $files) {
            $urls = array_sum(array_column($files, 'urls'));
            $plan->add(Change::update("files $label", sprintf('replace the old domain (%d URL(s))', $urls), count: count($files)));
        }

        if ($request->physicalUri !== null && is_file(State::source($context)->root . '/robots.txt')) {
            $plan->notice(Notice::warning('robots.txt lists paths below the old base path; regenerate it (back office: Shop Parameters > Traffic & SEO > SEO & URLs > Robots file generation).', 'robots.txt'));
        }

        return $plan;
    }

    public function apply(Context $context, Plan $plan): StepResult
    {
        $applied = 0;

        foreach ($this->phpIniFiles($context) as $path => $rewrite) {
            if ($rewrite->changes === []) {
                continue;
            }
            $this->write($context, $path, $rewrite->content);
            $applied += count($rewrite->changes);
        }

        foreach ($this->textGroups($context) as $files) {
            foreach ($files as $path => $file) {
                $this->write($context, $path, $file['content']);
                ++$applied;
            }
        }

        return new StepResult($applied);
    }

    private function write(Context $context, string $path, string $content): void
    {
        $backup = $context->journal->backupFile($path);
        AtomicFile::write($path, $content);
        $context->journal->file($path, 'update', $backup);
    }

    private function relative(Context $context, string $path): string
    {
        return ltrim(substr($path, strlen(State::source($context)->root)), '/');
    }

    /**
     * @return array<string, \ThreadSafe\PrestaShop9\PhpIniRewrite> absolute path → rewrite (including files without changes)
     */
    private function phpIniFiles(Context $context): array
    {
        $request = State::request($context);
        $root = State::source($context)->root;
        $skeleton = Resources::strings('prestashop9/skeleton.json', 'writable');
        $rewriter = new PhpIniRewriter(
            Resources::strings('prestashop9/host-bound-files.json', 'phpIniDirectives'),
            $root,
            $request->serverPath(),
            $skeleton,
        );

        $result = [];
        foreach (Resources::strings('prestashop9/host-bound-files.json', 'phpIni') as $name) {
            $path = $root . '/' . $name;
            $content = is_file($path) ? file_get_contents($path) : false;
            if ($content !== false) {
                $result[$path] = $rewriter->rewrite($content);
            }
        }

        return $result;
    }

    /**
     * Files whose content changes when the domain is replaced, grouped by the rule that found them.
     *
     * @return array<string, array<string, array{content: string, urls: int}>> label → absolute path → new content and URL count
     */
    private function textGroups(Context $context): array
    {
        $request = State::request($context);
        $matcher = $request->urlMatcher();
        if ($matcher === null) {
            return [];
        }
        $root = State::source($context)->root;
        $config = Resources::json('prestashop9/host-bound-files.json');
        $maxBytes = is_int($config['maxFileBytes'] ?? null) ? $config['maxFileBytes'] : 52428800;

        $groups = [];
        $consider = function (string $label, string $path) use (&$groups, $matcher, $maxBytes): void {
            if (!is_file($path) || is_link($path) || filesize($path) > $maxBytes) {
                return;
            }
            $content = file_get_contents($path);
            if ($content === false || str_contains($content, "\0")) {
                return;
            }
            $stats = new Stats();
            $new = $matcher->rewrite($content, false, $stats);
            if ($new !== $content) {
                $groups[$label][$path] = ['content' => $new, 'urls' => $stats->urlsReplaced];
            }
        };

        foreach (Resources::strings('prestashop9/host-bound-files.json', 'files') as $pattern) {
            foreach (glob($root . '/' . $pattern) ?: [] as $path) {
                $consider($pattern, $path);
            }
        }
        $directories = $config['directories'] ?? [];
        foreach (is_array($directories) ? $directories : [] as $entry) {
            if (!is_array($entry) || !is_string($entry['glob'] ?? null) || !is_array($entry['extensions'] ?? null)) {
                continue;
            }
            $label = $entry['glob'] . '/**';
            foreach (glob($root . '/' . $entry['glob'], GLOB_ONLYDIR) ?: [] as $directory) {
                foreach ($this->filesBelow($directory, array_values(array_map('strval', $entry['extensions']))) as $path) {
                    $consider($label, $path);
                }
            }
        }

        return $groups;
    }

    /**
     * @param list<string> $extensions
     *
     * @return iterable<string>
     */
    private function filesBelow(string $directory, array $extensions): iterable
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if ($file->isFile() && !$file->isLink() && in_array(strtolower($file->getExtension()), $extensions, true)) {
                yield $file->getPathname();
            }
        }
    }
}
