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
        $rows = [];
        foreach ($checks as $check) {
            $rows[] = ['level' => $check->level, 'subject' => '', 'message' => $check->message, 'hint' => $check->hint];
        }

        return self::checkTable($rows);
    }

    /**
     * The findings as a table: a symbol and its written name in the first column (the level is never only a colour), the finding next to it.
     * The symbols are drawn with presentation attributes only; the page allows no style attribute.
     *
     * @param list<array{level: string, subject: string, message: string, hint: string}> $rows level: ok, info, warning or error
     */
    public static function checkTable(array $rows): string
    {
        $names = ['ok' => 'OK', 'info' => 'Note', 'warning' => 'Warning', 'error' => 'Problem'];
        $html = '<table class="checks"><thead><tr><th scope="col">Result</th><th scope="col">Finding</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $level = isset($names[$row['level']]) ? $row['level'] : 'info';
            $subject = $row['subject'] !== '' ? '<strong>' . self::e($row['subject']) . ':</strong> ' : '';
            $html .= '<tr class="' . $level . '"><td class="level">' . self::symbol($level) . '<span>' . $names[$level] . '</span></td><td>' . $subject . self::e($row['message'])
                . ($row['hint'] !== '' ? ' <span class="hint">' . self::e($row['hint']) . '</span>' : '') . '</td></tr>';
        }

        return $html . '</tbody></table>';
    }

    /**
     * The symbol of a level: a circle with a check, a circle with an "i", a triangle with an exclamation mark, a circle with a cross.
     */
    public static function symbol(string $level): string
    {
        $shape = match ($level) {
            'ok' => '<circle cx="12" cy="12" r="9"/><path d="M7.5 12.5l3 3 6-6.5"/>',
            'warning' => '<path d="M12 3.5l9.5 16.5h-19z"/><path d="M12 10v4.5M12 17.4v.2"/>',
            'error' => '<circle cx="12" cy="12" r="9"/><path d="M8.7 8.7l6.6 6.6M15.3 8.7l-6.6 6.6"/>',
            default => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5.5M12 7.9v.2"/>',
        };

        return '<svg class="sym" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $shape . '</svg>';
    }

    /**
     * The style of the wizard: the palette of the web interface (docs/DESIGN.md), in the same tokens, light and dark by the system's choice.
     * The wizard is one file with no other file next to it, so the style is inline.
     */
    public static function css(): string
    {
        return ':root{--ink:#eef0f2;--panel:#fff;--panel-2:#f6f7f8;--recess:#e5e8eb;--border:#cdd3d9;--border-strong:#7f8a95;--text:#14191f;--text-soft:#434e58;--text-faint:#56616b;'
            . '--brass:#8a5a08;--brass-bright:#6b4506;--brass-fill:#e0a13d;--brass-fill-hover:#f0b75a;--brass-edge:#a8740f;--on-brass:#14191f;--ok:#1a7339;--warning:#8f4a00;--error:#b3382c;'
            . '--sans:"IBM Plex Sans",ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;--mono:"IBM Plex Mono",ui-monospace,"Cascadia Mono",Consolas,monospace}'
            . '@media(prefers-color-scheme:dark){:root{--ink:#14191f;--panel:#1b232c;--panel-2:#212a33;--recess:#0f1318;--border:#303b47;--border-strong:#6a7784;--text:#e9ebe8;--text-soft:#9aa2a8;'
            . '--text-faint:#838b92;--brass:#e0a13d;--brass-bright:#f0b75a;--brass-edge:#e0a13d;--ok:#5cb87a;--warning:#e6c84f;--error:#e87065}}'
            . 'body{font:16px/1.55 var(--sans);margin:0;background:var(--ink);color:var(--text)}main{max-width:46rem;margin:0 auto;padding:1.5rem 1rem 4rem}'
            . 'a{color:var(--brass);text-underline-offset:.18em}a:hover{color:var(--brass-bright)}'
            . 'a:focus-visible,button:focus-visible,input:focus-visible,select:focus-visible,textarea:focus-visible{outline:2px solid var(--brass);outline-offset:2px}'
            . 'h1{display:flex;align-items:center;gap:.65rem;font:600 .85rem/1.4 var(--mono);letter-spacing:.2em;text-transform:uppercase;margin:0 0 .5rem;color:var(--text)}.logo{flex:none;width:2.25rem;height:2.25rem;border-radius:6px}h2{font-size:1.375rem;font-weight:600;line-height:1.25;margin:1rem 0;color:var(--text)}'
            . '.steps{display:flex;gap:.25rem;flex-wrap:wrap;list-style:none;padding:0;margin:0}'
            . '.steps li{padding:.2rem .6rem;border-radius:4px;border:1px solid var(--border);background:var(--panel);color:var(--text-soft);font:500 .7rem/1.5 var(--mono);letter-spacing:.06em;text-transform:uppercase}'
            . '.steps li.current{background:var(--brass-fill);border-color:var(--brass-edge);color:var(--on-brass)}'
            . 'label{display:block;margin:.8rem 0 .2rem;font-weight:600}input[type=text],input[type=password],select,textarea{width:100%;box-sizing:border-box;padding:.5rem;font:inherit;color:var(--text);background:var(--recess);border:1px solid var(--border-strong);border-radius:6px}'
            . 'input[type=text]:focus,input[type=password]:focus,select:focus,textarea:focus{border-color:var(--brass)}input[type=checkbox]{margin-right:.4rem;accent-color:var(--brass-fill)}::placeholder{color:var(--text-faint);opacity:1}'
            . 'button,.button{font:600 .78rem/1.4 var(--mono);letter-spacing:.06em;text-transform:uppercase;padding:.55rem 1.1rem;border:1px solid var(--brass-edge);border-radius:6px;background:var(--brass-fill);color:var(--on-brass);cursor:pointer;margin:.3rem .3rem .3rem 0;text-decoration:none;display:inline-block}'
            . 'button:hover,.button:hover{background:var(--brass-fill-hover);color:var(--on-brass)}'
            . 'button.secondary,.button.secondary{background:var(--panel-2);border-color:var(--border-strong);color:var(--text)}button.secondary:hover,.button.secondary:hover{background:var(--panel-2);border-color:var(--brass);color:var(--text)}'
            . '.tiles{display:grid;gap:.75rem;margin:.6rem 0 1.2rem}.tile{position:relative;background:var(--panel);border:1px solid var(--border);border-radius:8px;padding:.85rem 1rem .85rem 2.7rem}.tile:has(input[name=place]:checked){border-color:var(--brass-edge);box-shadow:inset 0 0 0 1px var(--brass-edge);background:var(--panel-2)}.tile.off{background:var(--panel-2);color:var(--text-soft)}.tile label.pick{display:flex;align-items:center;flex-wrap:wrap;gap:.2rem .6rem;margin:0;font-weight:600;cursor:pointer}.tile.off label.pick{cursor:default}.badge.off{border-style:dashed}.tile label.pick::after{content:"";position:absolute;inset:0;border-radius:8px}.tile input[type=radio]{appearance:none;-webkit-appearance:none;position:absolute;left:1rem;top:1.05rem;margin:0;width:1.05rem;height:1.05rem;border:2px solid var(--border-strong);border-radius:50%;background:var(--panel)}.tile input[type=radio]:checked{border-color:var(--brass-edge);background:radial-gradient(circle,var(--brass-fill) 0 40%,var(--panel) 45%)}.tile input[type=radio]:disabled{border-color:var(--border);background:var(--recess)}.tile input[type=radio]:focus-visible{outline:2px solid var(--brass);outline-offset:2px}.tile .path{display:block;margin:.4rem 0 .1rem}.tile p{margin:.35rem 0 0}.tile .confirm{position:relative;z-index:1;display:flex;gap:.5rem;align-items:flex-start;margin:.7rem 0 0;padding:.5rem .7rem;font-weight:400;background:var(--recess);border:1px solid var(--border);border-radius:6px}.tile input[type=checkbox]{margin-top:.25rem}.tile input[type=text]{position:relative;z-index:1;margin-top:.5rem}.badge{font:600 .66rem/1 var(--mono);letter-spacing:.08em;text-transform:uppercase;padding:.22rem .45rem;border-radius:4px;border:1px solid var(--border-strong);color:var(--text-soft)}.badge.rec{background:var(--brass-fill);border-color:var(--brass-edge);color:var(--on-brass)}.badge.weak{border-color:var(--warning);color:var(--warning)}.help,.hint{color:var(--text-soft);font-size:.88rem}'
            . 'table.checks td.level{white-space:nowrap;width:1%;font:600 .7rem/1.5 var(--mono);letter-spacing:.08em;text-transform:uppercase;color:var(--text-soft)}table.checks td.level .sym{vertical-align:-.3em;margin-right:.45rem}'
            . 'table.checks tr.ok td.level{color:var(--ok)}table.checks tr.info td.level{color:var(--text-soft)}table.checks tr.warning td.level{color:var(--warning)}table.checks tr.error td.level{color:var(--error)}'
            . '.box{background:var(--panel);border:1px solid var(--border);border-radius:8px;padding:.8rem 1rem;margin:1rem 0}.bar{height:1.2rem;background:var(--recess);border:1px solid var(--border);border-radius:4px;overflow:hidden}'
            . '.bar div{height:100%;width:0;background:var(--brass-fill);transition:width .3s}.fail{color:var(--error)}button:disabled{opacity:.45;cursor:not-allowed;filter:grayscale(.4)}.clock{font:.85rem var(--mono);margin:.2rem 0 1rem}label.danger{color:var(--error)}table{border-collapse:collapse;width:100%;background:var(--panel)}'
            . 'td,th{text-align:left;padding:.25rem .5rem;border-bottom:1px solid var(--border);vertical-align:top}th{font:500 .7rem/1.5 var(--mono);letter-spacing:.1em;text-transform:uppercase;color:var(--text-faint)}'
            . '.copyrow{position:relative;margin:1rem 0}.copyrow pre{margin:0;padding-right:3rem;white-space:pre-wrap;overflow-wrap:anywhere}@media(max-width:30rem){.copyrow pre{font-size:.9rem}}'
            . '.copyrow button.copy{position:absolute;top:50%;transform:translateY(-50%);right:.35rem;width:2rem;height:2rem;margin:0;padding:0;display:flex;align-items:center;justify-content:center;background:transparent;border:1px solid transparent;color:var(--text-soft)}'
            . '.copyrow button.copy:hover,.copyrow button.copy:focus-visible{background:var(--panel-2);border-color:var(--border-strong);color:var(--text)}'
            . 'pre{background:var(--recess);border:1px solid var(--border);border-radius:6px;padding:.7rem .9rem;overflow-x:auto}pre code{background:none;border:0;padding:0;word-break:normal}'
            . 'code{background:var(--recess);border:1px solid var(--border);padding:.05rem .3rem;border-radius:4px;font:.88em var(--mono);word-break:break-all}.ok{color:var(--ok)}@media(prefers-reduced-motion:reduce){*{transition:none!important}}';
    }

    private function __construct()
    {
    }
}
