<?php

declare(strict_types=1);

namespace ThreadSafe\Web;

/**
 * The logo (`.design/logo.svg`, docs/DESIGN.md "The logo") as markup the pages can carry: the graphite tile, the door of a safe in brass outline, and the brass
 * disc with the restore arrow that steps out of it. The wizards (`threadsafe-restore.php`, `threadsafe-install.php`) are single files and the interface is served under a policy
 * that allows no inline style, so the logo is written with presentation attributes only - never a `style` attribute - and lives here, in the code, where
 * every page can reach it.
 */
final class Logo
{
    private const string TILE = '#1b232c';

    private const string BRASS = '#e0a13d';

    /**
     * The logo as an inline element, for the mark of the menu and the heading of a wizard. It is decoration: the name stands next to it.
     *
     * @param string $class the CSS class that gives it its size
     */
    public static function svg(string $class = 'logo'): string
    {
        $ink = self::TILE;
        $brass = self::BRASS;

        return '<svg class="' . htmlspecialchars($class, ENT_QUOTES) . '" viewBox="0 0 4500 4500" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false" fill-rule="evenodd" stroke-linecap="round" stroke-linejoin="round">'
            . '<rect x="225" y="225" width="4050" height="4050" rx="141.667" fill="' . $ink . '"/>'
            . '<g transform="translate(-190,-220)">'
            . '<rect x="1080" y="1020" width="2340" height="2340" rx="120" fill="none" stroke="' . $brass . '" stroke-width="66.67"/>'
            . '<rect x="960" y="1380" width="190" height="320" rx="41.667" fill="' . $brass . '"/>'
            . '<rect x="960" y="2680" width="190" height="320" rx="41.667" fill="' . $brass . '"/>'
            . '<circle cx="1950" cy="1830" r="450" fill="none" stroke="' . $brass . '" stroke-width="66.67"/>'
            . '<path d="M1950,1380 L1950,1460 M1950,2200 L1950,2280 M1500,1830 L1580,1830 M2320,1830 L2400,1830" fill="none" stroke="' . $brass . '" stroke-width="90"/>'
            . '<circle cx="1950" cy="1830" r="150" fill="' . $brass . '"/>'
            . '<circle cx="3260" cy="3300" r="640" fill="' . $brass . '" stroke="' . $ink . '" stroke-width="100"/>'
            . '<path d="M3472.1,3047.2 A330,330 0 1 0 3493.3,3533.3" fill="none" stroke="' . $ink . '" stroke-width="110"/>'
            . '<path d="M3260,3170 L3260,3300 L3365,3360" fill="none" stroke="' . $ink . '" stroke-width="95"/>'
            . '<path d="M3670.1,3356.6 L3645.4,3685.4 L3341.3,3381.3 Z" fill="' . $ink . '" stroke="' . $ink . '" stroke-width="40"/>'
            . '</g></svg>';
    }

    /**
     * The favicon: the logo with fewer and heavier parts, so that it holds at 16 and 32 px. `public/favicon.svg` is this, as a file; a test keeps them equal.
     */
    public static function favicon(): string
    {
        $ink = self::TILE;
        $brass = self::BRASS;

        return '<svg viewBox="0 0 4500 4500" xmlns="http://www.w3.org/2000/svg" fill-rule="evenodd" stroke-linecap="round" stroke-linejoin="round">'
            . '<rect x="225" y="225" width="4050" height="4050" rx="600" fill="' . $ink . '"/>'
            . '<g transform="translate(-190,-220)">'
            . '<rect x="1080" y="1020" width="2340" height="2340" rx="160" fill="none" stroke="' . $brass . '" stroke-width="170"/>'
            . '<circle cx="1950" cy="1830" r="470" fill="none" stroke="' . $brass . '" stroke-width="150"/>'
            . '<circle cx="1950" cy="1830" r="200" fill="' . $brass . '"/>'
            . '<circle cx="3260" cy="3300" r="700" fill="' . $brass . '" stroke="' . $ink . '" stroke-width="130"/>'
            . '<path d="M3491.4,3024.2 A360,360 0 1 0 3514.6,3554.6" fill="none" stroke="' . $ink . '" stroke-width="150"/>'
            . '<path d="M3260,3150 L3260,3300 L3380,3370" fill="none" stroke="' . $ink . '" stroke-width="130"/>'
            . '<path d="M3719.6,3349.5 L3684.3,3724.3 L3344.9,3384.9 Z" fill="' . $ink . '" stroke="' . $ink . '" stroke-width="60"/>'
            . '</g></svg>';
    }

    /** The favicon as an address that needs no file, for the pages that are single files. */
    public static function faviconUri(): string
    {
        return 'data:image/svg+xml;base64,' . base64_encode(self::favicon());
    }

    private function __construct()
    {
    }
}
