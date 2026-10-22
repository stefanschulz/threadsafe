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
 */
final class View
{
    /** The entries of the navigation: route => label. Later work packages add theirs. */
    private const array NAVIGATION = ['/' => 'Dashboard', '/profiles' => 'Profiles', '/system' => 'System'];

    /**
     * A page for a logged-in user.
     *
     * @param string $active the route of the navigation entry to mark as current
     */
    public static function page(Context $context, string $title, string $body, string $active = '/', int $status = 200): Response
    {
        $navigation = '';
        foreach (self::NAVIGATION as $route => $label) {
            $navigation .= '<a href="' . Html::e($context->urls->to($route)) . '"' . ($route === $active ? ' aria-current="page"' : '') . '>' . Html::e($label) . '</a>';
        }
        $header = '<header class="bar"><a class="brand" href="' . Html::e($context->urls->to('/')) . '">ThreadSafe</a><nav aria-label="Main">' . $navigation . '</nav>'
            . self::form($context, '/logout', '<button class="link" type="submit">Log out (' . Html::e($context->user() ?? '') . ')</button>', 'logout') . '</header>';

        return self::document($context, $title, $header . '<main>' . self::flashes($context) . '<h1>' . Html::e($title) . '</h1>' . $body . '</main>', $status);
    }

    /**
     * A page before the login (the login itself, the setup, the explanations).
     */
    public static function bare(Context $context, string $title, string $body, int $status = 200): Response
    {
        $header = '<header class="bar"><span class="brand">ThreadSafe</span></header>';

        return self::document($context, $title, $header . '<main class="narrow">' . self::flashes($context) . '<h1>' . Html::e($title) . '</h1>' . $body . '</main>', $status);
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

    private static function document(Context $context, string $title, string $inner, int $status): Response
    {
        $csrf = $context->csrf() !== '' ? '<meta name="csrf-token" content="' . Html::e($context->csrf()) . '">' : '';
        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow"><meta name="color-scheme" content="light dark">' . $csrf
            . '<title>' . Html::e($title) . ' - ThreadSafe</title><link rel="stylesheet" href="' . Html::e($context->urls->asset('app.css')) . '">'
            . '<script src="' . Html::e($context->urls->asset('app.js')) . '" defer></script></head><body>'
            . $inner . '<footer>ThreadSafe ' . Html::e(Version::CURRENT) . '</footer></body></html>';

        return Response::html($html, $status);
    }

    private function __construct()
    {
    }
}
