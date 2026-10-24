<?php

declare(strict_types=1);

namespace ThreadSafe\Joomla\Steps;

use ThreadSafe\Core\AtomicFile;
use ThreadSafe\Core\Change;
use ThreadSafe\Core\Context;
use ThreadSafe\Core\Notice;
use ThreadSafe\Core\Plan;
use ThreadSafe\Core\Step;
use ThreadSafe\Core\StepResult;
use ThreadSafe\Joomla\HtaccessRewriter;
use ThreadSafe\Joomla\State;

/**
 * Brings the `.htaccess` in line with the new base path (see {@see HtaccessRewriter}). Only when the request names a base path: without
 * one the site stays where it was and the file is right as it is. Without an `.htaccess` (Joomla works without one while the search-engine
 * friendly addresses are off, and on other web servers) there is nothing to adapt.
 */
final class RewriteHtaccess implements Step
{
    private const string FILE = '.htaccess';

    public function id(): string
    {
        return 'rewrite-htaccess';
    }

    public function title(): string
    {
        return 'Adapt .htaccess to the new base path';
    }

    public function plan(Context $context): Plan
    {
        $plan = new Plan();
        $request = State::request($context);
        if ($request->physicalUri === null) {
            return $plan->notice(Notice::info('No new base path given: the .htaccess stays as it is.'));
        }
        $result = $this->compute($context);
        if ($result === null) {
            return $plan->notice(Notice::info('There is no .htaccess (Joomla needs none while its search-engine friendly addresses are off, and other web servers have their own rules); nothing to adapt.'));
        }
        if ($result[1]['changes'] > 0) {
            $plan->add(Change::update('file ' . self::FILE, 'set the new base path', count: $result[1]['changes']));
        }
        foreach ($result[1]['warnings'] as $warning) {
            $plan->notice(Notice::warning($warning, self::FILE));
        }

        return $plan;
    }

    public function apply(Context $context, Plan $plan): StepResult
    {
        $result = $this->compute($context);
        if ($result === null || $result[1]['changes'] === 0) {
            return new StepResult(0);
        }
        [$path, $rewrite] = $result;
        $backup = $context->journal->backupFile($path);
        AtomicFile::write($path, $rewrite['content']);
        $context->journal->file($path, 'update', $backup);

        return new StepResult($rewrite['changes']);
    }

    /**
     * @return array{0: string, 1: array{content: string, changes: int, warnings: list<string>}}|null path and result; null if there is
     *                                                                                             no file or no base path was asked for
     */
    private function compute(Context $context): ?array
    {
        $base = State::request($context)->physicalUri;
        $path = State::source($context)->root . '/' . self::FILE;
        $content = $base !== null && is_file($path) ? file_get_contents($path) : false;
        if ($content === false) {
            return null;
        }

        return [$path, (new HtaccessRewriter())->rewrite($content, $base)];
    }
}
