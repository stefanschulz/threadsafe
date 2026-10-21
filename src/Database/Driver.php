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

    /**
     * Runs a SELECT and hands out the rows one by one without holding the whole result in memory. The connection cannot
     * run another statement until the generator is finished or discarded.
     *
     * @param list<scalar|null> $parameters
     *
     * @return \Generator<int, array<string, mixed>>
     *
     * @throws DatabaseError
     */
    public function stream(string $sql, array $parameters): \Generator;

    /**
     * Runs one statement as it is, without preparing it and without parameters (text protocol): for SQL that comes from a
     * dump, which can hold statements that cannot be prepared (triggers ...). Results, if any, are discarded.
     *
     * @throws DatabaseError
     */
    public function run(string $sql): void;

    /**
     * The warnings the server gave for the last statement run with {@see run()} (`SHOW WARNINGS`, which MySQL cannot prepare and
     * which therefore does not go through {@see select()}).
     *
     * @return list<array{level: string, code: int, message: string}>
     *
     * @throws DatabaseError
     */
    public function warnings(): array;

    public function lastInsertId(): int;

    /**
     * Starts a transaction that sees the data as of this moment (`START TRANSACTION WITH CONSISTENT SNAPSHOT`).
     *
     * @throws DatabaseError
     */
    public function beginSnapshot(): void;

    /** Ends what {@see beginSnapshot()} started. */
    public function endSnapshot(): void;

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
