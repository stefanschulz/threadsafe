<?php

declare(strict_types=1);

namespace ThreadSafe\PrestaShop9;

use InvalidArgumentException;
use ThreadSafe\Core\Exception\GuardFailed;
use ThreadSafe\Database\Connection;
use ThreadSafe\Database\ConnectionFailed;
use ThreadSafe\Database\DatabaseSettings;
use ThreadSafe\Migration\MailCatcher;
use ThreadSafe\Migration\Mode;
use ThreadSafe\Migration\RequestBuilder as BaseRequestBuilder;
use ThreadSafe\Migration\SslMode;
use ThreadSafe\PrestaShop9\DevMode\DevModeRules;
use ThreadSafe\PrestaShop9\Exception\InvalidParametersFile;
use ThreadSafe\PrestaShop9\Modules\ModuleRules;

/**
 * The request builder for PrestaShop 9: the common settings ({@see BaseRequestBuilder}) plus
 *
 *   path          string   new base path, e.g. "/shop/"
 *   ssl           string   none | login | all
 *   mode          string   production (default) or dev: cut live mail, payment and cloud connections
 *   mailCatcher   string   dev mode: HOST or HOST:PORT of the SMTP server that receives all mail (default: no mail at all)
 *   devSkip       list     dev mode: ids of rules to leave out (see plugins/prestashop9/resources/dev-mode.json)
 *   devRules      string   dev mode: JSON file with more rules
 *   rulesDir      string   directory with the user's module rules (default: data/rules next to the program)
 *   finalize      bool     let PrestaShop clear its caches itself at the end (default true)
 *
 * Database values that are not given come from `app/config/parameters.php`; `from` defaults to the main domain in the
 * shop's database.
 */
final class RequestBuilder extends BaseRequestBuilder
{
    protected function extraKeys(): array
    {
        return ['path', 'ssl', 'mode', 'mailCatcher', 'devSkip', 'devRules', 'rulesDir', 'finalize'];
    }

    protected function extraArguments(array $settings, string $root): array
    {
        $mode = Mode::tryFrom($this->string($settings, 'mode') ?? 'production') ?? throw new InvalidArgumentException('The mode must be "production" or "dev".');
        $rulesDir = $this->string($settings, 'rulesDir');
        if ($rulesDir !== null && !is_dir($rulesDir)) {
            throw new InvalidArgumentException("The rules directory \"$rulesDir\" does not exist.");
        }
        [$mailCatcher, $devSkip, $devRules] = $this->devSettings($settings, $mode, $rulesDir);

        $ssl = null;
        if (($value = $this->string($settings, 'ssl')) !== null) {
            $ssl = SslMode::tryFrom($value) ?? throw new InvalidArgumentException('The SSL mode must be "none", "login" or "all".');
        }

        return [
            'physicalUri' => $this->string($settings, 'path'),
            'ssl' => $ssl,
            'mode' => $mode,
            'mailCatcher' => $mailCatcher,
            'devSkip' => $devSkip,
            'devRulesFile' => $devRules,
            'rulesDir' => $rulesDir,
            'finalize' => $this->boolean($settings, 'finalize', true),
        ];
    }

    /**
     * The dev mode settings; they make no sense in production mode, where they would be silently ignored.
     *
     * @param array<string, mixed> $settings
     *
     * @return array{MailCatcher|null, list<string>, string|null}
     */
    private function devSettings(array $settings, Mode $mode, ?string $rulesDir): array
    {
        $catcher = $this->string($settings, 'mailCatcher');
        $skip = $settings['devSkip'] ?? [];
        $file = $this->string($settings, 'devRules');
        if (!is_array($skip) || array_filter($skip, static fn (mixed $id): bool => !is_string($id) || $id === '') !== []) {
            throw new InvalidArgumentException('devSkip must be a list of rule ids.');
        }

        if ($mode !== Mode::Dev) {
            if ($catcher !== null || $skip !== [] || $file !== null) {
                throw new InvalidArgumentException('The mail catcher and the dev rule settings need --mode=dev.');
            }

            return [null, [], null];
        }

        $skip = array_values($skip);
        DevModeRules::load($file)->assertKnown($skip, ModuleRules::load($rulesDir)->knownNames());

        return [$catcher === null ? null : MailCatcher::parse($catcher), $skip, $file];
    }

    protected function configuredDatabase(string $root): ?DatabaseSettings
    {
        try {
            return ParametersFile::fromPath($root . '/app/config/parameters.php')->database();
        } catch (InvalidParametersFile) {
            return null; // all values must then be given
        }
    }

    /** The main shop URL's domain, read from the shop's database. */
    protected function currentDomain(?DatabaseSettings $database, string $root): string
    {
        try {
            $settings = $database ?? ParametersFile::fromPath($root . '/app/config/parameters.php')->database();
            $connection = Connection::open($settings);
            $table = $connection->table('shop_url');
            $domain = $connection->tableExists($table)
                ? $connection->scalar('SELECT domain FROM ' . $connection->quote($table) . ' WHERE main = 1 ORDER BY id_shop_url LIMIT 1')
                : null;
        } catch (InvalidParametersFile | ConnectionFailed $e) {
            throw new GuardFailed('Cannot find out the shop\'s current domain (give --from): ' . $e->getMessage(), 0, $e);
        }
        if (!is_string($domain) || $domain === '') {
            throw new GuardFailed('The shop has no main shop URL; give the old domain with --from.');
        }
        $this->note("Old domain taken from the shop's database: $domain");

        return $domain;
    }
}
