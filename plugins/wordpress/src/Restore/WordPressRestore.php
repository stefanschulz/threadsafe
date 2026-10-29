<?php

declare(strict_types=1);

namespace ThreadSafe\WordPress\Restore;

use ThreadSafe\Backup\Manifest;
use ThreadSafe\Plugin\Restorable;
use ThreadSafe\Restore\Check;
use ThreadSafe\Restore\Field;
use ThreadSafe\Restore\SitePath;
use ThreadSafe\WordPress\Installation;

/**
 * The restore side of WordPress: what the restore asks beyond what every restore needs, how the answers become settings of the adaptation,
 * where the old address comes from (the manifest holds `home` and `siteurl` as the options of the database had them: no guessing), and what
 * is checked before anything is written (docs/PHASE9.md 3.5).
 */
final class WordPressRestore implements Restorable
{
    public function fields(Manifest $manifest): array
    {
        $home = self::path($manifest);

        return [
            new Field('from', 'Old domain', Field::TEXT, $this->sourceDomain($manifest) ?? '', help: 'The domain the backed-up site had, as its database says (the option "home"); it is replaced in the content of the database.'),
            new Field('to', 'New domain', Field::TEXT, '', required: true, proposeHost: true, help: 'The domain the restored site is reached under, e.g. www.example.org.'),
            new Field('path', 'Base path', Field::TEXT, '', help: $home === '' ? 'Where in the new domain the site lives, e.g. /sub/; empty: at the root. Links in the content follow it.' : 'The old site lived in "' . $home . '/"; leave this empty: ThreadSafe can move a site into a directory only from the root of a domain.'),
            new Field('keepDropIns', 'Cache drop-ins', Field::CHOICE, 'off', ['off', 'keep'], help: 'off (the default): object-cache.php, advanced-cache.php and db.php are renamed to *.threadsafe-off, because a drop-in that serves something the old host had (Redis, a page cache) breaks every page; keep: leave them as they are.'),
        ];
    }

    public function restoreSettings(array $values): array
    {
        $settings = [];
        foreach (['from' => 'from', 'to' => 'to', 'path' => 'path'] as $field => $key) {
            if (($values[$field] ?? '') !== '') {
                $settings[$key] = $values[$field];
            }
        }
        if (($values['keepDropIns'] ?? '') === 'keep') {
            $settings['keepDropIns'] = true;
        }
        // an old domain without a new one would be refused as an error of the request; it is only a proposal, so it is left out
        if (!isset($settings['to'])) {
            unset($settings['from']);
        }

        return $settings;
    }

    /**
     * The domain the backed-up site had: the host (and port) of the option `home`, else of `siteurl`; null if the backup knows neither.
     */
    public function sourceDomain(Manifest $manifest): ?string
    {
        foreach (['homeUrl', 'siteUrl'] as $key) {
            $url = $manifest->extras[$key] ?? null;
            $parts = is_string($url) ? parse_url($url) : false;
            if (is_array($parts) && isset($parts['host'])) {
                return strtolower($parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''));
            }
        }

        return null;
    }

    public function restoreChecks(Manifest $manifest): array
    {
        $checks = [];
        $version = $manifest->application['version'];
        if ($version === null || !Installation::isSupported($version)) {
            $checks[] = Check::error('The backup holds ' . ($version === null ? 'a WordPress of unknown version' : "WordPress $version") . '; only WordPress ' . Installation::MINIMUM_VERSION . ' and later can be restored and adapted.');
        }
        if (($manifest->extras['multisite'] ?? false) === true || ($manifest->extras['multisiteTables'] ?? false) === true) {
            $checks[] = Check::error('The backup holds a WordPress multisite network, which ThreadSafe does not restore yet.');
        }
        if (!extension_loaded('mysqli')) {
            $checks[] = Check::warning('This PHP has no mysqli, which WordPress itself uses to reach its database: the files and the database can be restored, but the site will not run on this host until the web server has it.');
        }
        foreach (['json', 'xml', 'mbstring'] as $extension) {
            if (!extension_loaded($extension)) {
                $checks[] = Check::warning("The PHP extension \"$extension\" is missing on this host; WordPress needs it, so the restored site will not run until it is there.");
            }
        }
        if (!extension_loaded('mysqli') && !extension_loaded('pdo_mysql')) {
            $checks[] = Check::warning('This PHP has neither mysqli nor pdo_mysql; the restored site cannot reach its database.');
        }
        if ($version !== null && Installation::isSupported($version) && version_compare($version, '6.7', '<') && version_compare((string) phpversion(), '8.4', '>=')) {
            $checks[] = Check::warning("WordPress $version on PHP " . phpversion() . ' works with deprecation notices (WordPress supports PHP 8.4 from version 6.7); a host that shows PHP notices on the page shows them.');
        }
        if (($manifest->extras['configLocation'] ?? 'root') === 'above') {
            $checks[] = Check::warning('The site kept its wp-config.php above the site directory, which a backup does not hold: a new one is made from wp-config-sample.php with the database of the restore and new keys (every login of the old site ends).');
        }
        if (isset($manifest->extras['configurationWarning'])) {
            $checks[] = Check::warning('The configuration of the site could not be read when the backup was made: ' . (string) $manifest->extras['configurationWarning']);
        }
        if (isset($manifest->extras['extrasWarning'])) {
            $checks[] = Check::warning('The backup could not read everything about the site: ' . (string) $manifest->extras['extrasWarning']);
        }
        if ($this->sourceDomain($manifest) === null) {
            $checks[] = Check::warning('The backup does not say which domain the site had; give the old domain (--from) if the domain is to be changed.');
        }
        $dropIns = $manifest->extras['dropIns'] ?? [];
        if (is_array($dropIns) && $dropIns !== []) {
            $checks[] = Check::info('The site has the drop-ins ' . implode(', ', array_map('strval', $dropIns)) . '; unless you ask to keep them they are renamed to *.threadsafe-off (a drop-in that serves something the old host had breaks every page).');
        }
        foreach (['homeConstant' => 'WP_HOME', 'siteurlConstant' => 'WP_SITEURL'] as $key => $constant) {
            if (($manifest->extras[$key] ?? false) === true) {
                $checks[] = Check::info("wp-config.php sets $constant, which overrides the database: it follows the new address.");
            }
        }
        $siteUrl = $manifest->extras['siteUrl'] ?? null;
        $homeUrl = $manifest->extras['homeUrl'] ?? null;
        if (is_string($siteUrl) && is_string($homeUrl) && rtrim($siteUrl, '/') !== rtrim($homeUrl, '/')) {
            $checks[] = Check::info("The address of the site ($siteUrl) is not the address of the home page ($homeUrl): WordPress lives in its own directory; both follow the new domain.");
        }

        return $checks;
    }

    public function links(Manifest $manifest, string $root, array $values, string $servedPath): array
    {
        $base = SitePath::base($values['path'] ?? '', self::path($manifest) !== '' ? self::path($manifest) . '/' : $servedPath);

        return [
            ['label' => 'Open the site', 'path' => $base],
            ['label' => 'Administration login', 'path' => $base . 'wp-admin/'],
        ];
    }

    /** The path the old site lived in, without a trailing slash ("" at the root of its domain). */
    private static function path(Manifest $manifest): string
    {
        $home = $manifest->extras['homeUrl'] ?? null;
        $path = is_string($home) ? parse_url($home, PHP_URL_PATH) : null;

        return is_string($path) ? rtrim($path, '/') : '';
    }
}
