<?php

declare(strict_types=1);

namespace ThreadSafe\Restore;

/**
 * One statement of a SQL file as {@see SqlSplitter} hands it out.
 */
final readonly class SqlStatement
{
    /**
     * @param string $text      the statement without its delimiter (leading comments are part of it)
     * @param int    $end       byte offset in the file right after the statement and its delimiter
     * @param string $delimiter the delimiter in effect after the statement (changes with the DELIMITER command); a restart at
     *                          $end needs it
     */
    public function __construct(public string $text, public int $end, public string $delimiter)
    {
    }
}
