<?php

declare(strict_types=1);

namespace ThreadSafe\Admin;

use ThreadSafe\Core\Version;
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
    private const array NAVIGATION = ['/' => 'Dashboard'];

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
    public static function field(string $name, string $label, string $type = 'text', string $value = '', array $attributes = []): string
    {
        $extra = '';
        foreach ($attributes as $attribute => $content) {
            if (preg_match('/^[a-z-]+$/', $attribute) === 1) {
                $extra .= ' ' . $attribute . '="' . Html::e($content) . '"';
            }
        }

        return '<div class="field"><label for="f-' . Html::e($name) . '">' . Html::e($label) . '</label><input id="f-' . Html::e($name) . '" name="' . Html::e($name) . '" type="' . Html::e($type) . '"'
            . ($type === 'password' ? '' : ' value="' . Html::e($value) . '"') . $extra . '></div>';
    }

    /** A plain page for an error before anything else is set up (no session, no addresses). */
    public static function error(string $title, string $message, int $status): Response
    {
        return new Response($status, ['Content-Type: text/html; charset=utf-8'], '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>' . Html::e($title) . ' - ThreadSafe</title>'
            . '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"></head><body><h1>' . Html::e($title) . '</h1><p>' . Html::e($message) . '</p></body></html>');
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
