<?php

declare(strict_types=1);

namespace ThreadSafe\Admin;

use ThreadSafe\Core\Version;
use ThreadSafe\Profile\Check;
use ThreadSafe\Profile\TestReport;
use ThreadSafe\Web\Html;
use ThreadSafe\Web\Response;

/**
 * The frame around every page: head, navigation, messages, footer. The pages bring their own body as HTML that was built with
 * {@see Html::e()} for everything that comes from outside; nothing here puts text into a page in any other way. There is no inline
 * script or style (the content security policy forbids both): the page links `app.css` and `app.js` and carries the CSRF token in
 * a `<meta>` element for the script.
 *
 * The menu is a bar on the left with an icon and a name per entry; it can be folded to the icons, and the colour scheme can be set to
 * automatic (the system's), light or dark. Both choices are kept in cookies (`threadsafe_nav`, `threadsafe_theme`) that the script sets and
 * this class reads, so that the page is drawn right from the first byte: no flash of the other scheme, no script in the head.
 */
final class View
{
    /** The entries of the navigation: route => [label, icon]. */
    private const array NAVIGATION = ['/' => ['Dashboard', 'dashboard'], '/profiles' => ['Profiles', 'profiles'], '/system' => ['System', 'system']];

    /** The settings of the colour scheme, in the order of the switch: value => [label, icon]. */
    private const array THEMES = ['auto' => ['Auto (follows the system)', 'auto'], 'light' => ['Light', 'light'], 'dark' => ['Dark', 'dark']];

    /**
     * A page for a logged-in user.
     *
     * @param string $active the route of the navigation entry to mark as current
     * @param string $actions the main action of the page as HTML (a button), shown at the right of the title
     */
    public static function page(Context $context, string $title, string $body, string $active = '/', int $status = 200, string $actions = ''): Response
    {
        // the main action of a page (HTML) stands at the right of the title
        $heading = '<h1>' . Html::e($title) . '</h1>';
        if ($actions !== '') {
            $heading = '<div class="page-head">' . $heading . '<div class="page-actions">' . $actions . '</div></div>';
        }
        $navigation = '';
        foreach (self::NAVIGATION as $route => [$label, $icon]) {
            $navigation .= '<a href="' . Html::e($context->urls->to($route)) . '" title="' . Html::e($label) . '"' . ($route === $active ? ' aria-current="page"' : '') . '>'
                . Icons::svg($icon) . '<span class="side-label">' . Html::e($label) . '</span></a>';
        }
        $collapsed = self::collapsed($context);
        $user = Html::e($context->user() ?? '');
        $side = '<aside class="side" aria-label="Menu" data-cookie-path="' . Html::e($context->urls->cookiePath()) . '">'
            . '<div class="side-head"><a class="mark" href="' . Html::e($context->urls->to('/')) . '" title="ThreadSafe"><span aria-hidden="true">TS</span><span class="side-label">ThreadSafe</span></a></div>'
            . '<div class="side-menu"><button type="button" class="side-toggle js-only" data-nav-toggle aria-expanded="' . ($collapsed ? 'false' : 'true') . '" aria-controls="side-nav" title="' . ($collapsed ? 'Unfold the menu' : 'Fold the menu') . '">'
            . Icons::svg('chevron') . '<span class="side-label">' . ($collapsed ? 'Unfold the menu' : 'Fold the menu') . '</span></button>'
            . '<nav id="side-nav" aria-label="Main">' . $navigation . '</nav></div>'
            . '<div class="side-foot">' . self::themeSwitch($context)
            . self::form($context, '/logout', '<button class="side-link" type="submit" title="Log out (' . $user . ')">' . Icons::svg('logout') . '<span class="side-label">Log out (' . $user . ')</span></button>', 'logout')
            . '</div></aside>';

        return self::document(
            $context,
            $title,
            '<a class="skip" href="#content">Skip to the content</a><div class="app">' . $side . '<div class="content"><main id="content" tabindex="-1">' . self::flashes($context) . $heading . $body . '</main>' . self::footer() . '</div></div>',
            $status,
            true,
        );
    }

    /**
     * A page before the login (the login itself, the setup, the explanations).
     */
    public static function bare(Context $context, string $title, string $body, int $status = 200): Response
    {
        $header = '<header class="bar"><span class="mark"><span aria-hidden="true">TS</span><span class="side-label">ThreadSafe</span></span>' . self::themeSwitch($context) . '</header>';

        return self::document($context, $title, '<a class="skip" href="#content">Skip to the content</a>' . $header . '<main class="narrow" id="content" tabindex="-1">' . self::flashes($context) . '<h1>' . Html::e($title) . '</h1>' . $body . '</main>' . self::footer(), $status);
    }

