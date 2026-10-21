<?php

declare(strict_types=1);

namespace ThreadSafe\Restore;

use InvalidArgumentException;

/**
 * Changes the table prefix while a dump is imported, by rewriting the table name in the four kinds of statement that
 * {@see \ThreadSafe\Backup\Dumper} generates for a table: `DROP TABLE IF EXISTS`, `CREATE TABLE`, `INSERT INTO` and `UPDATE`
 * (the continuation of a cell that was too big for one INSERT). Nothing else is touched - the data is not parsed - so a
 * dump is only safe to rewrite when no other statement names a table: views, triggers and foreign keys do, which is why
 * {@see obstacle()} and {@see Importer} refuse those (docs/PHASE3.md, section 8).
 *
 * Tables whose name does not begin with the old prefix keep their name.
 */
final class PrefixRewriter
{
    /** Comments and blanks that may precede a statement (but not an executable comment). */
    private const string LEAD = '(?:\s+|--[^\n]*\n|#[^\n]*\n|/\*(?!!).*?\*/)*';

    /**
     * @throws InvalidArgumentException a prefix with other characters than letters, digits and underscore
     */
    public function __construct(public readonly string $from, public readonly string $to)
    {
        foreach ([$from, $to] as $prefix) {
            if (preg_match('/^[A-Za-z0-9_]*$/', $prefix) !== 1) {
                throw new InvalidArgumentException('A table prefix may only hold letters, digits and underscores.');
            }
        }
    }

    public function changes(): bool
    {
        return $this->from !== $this->to;
    }

    public function rewrite(string $statement): string
    {
        if (!$this->changes()) {
            return $statement;
        }
        $pattern = '~\A(' . self::LEAD . ')(DROP TABLE IF EXISTS |CREATE TABLE |INSERT INTO |UPDATE )`' . preg_quote($this->from, '~') . '~s';
        if (preg_match($pattern, $statement, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return $statement;
        }

        return $m[1][0] . $m[2][0] . '`' . $this->to . substr($statement, strlen($m[0][0]));
    }

    /**
     * Whether this statement makes a prefix change unsafe: a `CREATE TABLE` with a foreign key (it names another table),
     * a table that unites others (`UNION=`), a view or a trigger.
     *
     * @return string|null the reason, or null if the statement is fine
     */
    public static function obstacle(string $statement): ?string
    {
        if (preg_match('~\A' . self::LEAD . 'CREATE\b(\s+TABLE\s)?~is', $statement, $m) !== 1) {
            return null;
        }
        if (($m[1] ?? '') === '') {
            return 'the dump contains views, triggers or routines, which name tables';
        }
        // without the quoted parts (names, strings, comments) only SQL keywords are left
        $bare = (string) preg_replace('~\'(?:[^\'\\\\]|\\\\.|\'\')*+\'|"(?:[^"\\\\]|\\\\.|"")*+"|`[^`]*+`~s', '``', $statement);
        if (preg_match('~\b(FOREIGN\s+KEY|REFERENCES)\b~i', $bare) === 1) {
            return 'the dump contains foreign keys, which name tables';
        }
        if (preg_match('~\bUNION\s*=~i', $bare) === 1) {
            return 'the dump contains a table that unites other tables';
        }

        return null;
    }

    /**
     * A short description of a statement for messages: its kind and the table, never its values (they can be customer data).
     */
    public static function describe(string $statement): string
    {
        if (preg_match('~\A' . self::LEAD . '((?:DROP TABLE IF EXISTS|CREATE TABLE|INSERT INTO|UPDATE|DROP VIEW IF EXISTS|DROP TRIGGER IF EXISTS) `(?:[^`]|``)*`)~is', $statement, $m) === 1) {
            return $m[1];
        }
        if (preg_match('~\A' . self::LEAD . '([A-Za-z ]{1,40})~s', $statement, $m) === 1) {
            return trim($m[1]);
        }

        return 'a statement';
    }
}
