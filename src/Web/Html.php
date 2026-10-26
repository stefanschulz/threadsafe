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
     * @param string|null          $step  the current step (a key of $steps) for the step list, null on the pages around the steps
     * @param string               $name  what the page belongs to ("restore", "installer"): the heading and the title
     * @param array<string, string> $steps the steps by key and label; default: those of the restore wizard
     */
    public static function page(string $title, string $body, string $nonce, ?string $step = null, string $script = '', string $name = 'restore', array $steps = self::STEPS): string
    {
        $list = '';
        if ($step !== null) {
            foreach ($steps as $key => $label) {
                $class = $key === $step ? ' class="current"' : '';
                $list .= '<li' . $class . '>' . self::e($label) . '</li>';
            }
            $list = '<ol class="steps">' . $list . '</ol>';
        }
        $scriptTag = $script === '' ? '' : '<script nonce="' . self::e($nonce) . '">' . $script . '</script>';

        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow"><title>' . self::e($title) . ' - ThreadSafe ' . self::e($name) . '</title><link rel="icon" type="image/svg+xml" href="' . Logo::faviconUri() . '">'
            . '<style nonce="' . self::e($nonce) . '">' . self::css() . '</style></head><body><main>'
            . '<h1>' . Logo::svg() . '<span>ThreadSafe ' . self::e($name) . '</span></h1>' . $list . '<h2>' . self::e($title) . '</h2>' . $body . '</main>' . $scriptTag . '</body></html>';
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

    /**
     * The style of the wizard: the palette of the web interface (docs/DESIGN.md), in the same tokens, light and dark by the system's choice.
     * The wizard is one file with no other file next to it, so the style is inline.
     */
    private static function css(): string
    {
        return ':root{--ink:#eef0f2;--panel:#fff;--panel-2:#f6f7f8;--recess:#e5e8eb;--border:#cdd3d9;--border-strong:#7f8a95;--text:#14191f;--text-soft:#434e58;--text-faint:#56616b;'
            . '--brass:#8a5a08;--brass-bright:#6b4506;--brass-fill:#e0a13d;--brass-fill-hover:#f0b75a;--brass-edge:#a8740f;--on-brass:#14191f;--ok:#1a7339;--warning:#8f4a00;--error:#b3382c;'
            . '--sans:"IBM Plex Sans",ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;--mono:"IBM Plex Mono",ui-monospace,"Cascadia Mono",Consolas,monospace}'
            . '@media(prefers-color-scheme:dark){:root{--ink:#14191f;--panel:#1b232c;--panel-2:#212a33;--recess:#0f1318;--border:#303b47;--border-strong:#6a7784;--text:#e9ebe8;--text-soft:#9aa2a8;'
            . '--text-faint:#838b92;--brass:#e0a13d;--brass-bright:#f0b75a;--brass-edge:#e0a13d;--ok:#5cb87a;--warning:#e6c84f;--error:#e87065}}'
            . 'body{font:16px/1.55 var(--sans);margin:0;background:var(--ink);color:var(--text)}main{max-width:46rem;margin:0 auto;padding:1.5rem 1rem 4rem}'
            . 'a{color:var(--brass);text-underline-offset:.18em}a:hover{color:var(--brass-bright)}'
            . 'a:focus-visible,button:focus-visible,input:focus-visible,select:focus-visible{outline:2px solid var(--brass);outline-offset:2px}'
            . 'h1{display:flex;align-items:center;gap:.65rem;font:600 .85rem/1.4 var(--mono);letter-spacing:.2em;text-transform:uppercase;margin:0 0 .5rem;color:var(--text)}.logo{flex:none;width:2.25rem;height:2.25rem;border-radius:6px}h2{font-size:1.375rem;font-weight:600;line-height:1.25;margin:1rem 0;color:var(--text)}'
            . '.steps{display:flex;gap:.25rem;flex-wrap:wrap;list-style:none;padding:0;margin:0}'
            . '.steps li{padding:.2rem .6rem;border-radius:4px;border:1px solid var(--border);background:var(--panel);color:var(--text-soft);font:500 .7rem/1.5 var(--mono);letter-spacing:.06em;text-transform:uppercase}'
            . '.steps li.current{background:var(--brass-fill);border-color:var(--brass-edge);color:var(--on-brass)}'
            . 'label{display:block;margin:.8rem 0 .2rem;font-weight:600}input[type=text],input[type=password],select{width:100%;box-sizing:border-box;padding:.5rem;font:inherit;color:var(--text);background:var(--recess);border:1px solid var(--border-strong);border-radius:6px}'
            . 'input[type=text]:focus,input[type=password]:focus,select:focus{border-color:var(--brass)}input[type=checkbox]{margin-right:.4rem;accent-color:var(--brass-fill)}::placeholder{color:var(--text-faint);opacity:1}'
            . 'button,.button{font:600 .78rem/1.4 var(--mono);letter-spacing:.06em;text-transform:uppercase;padding:.55rem 1.1rem;border:1px solid var(--brass-edge);border-radius:6px;background:var(--brass-fill);color:var(--on-brass);cursor:pointer;margin:.3rem .3rem .3rem 0;text-decoration:none;display:inline-block}'
            . 'button:hover,.button:hover{background:var(--brass-fill-hover);color:var(--on-brass)}'
            . 'button.secondary,.button.secondary{background:var(--panel-2);border-color:var(--border-strong);color:var(--text)}button.secondary:hover,.button.secondary:hover{background:var(--panel-2);border-color:var(--brass);color:var(--text)}'
            . '.help,.hint{color:var(--text-soft);font-size:.88rem}.checks{padding-left:1.2rem}.checks .error{color:var(--error)}.checks .warning{color:var(--warning)}'
            . '.box{background:var(--panel);border:1px solid var(--border);border-radius:8px;padding:.8rem 1rem;margin:1rem 0}.bar{height:1.2rem;background:var(--recess);border:1px solid var(--border);border-radius:4px;overflow:hidden}'
            . '.bar div{height:100%;width:0;background:var(--brass-fill);transition:width .3s}.fail{color:var(--error)}table{border-collapse:collapse;width:100%;background:var(--panel)}'
            . 'td,th{text-align:left;padding:.25rem .5rem;border-bottom:1px solid var(--border);vertical-align:top}th{font:500 .7rem/1.5 var(--mono);letter-spacing:.1em;text-transform:uppercase;color:var(--text-faint)}'
            . 'code{background:var(--recess);border:1px solid var(--border);padding:.05rem .3rem;border-radius:4px;font:.88em var(--mono);word-break:break-all}.ok{color:var(--ok)}@media(prefers-reduced-motion:reduce){*{transition:none!important}}';
    }

    private function __construct()
    {
    }
}