    /** The cookie of the colour scheme: `light` or `dark`; anything else (no cookie, a forged one) is automatic. */
    private static function theme(Context $context): string
    {
        $value = $context->request->cookie('threadsafe_theme');

        return $value === 'light' || $value === 'dark' ? $value : 'auto';
    }

    private static function collapsed(Context $context): bool
    {
        return $context->request->cookie('threadsafe_nav') === 'collapsed';
    }

    /** Three buttons: automatic, light, dark. The script sets the scheme and the cookie; without script they are not shown. */
    private static function themeSwitch(Context $context): string
    {
        $current = self::theme($context);
        $buttons = '';
        foreach (self::THEMES as $value => [$label, $icon]) {
            $buttons .= '<button type="button" data-theme-set="' . $value . '" aria-pressed="' . ($value === $current ? 'true' : 'false') . '" title="' . Html::e($label) . '">'
                . Icons::svg($icon) . '<span class="side-label">' . Html::e($label) . '</span></button>';
        }

        return '<div class="theme js-only" role="group" aria-label="Colour scheme">' . $buttons . '</div>';
    }

    private static function footer(): string
    {
        return '<footer>ThreadSafe ' . Html::e(Version::CURRENT) . '</footer>';
    }

    /**
     * A form that posts to a route and carries the CSRF token.
     *
     * @param string $inner the fields and buttons, as HTML
     * @param string $class a CSS class for the form
     */
    public static function form(Context $context, string $route, string $inner, string $class = ''): string
    {
        return '<form method="post" action="' . Html::e($context->urls->to($route)) . '"' . ($class !== '' ? ' class="' . Html::e($class) . '"' : '') . '>'
            . '<input type="hidden" name="' . Csrf::FIELD . '" value="' . Html::e($context->csrf()) . '">' . $inner . '</form>';
    }

    /**
     * A labelled input.
     *
     * @param array<string, string> $attributes more attributes (autocomplete, autofocus ...), values are escaped
     */
    public static function field(string $name, string $label, string $type = 'text', string $value = '', array $attributes = [], string $hint = ''): string
    {
        $extra = '';
        foreach ($attributes as $attribute => $content) {
            if (preg_match('/^[a-z-]+$/', $attribute) === 1) {
                $extra .= ' ' . $attribute . '="' . Html::e($content) . '"';
            }
        }

        return '<div class="field"><label for="f-' . Html::e($name) . '">' . Html::e($label) . '</label><input id="f-' . Html::e($name) . '" name="' . Html::e($name) . '" type="' . Html::e($type) . '"'
            . ($type === 'password' ? '' : ' value="' . Html::e($value) . '"') . $extra . '>' . self::hintText($hint) . '</div>';
    }

    /**
     * A multi-line text field.
     */
    public static function textarea(string $name, string $label, string $value = '', int $rows = 4, string $hint = ''): string
    {
        return '<div class="field"><label for="f-' . Html::e($name) . '">' . Html::e($label) . '</label><textarea id="f-' . Html::e($name) . '" name="' . Html::e($name) . '" rows="' . $rows . '">'
            . Html::e($value) . '</textarea>' . self::hintText($hint) . '</div>';
    }

    /**
     * A choice from a list.
     *
     * @param array<string, string> $options value => label
     */
    public static function select(string $name, string $label, array $options, string $selected, string $hint = ''): string
    {
        $html = '';
        foreach ($options as $value => $text) {
            $html .= '<option value="' . Html::e((string) $value) . '"' . ((string) $value === $selected ? ' selected' : '') . '>' . Html::e($text) . '</option>';
        }

        return '<div class="field"><label for="f-' . Html::e($name) . '">' . Html::e($label) . '</label><select id="f-' . Html::e($name) . '" name="' . Html::e($name) . '">' . $html . '</select>' . self::hintText($hint) . '</div>';
    }

    public static function checkbox(string $name, string $label, bool $checked, string $hint = ''): string
    {
        return '<div class="field check"><label><input type="checkbox" name="' . Html::e($name) . '" value="1"' . ($checked ? ' checked' : '') . '> ' . Html::e($label) . '</label>' . self::hintText($hint) . '</div>';
    }

