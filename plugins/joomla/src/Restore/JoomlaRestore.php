<?php

declare(strict_types=1);

namespace ThreadSafe\Joomla\Restore;

use ThreadSafe\Backup\Manifest;
use ThreadSafe\Joomla\Installation;
use ThreadSafe\Plugin\Restorable;
use ThreadSafe\Restore\Check;
use ThreadSafe\Restore\Field;

/**
 * The restore side of Joomla 5 and 6: what the restore asks beyond what every restore needs, how the answers become settings of the
 * adaptation, where the old address comes from (docs/PHASE6.md 3.4), and what is checked before anything is written.
 */
final class JoomlaRestore implements Restorable
{
    /** PHP extensions that Joomla's own installer insists on; without them the restored site does not run. */
    private const array NEEDED = ['json' => 'json', 'zlib' => 'zlib', 'xml' => 'xml', 'mbstring' => 'mbstring'];

    /** and those it warns about */
    private const array RECOMMENDED = ['gd' => 'gd', 'iconv' => 'iconv', 'zip' => 'zip', 'intl' => 'intl'];

    public function fields(Manifest $manifest): array
    {
        $candidates = $this->candidates($manifest);
        $found = $candidates === [] ? '' : ' Found in the content of the backup: ' . implode(', ', array_map(static fn (array $c): string => $c['host'] . ' (' . $c['count'] . ')', array_slice($candidates, 0, 5))) . '.';

        return [
            new Field('from', 'Old domain', Field::TEXT, $this->sourceDomain($manifest) ?? '', help: 'The domain the backed-up site had; it is replaced in the content of the database. Joomla stores its own address nowhere, so this is the most frequent one in the content.' . $found),
            new Field('to', 'New domain', Field::TEXT, '', help: 'The domain the restored site is reached under, e.g. www.example.org. Empty: the domain stays and only the host-bound settings are adapted.'),
            new Field('path', 'Base path', Field::TEXT, '', help: 'Where in the domain the site lives, e.g. /sub/; empty keeps it as it is. Sets RewriteBase in .htaccess.'),
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
        // an old domain without a new one would be refused as an error of the request; it is only a proposal, so it is left out
        if (!isset($settings['to'])) {
            unset($settings['from']);
        }

        return $settings;
    }

    /**
     * Where the old address comes from: the `live_site` of the backed-up site if it was set, else the host that its content points to
     * most often. Null if the backup knows neither.
     */
    public function sourceDomain(Manifest $manifest): ?string
    {
        $liveSite = $manifest->extras['liveSite'] ?? null;
        if (is_string($liveSite) && $liveSite !== '') {
            $parts = parse_url($liveSite);
            if (is_array($parts) && isset($parts['host'])) {
                return strtolower($parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''));
            }
        }

        return $this->candidates($manifest)[0]['host'] ?? null;
    }

    public function restoreChecks(Manifest $manifest): array
    {
        $checks = [];
        $version = $manifest->application['version'];
        $major = $version === null ? 0 : (int) explode('.', $version)[0];
        if (!in_array($major, Installation::MAJOR_VERSIONS, true)) {
            $checks[] = Check::error('The backup holds ' . ($version === null ? 'a Joomla of unknown version' : "Joomla $version") . '; only Joomla 5 and 6 can be restored and adapted.');
        }
        foreach (self::NEEDED as $extension => $name) {
            if (!extension_loaded($extension)) {
                $checks[] = Check::warning("The PHP extension \"$name\" is missing on this host; Joomla needs it, so the restored site will not run until it is there.");
            }
        }
        $missing = array_keys(array_filter(self::RECOMMENDED, static fn (string $extension): bool => !extension_loaded($extension)));
        if ($missing !== []) {
            $checks[] = Check::warning('Joomla recommends the PHP extensions ' . implode(', ', $missing) . ', which this host does not have.');
        }
        if (!extension_loaded('mysqli') && !extension_loaded('pdo_mysql')) {
            $checks[] = Check::warning('This PHP has neither mysqli nor pdo_mysql; the restored site cannot reach its database.');
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
        if (is_string($manifest->extras['liveSite'] ?? null) && $manifest->extras['liveSite'] !== '') {
            $checks[] = Check::warning('The site had a fixed address (live_site = ' . $manifest->extras['liveSite'] . '); the restore follows it to the new domain, or clears it if the domain stays.');
        }

        return $checks;
    }

    /**
     * @return list<array{host: string, count: int}>
     */
    private function candidates(Manifest $manifest): array
    {
        $found = $manifest->extras['addressCandidates'] ?? null;
        if (!is_array($found)) {
            return [];
        }
        $candidates = [];
        foreach ($found as $candidate) {
            if (is_array($candidate) && is_string($candidate['host'] ?? null) && $candidate['host'] !== '') {
                $candidates[] = ['host' => $candidate['host'], 'count' => (int) ($candidate['count'] ?? 0)];
            }
        }

        return $candidates;
    }
}
