<?php

declare(strict_types=1);

namespace ThreadSafe\Admin;

use ThreadSafe\Plugin\PluginError;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Profile\SiteProfile;
use ThreadSafe\Web\Html;

/**
 * The filter by website type above the sites on the dashboard and on the profiles page: all, and one entry per website type that the
 * profiles have, each with its number. The chosen one is marked (`aria-current`); the entries are links (`?type=...`), so the choice is
 * in the address and works without script.
 */
final class TypeFilter
{
    /**
     * @param list<SiteProfile> $profiles
     *
     * @return array<string, int> website type => number of profiles
     */
    public static function count(array $profiles): array
    {
        $byType = [];
        foreach ($profiles as $profile) {
            $byType[$profile->type] = ($byType[$profile->type] ?? 0) + 1;
        }
        ksort($byType);

        return $byType;
    }

    /**
     * The type that the request asks for, if the profiles have it; an unknown type means all.
     *
     * @param array<string, int> $byType
     */
    public static function selected(Context $context, array $byType): ?string
    {
        $wanted = $context->request->query('type');

        return is_string($wanted) && isset($byType[$wanted]) ? $wanted : null;
    }

    /**
     * @param string             $route    the page the entries lead to (`/` or `/profiles`)
     * @param array<string, int> $byType   website type => number of profiles
     * @param string|null        $selected the type that is shown, or null for all
     */
    public static function render(Context $context, string $route, array $byType, int $all, ?string $selected): string
    {
        $plugins = PluginRegistry::load();
        $chip = static fn (string $label, int $count, string $href, bool $current): string => '<a href="' . Html::e($href) . '"' . ($current ? ' aria-current="true"' : '') . '>'
            . Html::e($label) . ' <span class="count">' . $count . '</span></a>';
        $html = $chip('All', $all, $context->urls->to($route), $selected === null);
        foreach ($byType as $type => $count) {
            $html .= $chip(self::title($plugins, $type), $count, $context->urls->to($route, ['type' => $type]), $selected === $type);
        }

        return '<nav class="filter" aria-label="Filter the sites by website type"><span class="filter-label">Website type</span>' . $html . '</nav>';
    }

    /** The name of a website type as people know it ("PrestaShop 9"); the id when the type is not installed. */
    public static function title(PluginRegistry $plugins, string $type): string
    {
        try {
            return $plugins->get($type)->title();
        } catch (PluginError) {
            return $type;
        }
    }

    private function __construct()
    {
    }
}
