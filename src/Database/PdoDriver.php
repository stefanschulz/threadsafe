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
                    // one statement per call, as with mysqli: the importer runs what the splitter calls one statement
                    PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
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
        return array_values($this->prepared($sql, $parameters)->fetchAll());
    }

    public function scalar(string $sql, array $parameters): mixed
    {
        $value = $this->prepared($sql, $parameters)->fetchColumn();

        return $value === false ? null : $value;
    }

    public function execute(string $sql, array $parameters): int
    {
        return $this->prepared($sql, $parameters)->rowCount();
    }

    public function stream(string $sql, array $parameters): \Generator
    {
        $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        $statement = null;
        try {
            $statement = $this->prepared($sql, $parameters);
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

    public function run(string $sql): void
    {
        try {
            $this->pdo->exec($sql);
        } catch (PDOException $e) {
            throw new DatabaseError($e->getMessage(), 0, $e);
        }
    }

    public function warnings(): array
    {
        // MySQL refuses to prepare SHOW WARNINGS, and the attempt wipes the very warnings that are asked for: send it as text
        $emulated = $this->pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES);
        try {
            $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
            $statement = $this->pdo->query('SHOW WARNINGS');
            $warnings = [];
            foreach ($statement === false ? [] : $statement->fetchAll() as $row) {
                $warnings[] = ['level' => (string) ($row['Level'] ?? ''), 'code' => (int) ($row['Code'] ?? 0), 'message' => (string) ($row['Message'] ?? '')];
            }

            return $warnings;
        } catch (PDOException $e) {
            throw new DatabaseError($e->getMessage(), 0, $e);
        } finally {
            $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, $emulated);
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
    private function prepared(string $sql, array $parameters): PDOStatement
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
