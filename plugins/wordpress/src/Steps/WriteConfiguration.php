<?php

declare(strict_types=1);

namespace ThreadSafe\WordPress\Steps;

use ThreadSafe\Core\Change;
use ThreadSafe\Core\Context;
use ThreadSafe\Core\Notice;
use ThreadSafe\Core\Plan;
use ThreadSafe\Core\Step;
use ThreadSafe\Core\StepResult;
use ThreadSafe\Migration\MigrationRequest;
use ThreadSafe\WordPress\Config\WordPressConfigFile;
use ThreadSafe\WordPress\State;

/**
 * Adapts `wp-config.php` to the host the site now runs on, changing only the values that must change and nothing else of the file (the
 * salts, the settings a site or a plugin added, the comments and the line ends stay byte for byte):
 *
 *  - the database (host, port, user, password, name) and the table prefix, if the request names another one;
 *  - `WP_HOME` and `WP_SITEURL` **if the file has them** (they override the database: a stale one keeps the site on the old address) - they
 *    follow the move to the new domain and the new base path; one that names a host that is not part of the move is left alone and reported;
 *  - the settings that hold an absolute path of the old host (`WP_CONTENT_DIR`, and what plugins wrote: WP Super Cache's `WPCACHEHOME`),
 *    which are put under the directory the site has now.
 *
 * Nothing is ever added to the file (docs/PHASE9.md 3.2).
 */
final class WriteConfiguration implements Step
{
    public function id(): string
    {
        return 'write-configuration';
    }

    public function title(): string
    {
        return 'Adapt wp-config.php to the new host';
    }

    public function plan(Context $context): Plan
    {
        $plan = new Plan();
        foreach ($this->changes($context) as $change) {
            $plan->add($change['change']);
            if (isset($change['notice'])) {
                $plan->notice(Notice::warning($change['notice'], 'wp-config.php'));
            }
        }

        return $plan;
    }

    public function apply(Context $context, Plan $plan): StepResult
    {
        $changes = $this->changes($context);
        $source = State::source($context);
        $request = State::request($context);
        $current = WordPressConfigFile::fromPath($source->configPath);
        $updated = $current;
        $applied = 0;
        foreach ($changes as $change) {
            if ($change['value'] !== null) {
                $updated = $updated->with($change['name'], $change['value']);
                ++$applied;
            }
        }
        // the paths of the old host: every setting that passes through a directory of WordPress, put under the directory of the site now
        $updated = $updated->withPathsRebased($request->serverPath());
        if ($updated->toString() === $current->toString()) {
            return new StepResult(0);
        }
        $backup = $context->journal->backupFile($source->configPath);
        $updated->writeTo($source->configPath);
        $context->journal->file($source->configPath, 'update', $backup);

        return new StepResult(max(1, $applied));
    }

    /**
     * @return list<array{name: string, value: string|null, change: Change, notice?: string}> a value of null: reported, not written
     */
    private function changes(Context $context): array
    {
        $source = State::source($context);
        $request = State::request($context);
        $old = $source->configuredDatabase;
        $new = $source->database;
        $found = [];
        $add = static function (string $name, ?string $value, string $before, string $after, bool $secret = false, ?string $notice = null) use (&$found): void {
            $entry = ['name' => $name, 'value' => $value, 'change' => Change::update('file wp-config.php ' . $name, self::describe($name), $before, $after, secret: $secret)];
            if ($notice !== null) {
                $entry['notice'] = $notice;
            }
            $found[] = $entry;
        };

        // the database: DB_HOST carries the port when it is not the usual one
        $oldHost = $old->port === 3306 ? $old->host : $old->host . ':' . $old->port;
        $newHost = $new->port === 3306 ? $new->host : $new->host . ':' . $new->port;
        foreach ([
            ['DB_HOST', $oldHost, $newHost, false],
            ['DB_USER', $old->user, $new->user, false],
            ['DB_PASSWORD', $old->password, $new->password, true],
            ['DB_NAME', $old->name, $new->name, false],
            ['table_prefix', $old->prefix, $new->prefix, false],
        ] as [$name, $before, $after, $secret]) {
            if ($before !== $after) {
                $add($name, $after, $before, $after, $secret);
            }
        }

        // the constants that override the address of the database
        $config = WordPressConfigFile::fromPath($source->configPath);
        foreach (['WP_HOME', 'WP_SITEURL'] as $constant) {
            $value = $config->get($constant);
            if (!is_string($value) || $value === '') {
                continue;
            }
            [$address, $notice] = self::moved($value, $request);
            if ($address === $value) {
                if ($notice !== null) {
                    $add($constant, null, $value, $value, notice: $notice);
                }

                continue;
            }
            $add($constant, $address, $value, $address);
        }

        // the absolute paths of the old host
        foreach ($config->pathSettings() as $name => $oldRoot) {
            if ($oldRoot !== rtrim($request->serverPath(), '/\\')) {
                $found[] = ['name' => $name, 'value' => null, 'change' => Change::update('file wp-config.php ' . $name, self::describe($name), $oldRoot, $request->serverPath())];
            }
        }

        return $found;
    }

    /**
     * The new address of a constant: the host of the mapping and, if the request has a base path, that path; an address whose host is not part
     * of the move is left alone and reported, because it would send visitors somewhere else (and a constant cannot be left out).
     *
     * @return array{0: string, 1: string|null} the value, and a warning if it was not moved
     */
    public static function moved(string $address, MigrationRequest $request): array
    {
        $parts = parse_url($address);
        if (!is_array($parts) || !isset($parts['host'])) {
            return [$address, null];
        }
        $host = strtolower($parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''));
        $mapping = $request->mappingFrom($host);
        if ($mapping === null) {
            return [$address, $request->domains === [] || $request->isTargetDomain($host) ? null : "The constant names \"$address\", which is not part of the move; it is left as it is (it keeps the site on that address)."];
        }
        $scheme = $mapping->scheme ?? ($parts['scheme'] ?? 'http');

        return [$scheme . '://' . $mapping->to . $mapping->toPath . ($mapping->toPath === '' ? ($parts['path'] ?? '') : ''), null];
    }

    private static function describe(string $name): string
    {
        return match ($name) {
            'WP_HOME', 'WP_SITEURL' => 'follow the new address',
            'DB_PASSWORD' => 'point the site to the database it has now',
            'table_prefix' => 'the table prefix of the database it has now',
            'DB_HOST', 'DB_USER', 'DB_NAME' => 'point the site to the database it has now',
            default => 'put the path under the directory of the site',
        };
    }
}
