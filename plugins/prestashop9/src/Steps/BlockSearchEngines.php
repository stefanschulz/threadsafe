<?php

declare(strict_types=1);

namespace ThreadSafe\PrestaShop9\Steps;

use InvalidArgumentException;
use ThreadSafe\Core\AtomicFile;
use ThreadSafe\Core\Change;
use ThreadSafe\Core\Context;
use ThreadSafe\Core\Exception\GuardFailed;
use ThreadSafe\Core\Notice;
use ThreadSafe\Core\Plan;
use ThreadSafe\Core\Step;
use ThreadSafe\Core\StepResult;
use ThreadSafe\PrestaShop9\DevMode\DevModeRules;
use ThreadSafe\PrestaShop9\State;

/**
 * Dev mode, file part: keeps search engines out of the copy. `robots.txt` forbids everything and a block in
 * `.htaccess` sends `X-Robots-Tag: noindex, nofollow` with every response (what robots.txt cannot do: a page that
 * is already known to a search engine is only removed by a noindex).
 *
 * The block sits outside PrestaShop's own `# ~~start~~` … `# ~~end~~` markers, so regenerating `.htaccess` in the back
 * office keeps it. The texts come from the `noindex` entry of the dev rules.
 */
final class BlockSearchEngines implements Step
{
    private const string START = '# ~~threadsafe-dev-start~~';

    private const string END = '# ~~threadsafe-dev-end~~';

    public function id(): string
    {
        return 'block-search-engines';
    }

    public function title(): string
    {
        return 'Dev mode: keep search engines out (robots.txt, X-Robots-Tag)';
    }

    public function plan(Context $context): Plan
    {
        $plan = new Plan();
        $wanted = $this->wanted($context);
        if ($wanted === null) {
            return $plan;
        }

        $root = State::source($context)->root;
        foreach ($this->files($root, $wanted) as $name => [$old, $new]) {
            if ($old === $new) {
                continue;
            }
            $plan->add($old === null
                ? Change::create("file $name", 'create it (search engines are kept out)')
                : Change::update("file $name", 'keep search engines out'));
        }
        if (!is_file($root . '/.htaccess')) {
            $plan->notice(Notice::info('There is no .htaccess (e.g. a web server other than Apache): only robots.txt keeps search engines out. Send "X-Robots-Tag: ' . $wanted['header'] . '" from the web server configuration.'));
        }

        return $plan;
    }

    public function apply(Context $context, Plan $plan): StepResult
    {
        $wanted = $this->wanted($context);
        if ($wanted === null) {
            return new StepResult(0);
        }

        $root = State::source($context)->root;
        $applied = 0;
        foreach ($this->files($root, $wanted) as $name => [$old, $new]) {
            if ($old === $new) {
                continue;
            }
            $path = $root . '/' . $name;
            $backup = $context->journal->backupFile($path);
            AtomicFile::write($path, $new);
            $context->journal->file($path, $old === null ? 'create' : 'update', $backup);
            ++$applied;
        }

        return new StepResult($applied);
    }

    /**
     * @return array{robots: string, header: string}|null null when this is no dev run or no active rule wants it
     */
    private function wanted(Context $context): ?array
    {
        $request = State::request($context);
        if (!$request->isDevMode()) {
            return null;
        }
        try {
            $rules = DevModeRules::load($request->devRulesFile)->activeFor($request, State::moduleRules($context)->knownNames());
        } catch (InvalidArgumentException $e) {
            throw new GuardFailed($e->getMessage(), 0, $e);
        }
        foreach ($rules as $rule) {
            if ($rule->noindex !== null) {
                return $rule->noindex;
            }
        }

        return null;
    }

    /**
     * @param array{robots: string, header: string} $wanted
     *
     * @return array<string, array{string|null, string}> file name → current and wanted content (current null: no file)
     */
    private function files(string $root, array $wanted): array
    {
        $files = [];

        $robots = is_file($root . '/robots.txt') ? file_get_contents($root . '/robots.txt') : false;
        $files['robots.txt'] = [$robots === false ? null : $robots, $wanted['robots']];

        $htaccess = is_file($root . '/.htaccess') ? file_get_contents($root . '/.htaccess') : false;
        if ($htaccess !== false) {
            $files['.htaccess'] = [$htaccess, $this->withBlock($htaccess, $wanted['header'])];
        }

        return $files;
    }

    /** The `.htaccess` content with exactly one header block at the very top. */
    private function withBlock(string $content, string $header): string
    {
        $block = self::START . " Written by ThreadSafe (dev mode); delete the block to let search engines in.\n"
            . "<IfModule mod_headers.c>\n"
            . '    Header set X-Robots-Tag "' . addcslashes($header, '"\\') . "\"\n"
            . "</IfModule>\n"
            . self::END . "\n";

        $without = preg_replace('/' . preg_quote(self::START, '/') . '.*?' . preg_quote(self::END, '/') . "\r?\n?/s", '', $content) ?? $content;

        return $block . "\n" . ltrim($without, "\r\n");
    }
}
