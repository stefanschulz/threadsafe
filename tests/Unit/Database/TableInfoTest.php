<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Database;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Database\TableInfo;

final class TableInfoTest extends TestCase
{
    public function testAVarcharIsCountedInCharactersAndATextTypeInBytes(): void
    {
        $table = new TableInfo('t', ['u', 'n', 'free'], ['id'], 0, ['u' => ['chars', 5], 'n' => ['bytes', 5]]);

        self::assertFalse($table->tooLong('u', 'abcde'));
        self::assertTrue($table->tooLong('u', 'abcdef'));
        self::assertFalse($table->tooLong('u', 'äöüäö'), 'five characters of two bytes each fit into varchar(5)');
        self::assertFalse($table->tooLong('n', 'abcde'));
        self::assertTrue($table->tooLong('n', 'äöü'), 'six bytes do not fit into five');
    }

    public function testAColumnWithoutAKnownLimitIsNeverTooLong(): void
    {
        self::assertFalse((new TableInfo('t', ['c'], ['id'], 0))->tooLong('c', str_repeat('x', 100000)));
    }
}