<?php

declare(strict_types=1);

namespace ThreadSafe\Database;

use PDO;
use PDOException;
use PDOStatement;

/**
 * The driver for the PHP extension `pdo_mysql` (it serves MariaDB as well as MySQL; the DSN prefix is "mysql:").
 */
final class PdoDriver implements Driver
{
    private function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @throws ConnectionFailed
     */
    public static function connect(DatabaseSettings $settings): self
    {
        if (!extension_loaded('pdo_mysql')) {
            throw new ConnectionFailed('The PHP extension pdo_mysql is not loaded (use the mysqli interface or install pdo_mysql).');
        }

        try {
            return new self(new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $settings->host, $settings->port, $settings->name),
                $settings->user,
                $settings->password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ],
            ));
        } catch (PDOException $e) {
            throw new ConnectionFailed(self::describe($settings, $e->getMessage()), 0, $e);
        }
    }

    public static function describe(DatabaseSettings $settings, string $reason): string
    {
        return sprintf('Cannot connect to database "%s" on %s:%d as "%s": %s', $settings->name, $settings->host, $settings->port, $settings->user, $reason);
    }

    public function name(): string
    {
        return 'pdo';
    }

    public function select(string $sql, array $parameters): array
    {
        return array_values($this->run($sql, $parameters)->fetchAll());
    }

    public function scalar(string $sql, array $parameters): mixed
    {
        $value = $this->run($sql, $parameters)->fetchColumn();

        return $value === false ? null : $value;
    }

    public function execute(string $sql, array $parameters): int
    {
        return $this->run($sql, $parameters)->rowCount();
    }

    public function stream(string $sql, array $parameters): \Generator
    {
        $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        $statement = null;
        try {
            $statement = $this->run($sql, $parameters);
            while (($row = $statement->fetch()) !== false) {
                yield $row;
            }
        } catch (PDOException $e) {
            throw new DatabaseError($e->getMessage(), 0, $e);
        } finally {
            $statement?->closeCursor();
            $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        }
    }

    public function lastInsertId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    public function beginSnapshot(): void
    {
        try {
            $this->pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        } catch (PDOException $e) {
            throw new DatabaseError($e->getMessage(), 0, $e);
        }
    }

    public function endSnapshot(): void
    {
        try {
            $this->pdo->exec('COMMIT');
        } catch (PDOException $e) {
            throw new DatabaseError($e->getMessage(), 0, $e);
        }
    }

    public function begin(): void
    {
        try {
            $this->pdo->beginTransaction();
        } catch (PDOException $e) {
            throw new DatabaseError($e->getMessage(), 0, $e);
        }
    }

    public function commit(): void
    {
        try {
            $this->pdo->commit();
        } catch (PDOException $e) {
            throw new DatabaseError($e->getMessage(), 0, $e);
        }
    }

    public function rollBack(): void
    {
        try {
            $this->pdo->rollBack();
        } catch (PDOException $e) {
            throw new DatabaseError($e->getMessage(), 0, $e);
        }
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    public function quoteString(string $value): string
    {
        return $this->pdo->quote($value);
    }

    /**
     * @param list<scalar|null> $parameters
     */
    private function run(string $sql, array $parameters): PDOStatement
    {
        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($parameters);
        } catch (PDOException $e) {
            throw new DatabaseError($e->getMessage(), 0, $e);
        }

        return $statement;
    }
}
