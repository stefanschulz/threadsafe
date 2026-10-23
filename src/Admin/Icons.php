<?php

declare(strict_types=1);

namespace ThreadSafe\Admin;

/**
 * The icons of the interface: 24 x 24, drawn with a line of 1.5 that takes the colour of the text around it (`stroke="currentColor"`, round
 * ends and joins, no fill), as docs/DESIGN.md says. They are inline, so there is no request for them; the style is in `app.css` (`.icon`).
 * They carry no meaning of their own: every icon stands next to a text or inside a button that has a name.
 */
final class Icons
{
    private const array PATHS = [
        'dashboard' => '<rect x="3.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="3.5" y="13.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="13.5" width="7" height="7" rx="1.5"/>',
        'profiles' => '<rect x="3.5" y="4" width="17" height="6.5" rx="1.5"/><rect x="3.5" y="13.5" width="17" height="6.5" rx="1.5"/><path d="M7 7.25h.01M7 16.75h.01"/>',
        'system' => '<path d="M4 7h9M17 7h3M4 17h3M11 17h9"/><circle cx="15" cy="7" r="2"/><circle cx="9" cy="17" r="2"/>',
        'edit' => '<path d="M4 20h4L18.5 9.5a2.12 2.12 0 0 0-3-3L5 17z"/><path d="M13.5 7.5l3 3"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'chevron' => '<path d="M14.5 6l-6 6 6 6"/>',
        'auto' => '<rect x="3" y="4.5" width="18" height="12" rx="1.5"/><path d="M8.5 20h7M12 16.5V20"/>',
        'light' => '<circle cx="12" cy="12" r="4"/><path d="M12 3v2M12 19v2M3 12h2M19 12h2M5.6 5.6L7 7M17 17l1.4 1.4M5.6 18.4L7 17M17 7l1.4-1.4"/>',
        'dark' => '<path d="M20 14.5A8 8 0 1 1 9.5 4a6.5 6.5 0 0 0 10.5 10.5z"/>',
        'logout' => '<path d="M9 4.5H6.5A1.5 1.5 0 0 0 5 6v12a1.5 1.5 0 0 0 1.5 1.5H9M14 8l4 4-4 4M18 12H9.5"/>',
    ];

    /**
     * @param string $name one of the icons above
     *
     * @return string an `<svg>` element, hidden from assistive technology
     */
    public static function svg(string $name): string
    {
        return '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . (self::PATHS[$name] ?? '') . '</svg>';
    }

    private function __construct()
    {
    }
}
