<?php

declare(strict_types=1);

namespace ThreadSafe\PrestaShop9\Restore;

use ThreadSafe\Backup\Manifest;
use ThreadSafe\Plugin\Restorable;
use ThreadSafe\Restore\Check;
use ThreadSafe\Restore\Field;

/**
 * The restore side of PrestaShop 9: what the person is asked (the new address of the shop, pre-filled with the old one), how the
 * answers become request settings, and what is checked before anything is written.
 */
final class PrestaShop9Restore implements Restorable
{
    public function fields(Manifest $manifest): array
    {
        $main = $this->mainUrl($manifest);

        return [
            new Field('to', 'New domain of the shop', Field::TEXT, '', required: true, proposeHost: true, help: 'The domain the restored shop is reached under, e.g. shop.example.org' . ($main !== null ? ' (the backup was made on ' . $main['domain'] . ')' : '') . '.'),
            new Field('path', 'Base path', Field::TEXT, '', help: 'Where in the domain the shop lives, e.g. /shop/; empty keeps ' . ($main['physicalUri'] ?? '/') . '.'),
            new Field('ssl', 'SSL', Field::CHOICE, '', ['', 'none', 'login', 'all'], help: 'none, login (back office and login only) or all; empty keeps the setting of the backup.'),
            new Field('mode', 'Mode', Field::CHOICE, 'production', ['production', 'dev'], help: 'dev cuts live mail, payment and cloud connections and keeps search engines out.'),
            new Field('mailCatcher', 'Mail catcher', Field::TEXT, '', help: 'Dev mode only: HOST or HOST:PORT of an SMTP server that receives all mail.'),
        ];
    }

    public function restoreSettings(array $values): array
    {
        $settings = [];
        foreach (['to' => 'to', 'path' => 'path', 'ssl' => 'ssl', 'mailCatcher' => 'mailCatcher'] as $field => $key) {
            if (($values[$field] ?? '') !== '') {
                $settings[$key] = $values[$field];
            }
        }
        if (($values['mode'] ?? '') === 'dev') {
            $settings['mode'] = 'dev';
        }

        return $settings;
    }

    public function sourceDomain(Manifest $manifest): ?string
    {
        return $this->mainUrl($manifest)['domain'] ?? null;
    }

    public function restoreChecks(Manifest $manifest): array
    {
        $checks = [];
        $version = $manifest->application['version'];
        if ($version === null || (int) explode('.', $version)[0] !== 9) {
            $checks[] = Check::error('The backup holds ' . ($version === null ? 'a PrestaShop of unknown version' : "PrestaShop $version") . '; only PrestaShop 9 can be restored and adapted.');
        }
        if (isset($manifest->extras['extrasWarning'])) {
            $checks[] = Check::warning('The backup could not read everything about the shop: ' . (string) $manifest->extras['extrasWarning']);
        }
        if ($this->mainUrl($manifest) === null) {
            $checks[] = Check::warning('The backup does not say which domain the shop had; give the old domain (--from) if the domain is to be changed.');
        }

        return $checks;
    }

    /**
     * @return array{domain: string, physicalUri: string}|null
     */
    private function mainUrl(Manifest $manifest): ?array
    {
        $urls = $manifest->extras['shopUrls'] ?? null;
        if (!is_array($urls)) {
            return null;
        }
        $first = null;
        foreach ($urls as $url) {
            if (!is_array($url) || !is_string($url['domain'] ?? null) || $url['domain'] === '') {
                continue;
            }
            $found = ['domain' => $url['domain'], 'physicalUri' => is_string($url['physicalUri'] ?? null) ? $url['physicalUri'] : '/'];
            if (($url['main'] ?? false) === true) {
                return $found;
            }
            $first ??= $found;
        }

        return $first;
    }
}