    /**
     * The checks of a connection test as a list; the level is written out, not only coloured.
     */
    public static function report(TestReport $report): string
    {
        return self::checks($report->checks) . '<p class="' . ($report->passed() ? 'flash ok' : 'flash error') . '">'
            . ($report->passed() ? 'The profile can be backed up.' : 'The profile cannot be backed up as it is.') . '</p>';
    }

    /** A plain page for an error before anything else is set up (no session, no addresses). */
    public static function error(string $title, string $message, int $status): Response
    {
        return new Response($status, ['Content-Type: text/html; charset=utf-8'], '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>' . Html::e($title) . ' - ThreadSafe</title>'
            . '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"></head><body><h1>' . Html::e($title) . '</h1><p>' . Html::e($message) . '</p></body></html>');
    }

    /**
     * A list of checks; the level is written out, not only coloured.
     *
     * @param list<Check> $checks
     */
    public static function checks(array $checks): string
    {
        $labels = ['ok' => 'OK', 'info' => 'Info', 'warning' => 'Warning', 'failure' => 'Failed'];
        $html = '<ul class="checks">';
        foreach ($checks as $check) {
            $html .= '<li class="' . Html::e($check->level->value) . '"><strong>' . Html::e($labels[$check->level->value]) . '</strong> '
                . Html::e($check->subject) . ': ' . Html::e($check->message) . '</li>';
        }

        return $html . '</ul>';
    }

    /**
     * The entries of the audit log as a table.
     *
     * @param list<array<string, mixed>> $entries
     */
    public static function audit(array $entries): string
    {
        if ($entries === []) {
            return '<p class="hint">Nothing yet.</p>';
        }
        $rows = '';
        foreach ($entries as $entry) {
            $detail = [];
            foreach (array_diff_key($entry, ['time' => 1, 'event' => 1, 'user' => 1, 'address' => 1]) as $name => $value) {
                $detail[] = $name . ': ' . (is_bool($value) ? ($value ? 'yes' : 'no') : (is_scalar($value) ? (string) $value : ''));
            }
            $time = Html::e((string) ($entry['time'] ?? ''));
            $rows .= '<tr><td><time datetime="' . $time . '">' . $time . '</time></td><td>' . Html::e((string) ($entry['event'] ?? '')) . '</td><td>' . Html::e((string) ($entry['user'] ?? ''))
                . '</td><td>' . Html::e(implode(', ', $detail)) . '</td></tr>';
        }

        return '<div class="scroll"><table><thead><tr><th>Time</th><th>Event</th><th>User</th><th>Details</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
    }

    /** "3 days ago", "5 minutes ago". */
    public static function age(int $seconds): string
    {
        $seconds = max(0, $seconds);
        foreach ([['day', 86400], ['hour', 3600], ['minute', 60]] as [$unit, $size]) {
            if ($seconds >= $size) {
                $n = intdiv($seconds, $size);

                return $n . ' ' . $unit . ($n === 1 ? '' : 's') . ' ago';
            }
        }

        return 'just now';
    }

    private static function hintText(string $hint): string
    {
        return $hint === '' ? '' : '<p class="hint">' . Html::e($hint) . '</p>';
    }

    private static function flashes(Context $context): string
    {
        $html = '';
        foreach ($context->takeFlashes() as $flash) {
            $type = in_array($flash['type'], ['ok', 'warning', 'error'], true) ? $flash['type'] : 'ok';
            $html .= '<p class="flash ' . $type . '" role="' . ($type === 'error' ? 'alert' : 'status') . '">' . Html::e($flash['message']) . '</p>';
        }

        return $html;
    }

    private static function document(Context $context, string $title, string $inner, int $status, bool $withMenu = false): Response
    {
        $theme = self::theme($context);
        $attributes = ($theme !== 'auto' ? ' data-theme="' . $theme . '"' : '') . ($withMenu && self::collapsed($context) ? ' data-nav="collapsed"' : '');
        $csrf = $context->csrf() !== '' ? '<meta name="csrf-token" content="' . Html::e($context->csrf()) . '">' : '';
        $html = '<!doctype html><html lang="en"' . $attributes . '><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow"><meta name="color-scheme" content="light dark">' . $csrf
            . '<title>' . Html::e($title) . ' - ThreadSafe</title><link rel="stylesheet" href="' . Html::e($context->urls->asset('app.css')) . '">'
            . '<script src="' . Html::e($context->urls->asset('app.js')) . '" defer></script></head><body>'
            . $inner . '</body></html>';

        return Response::html($html, $status);
    }

    private function __construct()
    {
    }
}
