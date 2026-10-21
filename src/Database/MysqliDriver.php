<?php

declare(strict_types=1);

namespace ThreadSafe\Database;

use mysqli;
use mysqli_sql_exception;
use mysqli_stmt;

/**
 * The driver for the PHP extension `mysqli` (with mysqlnd, which every PHP 8.4 has). For hosting without `pdo_mysql`.
 *
 * Every statement is prepared and its parameters are bound as strings, like PDO does with native prepared statements,
 * so that both drivers behave the same.
 */
final class MysqliDriver implements Driver
{
    private bool $inTransaction = false;

    private function __construct(private readonly mysqli $mysqli)
    {
    }

    /**
     * @throws ConnectionFailed
     */
    public static function connect(DatabaseSettings $settings): self
    {
        if (!extension_loaded('mysqli')) {
            throw new ConnectionFailed('The PHP extension mysqli is not loaded (use the pdo interface or install mysqli).');
        }

        // Errors as exceptions (the default of PHP 8.1 and later, set here so that it does not depend on php.ini).
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        try {
            $mysqli = new mysqli($settings->host, $settings->user, $settings->password, $settings->name, $settings->port);
            $mysqli->set_charset('utf8mb4');
        } catch (mysqli_sql_exception $e) {
            throw new ConnectionFailed(PdoDriver::describe($settings, $e->getMessage()), 0, $e);
        }

        return new self($mysqli);
    }

    public function name(): string
    {
        return 'mysqli';
    }

    public function select(string $sql, array $parameters): array
    {
        $statement = $this->prepared($sql, $parameters);
        try {
            $result = $statement->get_result();

            return $result === false ? [] : $result->fetch_all(MYSQLI_ASSOC);
        } catch (mysqli_sql_exception $e) {
            throw new DatabaseError($e->getMessage(), 0, $e);
        } finally {
            $statement->close();
        }
    }

    public function scalar(string $sql, array $parameters): mixed
    {
        $statement = $this->prepared($sql, $parameters);
        try {
            $result = $statement->get_result();
            $row = $result === false ? null : $result->fetch_row();

            return $row === null ? null : $row[0];
        } catch (mysqli_sql_exception $e) {
            throw new DatabaseError($e->getMessage(), 0, $e);
        } finally {
            $statement->close();
        }
    }

    public function execute(string $sql, array $parameters): int
    {
        $statement = $this->prepared($sql, $parameters);
        try {
            $rows = $statement->affected_rows;

            return is_int($rows) ? max(0, $rows) : 0;
        } finally {
            $statement->close();
        }
    }

    public function stream(string $sql, array $parameters): \Generator
    {
        $statement = $this->prepared($sql, $parameters);
        try {
            $meta = $statement->result_metadata();
            if ($meta === false) {
                return;
            }
            $names = array_map(static fn (object $field): string => $field->name, $meta->fetch_fields());
            $meta->free();
            $values = array_fill(0, count($names), null);
            $references = [];
            foreach (array_keys($values) as $i) {
                $references[$i] = &$values[$i];
            }
            $statement->bind_result(...$references);
            while ($statement->fetch()) {
                $row = [];
                foreach ($names as $i => $name) {
                    $row[$name] = $values[$i];
                }
                yield $row;
            }
        } catch (mysqli_sql_exception $e) {
            throw new DatabaseError($e->getMessage(), 0, $e);
        } finally {
            $statement->close();
        }
    }

    public function run(string $sql): void
    {
        try {
            $this->mysqli->real_query($sql);
            if ($this->mysqli->field_count > 0) {
                $result = $this->mysqli->use_result();
                if ($result !== false) {
                    $result->free();
                }
            }
        } catch (mysqli_sql_exception $e) {
            throw new DatabaseError($e->getMessage(), 0, $e);
        }
    }

    public function lastInsertId(): int
    {
        return (int) $this->mysqli->insert_id;
    }

    public function beginSnapshot(): void
    {
        try {
            $this->mysqli->begin_transaction(MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
        } catch (mysqli_sql_exception $e) {
            throw new DatabaseError($e->getMessage(), 0, $e);
        }
    }

    public function endSnapshot(): void
    {
        try {
            $this->mysqli->commit();
        } catch (mysqli_sql_exception $e) {
            throw new DatabaseError($e->getMessage(), 0, $e);
        }
    }

    public function begin(): void
    {
        try {
            $this->mysqli->begin_transaction();
        } catch (mysqli_sql_exception $e) {
            throw new DatabaseError($e->getMessage(), 0, $e);
        }
        $this->inTransaction = true;
    }

    public function commit(): void
    {
        $this->inTransaction = false;
        try {
            $this->mysqli->commit();
        } catch (mysqli_sql_exception $e) {
            throw new DatabaseError($e->getMessage(), 0, $e);
        }
    }

    public function rollBack(): void
    {
        $this->inTransaction = false;
        try {
            $this->mysqli->rollback();
        } catch (mysqli_sql_exception $e) {
            throw new DatabaseError($e->getMessage(), 0, $e);
        }
    }

    public function inTransaction(): bool
    {
        return $this->inTransaction;
    }

    public function quoteString(string $value): string
    {
        return "'" . $this->mysqli->real_escape_string($value) . "'";
    }

    /**
     * Prepares and executes; the caller reads the result and closes the statement.
     *
     * @param list<scalar|null> $parameters
     */
    private function prepared(string $sql, array $parameters): mysqli_stmt
    {
        try {
            $statement = $this->mysqli->prepare($sql);
            if ($statement === false) {
                throw new DatabaseError($this->mysqli->error);
            }
            try {
                if ($parameters !== []) {
                    $values = array_map(static fn (mixed $v): ?string => $v === null ? null : (is_bool($v) ? (string) (int) $v : (string) $v), $parameters);
                    $statement->bind_param(str_repeat('s', count($values)), ...$values);
                }
                $statement->execute();
            } catch (mysqli_sql_exception $e) {
                $statement->close();

                throw $e;
            }
        } catch (mysqli_sql_exception $e) {
            throw new DatabaseError($e->getMessage(), 0, $e);
        }

        return $statement;
    }
}
