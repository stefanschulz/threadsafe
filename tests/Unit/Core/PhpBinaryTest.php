<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Core\PhpBinary;

/**
 * The PHP command line program that a scheduled backup is started with, and the note for a host that has none.
 */
final class PhpBinaryTest extends TestCase
{
    public function testTheNoteSaysWhatIsMissingWhatStillWorksAndWhatToDo(): void
    {
        $note = PhpBinary::missingNote();

        self::assertStringContainsString('proc_open', $note, 'the cause');
        self::assertStringContainsString('work as before', $note, 'what still works');
        self::assertStringContainsString('scheduled backup that runs as a process of its own', $note, 'what is missing');
        self::assertStringContainsString('"ticks"', $note, 'what happens instead');
        self::assertStringContainsString('ask the host', $note, 'what to do');
        self::assertStringContainsString('you can ignore this', $note, 'and when nothing is needed');
        self::assertStringNotContainsString('later phase', $note);
    }

    public function testInAShellTheProgramIsTheRunningOne(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is disabled here.');
        }

        self::assertSame(PHP_BINARY, PhpBinary::cli());
    }
}
