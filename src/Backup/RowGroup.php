<?php

declare(strict_types=1);

namespace ThreadSafe\Backup;

/**
 * Rows waiting to be written as one multi-row INSERT statement; written when they add up to the size a statement should
 * have, or on request.
 */
final class RowGroup
{
    /** @var list<string> */
    private array $rows = [];

    private int $bytes = 0;

    public function __construct(private readonly string $head, private readonly int $statementBytes, private readonly AppendFile $out)
    {
    }

    /** Adds a row (its values in parentheses) and writes the statement if it has reached its size. */
    public function add(string $row): void
    {
        $this->rows[] = $row;
        $this->bytes += strlen($row);
        if ($this->bytes >= $this->statementBytes) {
            $this->flush();
        }
    }

    /** Writes what is waiting as one statement. */
    public function flush(): void
    {
        if ($this->rows === []) {
            return;
        }
        $this->out->write($this->head . implode(",\n", $this->rows) . ";\n");
        $this->rows = [];
        $this->bytes = 0;
    }
}
