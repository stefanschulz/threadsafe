<?php

declare(strict_types=1);

namespace ThreadSafe\Admin;

use ThreadSafe\Web\Html;

/**
 * The sections of a page, side by side under the title, one shown at a time (the System page, the page of a profile). They are links
 * (`?tab=...`), so the choice is in the address and nothing needs script; the one that is shown is marked with `aria-current`. The first
 * section is the one that opens without `?tab=`.
 */
final class Tabs
{
    /**
     * The section that the address asks for; anything unknown is the first one.
     *
     * @param array<string, string> $tabs id => name, the first one is the default
     */
    public static function current(Context $context, array $tabs): string
    {
        $wanted = $context->request->query('tab');

        return is_string($wanted) && isset($tabs[$wanted]) ? $wanted : (string) array_key_first($tabs);
    }

    /**
     * @param string                $route   the page the links lead to (without the query)
     * @param array<string, string> $tabs    id => name
     * @param string                $current the id of the section that is shown
     * @param string                $label   the name of the navigation for assistive technology
     */
    public static function render(Context $context, string $route, array $tabs, string $current, string $label): string
    {
        $first = (string) array_key_first($tabs);
        $links = '';
        foreach ($tabs as $id => $name) {
            $links .= '<a href="' . Html::e($context->urls->to($route, $id === $first ? [] : ['tab' => $id])) . '"' . ($id === $current ? ' aria-current="page"' : '') . '>' . Html::e($name) . '</a>';
        }

        return '<nav class="tabs" aria-label="' . Html::e($label) . '">' . $links . '</nav>';
    }

    private function __construct()
    {
    }
}
