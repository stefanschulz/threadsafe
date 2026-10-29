<?php

declare(strict_types=1);

namespace ThreadSafe\WordPress\Steps;

use ThreadSafe\Core\Change;
use ThreadSafe\Core\Context;
use ThreadSafe\Core\Plan;
use ThreadSafe\Core\Step;
use ThreadSafe\Core\StepResult;
use ThreadSafe\Database\Connection;
use ThreadSafe\WordPress\State;

/**
 * Two kinds of rows of a WordPress database are keyed by the table prefix: the option `<prefix>user_roles` and, in `usermeta`, every key that
 * begins with the prefix (`<prefix>capabilities`, `<prefix>user_level`, `<prefix>user-settings` ...). When a restore puts the tables under
 * another prefix, the importer renames the tables and these rows must follow: without it every user loses the rights and the login of the
 * administrator fails (docs/PHASE9.md 3.5, step 4). Only when the prefix changes.
 *
 * A key is compared byte for byte and by its beginning (a prefix may contain `_`, which is a wildcard in `LIKE`). A new prefix that begins with
 * the old one (`wp_` to `wp_2_`) does not touch the keys that already carry the new one, so that running it again does no harm.
 */
final class FixTablePrefix implements Step
{
    public function id(): string
    {
        return 'fix-table-prefix';
    }

    public function title(): string
    {
        return 'Move the rows that are keyed by the table prefix';
    }

    public function plan(Context $context): Plan
    {
        $plan = new Plan();
        $source = State::source($context);
        if (!$source->prefixChanges()) {
            return $plan;
        }
        $db = State::connection($context);
        $old = $source->configuredDatabase->prefix;
        $new = $source->database->prefix;
        $roles = $this->roles($db, $old, $new);
        if ($roles > 0) {
            $plan->add(Change::update('table ' . $db->table('options') . ' option_name', "rename {$old}user_roles to {$new}user_roles", count: $roles));
        }
        $keys = $this->keys($db, $old, $new);
        if ($keys > 0) {
            $plan->add(Change::update('table ' . $db->table('usermeta') . ' meta_key', "put the keys that begin with \"$old\" under \"$new\"", count: $keys));
        }

        return $plan;
    }

    public function apply(Context $context, Plan $plan): StepResult
    {
        $source = State::source($context);
        if (!$source->prefixChanges()) {
            return new StepResult(0);
        }
        $db = State::connection($context);
        $old = $source->configuredDatabase->prefix;
        $new = $source->database->prefix;
        $applied = 0;

        if ($this->roles($db, $old, $new) > 0) {
            $applied += $db->execute(
                'UPDATE ' . $db->quote($db->table('options')) . ' SET option_name = ? WHERE option_name = ?',
                [$new . 'user_roles', $old . 'user_roles'],
            );
            $context->journal->note('option renamed', ['from' => $old . 'user_roles', 'to' => $new . 'user_roles']);
        }
        if ($this->keys($db, $old, $new) > 0) {
            [$condition, $parameters] = $this->condition($old, $new);
            $applied += $db->execute(
                'UPDATE ' . $db->quote($db->table('usermeta')) . ' SET meta_key = CONCAT(?, SUBSTRING(meta_key, ?)) WHERE ' . $condition,
                [$new, strlen($old) + 1, ...$parameters],
            );
            $context->journal->note('usermeta keys moved', ['from' => $old, 'to' => $new, 'rows' => $applied]);
        }

        return new StepResult($applied);
    }

    /** How many rows of `options` still carry the old name of the roles (and no row has the new one). */
    private function roles(Connection $db, string $old, string $new): int
    {
        $table = $db->quote($db->table('options'));
        $has = static fn (string $name): int => (int) $db->scalar("SELECT COUNT(*) FROM $table WHERE option_name = ?", [$name]);

        return $has($new . 'user_roles') === 0 ? $has($old . 'user_roles') : 0;
    }

    /** How many keys of `usermeta` begin with the old prefix (and not already with the new one). */
    private function keys(Connection $db, string $old, string $new): int
    {
        [$condition, $parameters] = $this->condition($old, $new);

        return (int) $db->scalar('SELECT COUNT(*) FROM ' . $db->quote($db->table('usermeta')) . ' WHERE ' . $condition, $parameters);
    }

    /**
     * The condition for the keys that have to move, and its values in order.
     *
     * @return array{0: string, 1: list<int|string>}
     */
    private function condition(string $old, string $new): array
    {
        $parameters = [strlen($old), $old];
        $condition = 'BINARY LEFT(meta_key, ?) = BINARY ?';
        if (str_starts_with($new, $old)) {
            // the new prefix begins with the old: a key that already has the new one is done
            $condition .= ' AND NOT (BINARY LEFT(meta_key, ?) = BINARY ?)';
            $parameters[] = strlen($new);
            $parameters[] = $new;
        }

        return [$condition, $parameters];
    }
}
