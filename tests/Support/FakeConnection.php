<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Support;

use Closure;
use ReflectionClass;
use ThreadSafe\Database\Connection;
use ThreadSafe\Database\DatabaseError;
use ThreadSafe\Database\DatabaseSettings;
use ThreadSafe\Database\Driver;
use ThreadSafe\Database\ServerInfo;

/**
 * A {@see Connection} that answers from a table of canned results, for unit tests of code that only reads: a rule is a regular
 * expression for the SQL text and the rows to give back for it. A statement that no rule matches is an error, so that a test says
 * which query it expects. The statements that ran are kept in {@see $log}.
 */
final class FakeConnection implements Driver
{
    /** @var list<string> */
    public array $log = [];

    /**
     * @param array<string, list<array<string, mixed>>> $rules regular expression => rows
     */
    private function __construct(private readonly array $rules)
    {
    }

    /**
     * @param array<string, list<array<string, mixed>>> $rules
     * @param list<string>|null                           $log   receives the statements that ran (pass a variable)
     *
     * @param-out list<string> $log
     */
    public static function with(array $rules, string $prefix = 'jos_', ?array &$log = null): Connection
    {
        $driver = new self($rules);
        $log = [];
        $driver->log = &$log;
        $connection = (new ReflectionClass(Connection::class))->newInstanceWithoutConstructor();
        Closure::bind(function () use ($driver, $prefix): void {
            // the closure runs in the scope of Connection (bound below), where its readonly properties may be set
            $this->driver = $driver; // @phpstan-ignore property.readOnlyAssignOutOfClass
            $this->settings = new DatabaseSettings('fake', 3306, 'fake', 'fake', 'fake', $prefix); // @phpstan-ignore property.readOnlyAssignOutOfClass
            $this->server = new ServerInfo(ServerInfo::MARIADB, '10.11.0', '10.11.0-MariaDB'); // @phpstan-ignore property.readOnlyAssignOutOfClass
        }, $connection, Connection::class)();

        return $connection;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function answer(string $sql): array
    {
        $this->log[] = $sql;
        foreach ($this->rules as $pattern => $rows) {
            if (preg_match($pattern, $sql) === 1) {
                return $rows;
            }
        }

        throw new DatabaseError('The fake connection has no answer for: ' . $sql);
    }

    public function name(): string
    {
        return 'fake';
    }

    public function select(string $sql, array $parameters): array
    {
        return $this->answer($sql);
    }

    public function scalar(string $sql, array $parameters): mixed
    {
        $rows = $this->answer($sql);

        return $rows === [] ? null : reset($rows[0]);
    }

    public function execute(string $sql, array $parameters): int
    {
        return 0;
    }

    public function stream(string $sql, array $parameters): \Generator
    {
        yield from $this->answer($sql);
    }

    public function run(string $sql): void
    {
        $this->log[] = $sql;
    }

    public function warnings(): array
    {
        return [];
    }

    public function lastInsertId(): int
    {
        return 0;
    }

    public function beginSnapshot(): void
    {
    }

    public function endSnapshot(): void
    {
    }

    public function begin(): void
    {
    }

    public function commit(): void
    {
    }

    public function rollBack(): void
    {
    }

    public function inTransaction(): bool
    {
        return false;
    }

    public function quoteString(string $value): string
    {
        return "'" . addslashes($value) . "'";
    }
}
