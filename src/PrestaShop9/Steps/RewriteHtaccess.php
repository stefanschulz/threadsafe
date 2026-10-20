<?php

declare(strict_types=1);

namespace ThreadSafe\PrestaShop9\Steps;

use ThreadSafe\Core\AtomicFile;
use ThreadSafe\Core\Change;
use ThreadSafe\Core\Context;
use ThreadSafe\Core\Notice;
use ThreadSafe\Core\Plan;
use ThreadSafe\Core\Step;
use ThreadSafe\Core\StepResult;
use ThreadSafe\PrestaShop9\HtaccessRewriter;
use ThreadSafe\PrestaShop9\State;

/**
 * Brings the `.htaccess` in line with the new domain and base path. The friendly image URLs of PrestaShop are tied to
 * the domain (`RewriteCond %{HTTP_HOST} ^<domain>$`); with the old domain left in the file, product images silently
 * stop working after a move.
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
        return 'Adapt .htaccess to the new domain and base path';
    }

    public function plan(Context $context): Plan
    {
        $plan = new Plan();
        $rewrite = $this->compute($context);
        if ($rewrite === null) {
            return $plan->notice(Notice::info('There is no .htaccess (e.g. a web server other than Apache); nothing to adapt.'));
        }

        if ($rewrite[1]->changedLines > 0) {
            $plan->add(Change::update('file ' . self::FILE, 'set the new domain and base path', count: $rewrite[1]->changedLines));
        }
        foreach ($rewrite[1]->warnings as $warning) {
            $plan->notice(Notice::warning($warning, self::FILE));
        }

        return $plan;
    }

    public function apply(Context $context, Plan $plan): StepResult
    {
        $rewrite = $this->compute($context);
        if ($rewrite === null || $rewrite[1]->changedLines === 0) {
            return new StepResult(0);
        }

        [$path, $result] = $rewrite;
        $backup = $context->journal->backupFile($path);
        AtomicFile::write($path, $result->content);
        $context->journal->file($path, 'update', $backup);

        return new StepResult($result->changedLines);
    }

    /**
     * @return array{string, \ThreadSafe\PrestaShop9\HtaccessRewrite}|null path and result, null if there is no file
     */
    private function compute(Context $context): ?array
    {
        $request = State::request($context);
        $path = State::source($context)->root . '/' . self::FILE;
        $content = is_file($path) ? file_get_contents($path) : false;
        if ($content === false) {
            return null;
        }

        return [$path, (new HtaccessRewriter($request->domains, $request->physicalUri))->rewrite($content)];
    }
}
