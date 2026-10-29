<?php

declare(strict_types=1);

namespace ThreadSafe\WordPress\Steps;

use RuntimeException;
use ThreadSafe\Core\AtomicFile;
use ThreadSafe\Core\Change;
use ThreadSafe\Core\Context;
use ThreadSafe\Core\Exception\GuardFailed;
use ThreadSafe\Core\Notice;
use ThreadSafe\Core\Plan;
use ThreadSafe\Core\Step;
use ThreadSafe\Core\StepResult;
use ThreadSafe\WordPress\Config\WordPressConfigFile;
use ThreadSafe\WordPress\Installation;
use ThreadSafe\WordPress\State;

/**
 * Makes a `wp-config.php` where the site has none: a backup archives the site directory, so a configuration that lay one directory above it
 * is not in it (docs/PHASE9.md section 9, work package 3). The file is made from `wp-config-sample.php`, which every WordPress ships, with
 * the database of the request and **new keys and salts** (every login of the old site ends, which is what a new file means). A site that
 * has its `wp-config.php` is left alone.
 */
final class EnsureConfiguration implements Step
{
    private const string SAMPLE = 'wp-config-sample.php';

    /** the keys and salts of WordPress: eight constants that are each a long random string */
    private const array SALTS = ['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'];

    public function id(): string
    {
        return 'ensure-configuration';
    }

    public function title(): string
    {
        return 'Make wp-config.php if the site has none';
    }

    public function plan(Context $context): Plan
    {
        $plan = new Plan();
        $root = $this->root($context);
        if (Installation::configuration($root) !== null) {
            return $plan;
        }
        $this->mustBeMakeable($context, $root);

        return $plan->add(Change::create('file wp-config.php', 'make it from ' . self::SAMPLE . ' with the database of the request and new keys and salts'))
            ->notice(Notice::warning('The site has no wp-config.php (it lay above the site directory and is not part of a backup): a new one is made, with new keys, so that every login of the old site ends.', 'wp-config.php'));
    }

    public function apply(Context $context, Plan $plan): StepResult
    {
        $root = $this->root($context);
        if (Installation::configuration($root) !== null) {
            return new StepResult(0);
        }
        $this->mustBeMakeable($context, $root);
        $database = State::request($context)->database ?? throw new GuardFailed('wp-config.php cannot be made without the database.');
        $sample = @file_get_contents($root . '/' . self::SAMPLE);
        if ($sample === false) {
            throw new RuntimeException('Cannot read ' . self::SAMPLE . '.');
        }
        $file = WordPressConfigFile::fromString($sample)->withDatabase($database);
        foreach (self::SALTS as $name) {
            $file = $file->withIfPresent($name, self::phrase());
        }
        $path = $root . '/wp-config.php';
        $file->writeTo($path);
        @chmod($path, 0640);
        $context->journal->file($path, 'create');

        return new StepResult(1);
    }

    private function root(Context $context): string
    {
        return rtrim(State::request($context)->root, '/\\');
    }

    /** @throws GuardFailed there is no sample to make it from, or no database to put into it */
    private function mustBeMakeable(Context $context, string $root): void
    {
        if (!is_file($root . '/' . self::SAMPLE)) {
            throw new GuardFailed("\"$root\" has no wp-config.php and no " . self::SAMPLE . ' to make one from.');
        }
        if (State::request($context)->database === null) {
            throw new GuardFailed('The site has no wp-config.php: give the database (host, name, user, password, prefix), so that one can be made.');
        }
    }

    /** A key or a salt: 64 characters, none of which needs escaping in a PHP string. */
    private static function phrase(): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!#%&()*+,-./:;<=>?@[]^_{}|~';
        $phrase = '';
        for ($i = 0; $i < 64; ++$i) {
            $phrase .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $phrase;
    }
}
