<?php

declare(strict_types=1);

namespace ThreadSafe\Web;

use ThreadSafe\Restore\Check;

/**
 * The little HTML the wizard needs. Everything that comes from outside - file names, messages of the database, values the person
 * typed - goes through {@see e()}; nothing is concatenated into a page in any other way.
 */
final class Html
{
    public const array STEPS = ['backup' => 'Backup', 'requirements' => 'Requirements', 'target' => 'Target', 'summary' => 'Summary', 'run' => 'Restore', 'done' => 'Done'];

    public static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /**
     * @param string|null $step the current step (a key of {@see STEPS}) for the step list, null on the pages around the steps
     */
    public static function page(string $title, string $body, string $nonce, ?string $step = null, string $script = ''): string
    {
        $steps = '';
        if ($step !== null) {
            foreach (self::STEPS as $key => $label) {
                $class = $key === $step ? ' class="current"' : '';
                $steps .= '<li' . $class . '>' . self::e($label) . '</li>';
            }
            $steps = '<ol class="steps">' . $steps . '</ol>';
        }
        $scriptTag = $script === '' ? '' : '<script nonce="' . self::e($nonce) . '">' . $script . '</script>';

        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow"><title>' . self::e($title) . ' - ThreadSafe restore</title>'
            . '<style nonce="' . self::e($nonce) . '">' . self::css() . '</style></head><body><main>'
            . '<h1>ThreadSafe restore</h1>' . $steps . '<h2>' . self::e($title) . '</h2>' . $body . '</main>' . $scriptTag . '</body></html>';
    }

    /**
     * @param list<Check> $checks
     */
    public static function checks(array $checks): string
    {
        if ($checks === []) {
            return '<p class="ok">Nothing to report.</p>';
        }
        $html = '<ul class="checks">';
        foreach ($checks as $check) {
            $label = match ($check->level) {
                Check::ERROR => 'Problem',
                Check::WARNING => 'Warning',
                default => 'Note',
            };
            $html .= '<li class="' . self::e($check->level) . '"><strong>' . $label . ':</strong> ' . self::e($check->message)
                . ($check->hint !== '' ? ' <span class="hint">' . self::e($check->hint) . '</span>' : '') . '</li>';
        }

        return $html . '</ul>';
    }

    private static function css(): string
    {
        return 'body{font:16px/1.5 system-ui,sans-serif;margin:0;background:#f4f5f7;color:#1b1f23}main{max-width:46rem;margin:0 auto;padding:1.5rem 1rem 4rem}'
            . 'h1{font-size:1.1rem;margin:0 0 .5rem;color:#586069}h2{margin:1rem 0}.steps{display:flex;gap:.25rem;flex-wrap:wrap;list-style:none;padding:0;margin:0}'
            . '.steps li{padding:.2rem .6rem;border-radius:1rem;background:#e1e4e8;font-size:.85rem}.steps li.current{background:#0366d6;color:#fff}'
            . 'label{display:block;margin:.8rem 0 .2rem;font-weight:600}input[type=text],input[type=password],select{width:100%;box-sizing:border-box;padding:.5rem;font:inherit;border:1px solid #c6cbd1;border-radius:4px}'
            . 'input[type=checkbox]{margin-right:.4rem}button,.button{font:inherit;padding:.55rem 1.1rem;border:0;border-radius:4px;background:#0366d6;color:#fff;cursor:pointer;margin:.3rem .3rem .3rem 0}'
            . 'button.secondary{background:#6a737d}.help,.hint{color:#586069;font-size:.88rem}.checks{padding-left:1.2rem}.checks .error{color:#b31d28}.checks .warning{color:#8a5a00}'
            . '.box{background:#fff;border:1px solid #d1d5da;border-radius:6px;padding:.8rem 1rem;margin:1rem 0}.bar{height:1.2rem;background:#e1e4e8;border-radius:.6rem;overflow:hidden}'
            . '.bar div{height:100%;width:0;background:#28a745;transition:width .3s}.fail{color:#b31d28}table{border-collapse:collapse;width:100%}td,th{text-align:left;padding:.25rem .5rem;border-bottom:1px solid #e1e4e8;vertical-align:top}'
            . 'code{background:#e8eaed;padding:.05rem .3rem;border-radius:3px;word-break:break-all}.ok{color:#22863a}';
    }

    private function __construct()
    {
    }
}
