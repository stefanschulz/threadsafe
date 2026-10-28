<?php

declare(strict_types=1);

namespace ThreadSafe\PrestaShop9;

use ThreadSafe\Backup\Manifest;
use ThreadSafe\Cli\Input;
use ThreadSafe\Cli\OptionSpec;
use ThreadSafe\Database\Connection;
use ThreadSafe\Database\DatabaseSettings;
use ThreadSafe\Migration\RequestBuilder as BaseRequestBuilder;
use ThreadSafe\Plugin\Backupable;
use ThreadSafe\Plugin\BackupDefaults;
use ThreadSafe\Plugin\DetectedSite;
use ThreadSafe\Plugin\Restorable;
use ThreadSafe\Plugin\WebsiteType;
use ThreadSafe\PrestaShop9\Backup\PrestaShop9Backup;
use ThreadSafe\PrestaShop9\Restore\PrestaShop9Restore;

/**
 * The website type "PrestaShop 9": entry class of the plugin in `plugins/prestashop9/`.
 */
final class PrestaShop9Type implements WebsiteType, Backupable, Restorable
{
    public function fields(Manifest $manifest): array
    {
        return (new PrestaShop9Restore())->fields($manifest);
    }

    public function restoreSettings(array $values): array
    {
        return (new PrestaShop9Restore())->restoreSettings($values);
    }

    public function sourceDomain(Manifest $manifest): ?string
    {
        return (new PrestaShop9Restore())->sourceDomain($manifest);
    }

    public function restoreChecks(Manifest $manifest): array
    {
        return (new PrestaShop9Restore())->restoreChecks($manifest);
    }

    public function links(Manifest $manifest, string $root, array $values, string $servedPath): array
    {
        return (new PrestaShop9Restore())->links($manifest, $root, $values, $servedPath);
    }

    public function detect(string $path): ?DetectedSite
    {
        return (new PrestaShop9Backup())->detect($path);
    }

    public function databaseSettings(string $path): DatabaseSettings
    {
        return (new PrestaShop9Backup())->databaseSettings($path);
    }

    public function defaultFilters(DetectedSite $site): BackupDefaults
    {
        return (new PrestaShop9Backup())->defaultFilters($site);
    }

    public function manifestExtras(DetectedSite $site, ?Connection $db): array
    {
        return (new PrestaShop9Backup())->manifestExtras($site, $db);
    }

    public function id(): string
    {
        return 'prestashop9';
    }

    public function title(): string
    {
        return 'PrestaShop 9';
    }

    public function options(): array
    {
        return [
            new OptionSpec('path', 'New base path, e.g. /shop/ (default: unchanged)', takesValue: true, valueName: 'PATH'),
            new OptionSpec('ssl', 'SSL mode: none, login or all (default: unchanged)', takesValue: true, valueName: 'MODE'),
            new OptionSpec('mode', 'production (default) or dev: cut live mail, payment and cloud connections, keep search engines out', takesValue: true, valueName: 'MODE'),
            new OptionSpec('mail-catcher', 'Dev mode: SMTP server that receives all mail, HOST or HOST:PORT (default: the shop sends no mail)', takesValue: true, valueName: 'HOST[:PORT]'),
            new OptionSpec('dev-skip', 'Dev mode: rule ids to leave out, comma separated (mail, payment, accounts, tracking, noindex)', takesValue: true, valueName: 'LIST'),
            new OptionSpec('dev-rules', 'Dev mode: JSON file with more rules (format of plugins/prestashop9/resources/dev-mode.json)', takesValue: true, valueName: 'FILE'),
            new OptionSpec('rules-dir', 'Directory with your module rules, one <module>.json each (default: data/rules next to the program)', takesValue: true, valueName: 'DIR'),
            new OptionSpec('no-finalize', 'Do not let PrestaShop clear its caches itself at the end'),
        ];
    }

    public function settings(Input $input): array
    {
        $settings = [];
        foreach (['path' => 'path', 'ssl' => 'ssl', 'mode' => 'mode', 'mail-catcher' => 'mailCatcher', 'dev-rules' => 'devRules', 'rules-dir' => 'rulesDir'] as $option => $key) {
            if ($input->has($option)) {
                $settings[$key] = $input->value($option);
            }
        }
        if ($input->has('dev-skip')) {
            $settings['devSkip'] = array_values(array_filter(array_map('trim', explode(',', (string) $input->value('dev-skip')))));
        }
        if ($input->has('no-finalize')) {
            $settings['finalize'] = false;
        }

        return $settings;
    }

    public function requestBuilder(): BaseRequestBuilder
    {
        return new RequestBuilder();
    }

    public function steps(): array
    {
        return MigrationPipeline::steps();
    }
}
