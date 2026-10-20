<?php

declare(strict_types=1);

namespace ThreadSafe\Database;

/**
 * How ThreadSafe talks to a MariaDB or MySQL server: one implementation per PHP interface ({@see PdoDriver},
 * {@see MysqliDriver}). Parameters are always passed as strings (or null); errors are thrown as {@see DatabaseError}.
 */
interface Driver
{
    /** "pdo" or "mysqli". */
    public function name(): string;

    /**
     * @param list<scalar|null> $parameters
     *
     * @return list<array<string, mixed>>
     *
     * @throws DatabaseError
     */
    public function select(string $sql, array $parameters): array;

    /**
     * First column of the first row, or null if there is none.
     *
     * @param list<scalar|null> $parameters
     *
     * @throws DatabaseError
     */
    public function scalar(string $sql, array $parameters): mixed;

    /**
     * Runs a statement and returns the number of rows it changed.
     *
     * @param list<scalar|null> $parameters
     *
     * @throws DatabaseError
     */
    public function execute(string $sql, array $parameters): int;

    public function lastInsertId(): int;

    /** @throws DatabaseError */
    public function begin(): void;

    /** @throws DatabaseError */
    public function commit(): void;

    /** @throws DatabaseError */
    public function rollBack(): void;

    public function inTransaction(): bool;

    /** A value as a quoted SQL string literal. */
    public function quoteString(string $value): string;
}
