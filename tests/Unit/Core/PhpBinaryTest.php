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
    public function testTheNoteSaysWhoseProgramItMeansWhatIsAffectedWhatIsNotAndWhatToDo(): void
    {
        $note = PhpBinary::missingNote();

        self::assertStringStartsWith('Not found by the PHP of the web interface', $note, 'whose PHP it is about');
        self::assertStringContainsString('proc_open', $note, 'the cause');
        self::assertStringContainsString('only for the trigger address', $note, 'what is affected');
        self::assertStringContainsString('"ticks"', $note, 'what happens instead');
        self::assertStringContainsString('A cron job that runs bin/threadsafe cron itself is not affected', $note, 'what is not, and the better way');
        self::assertStringContainsString('work as before', $note, 'what still works');
        self::assertStringContainsString('ask the host', $note, 'what to do for the web');
        self::assertStringNotContainsString('later phase', $note);
    }

    public function testWithoutThePhpBinaryTheCheckOfTheInstallationIsANoteNotAWarning(): void
    {
        // the host of the tests has one (it is a shell), so the note is looked at in the code that makes the check
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Core/Environment.php');

        self::assertStringContainsString("Check::info('PHP command line', PhpBinary::missingNote())", $source);
    }

    public function testInAShellTheProgramIsTheRunningOne(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is disabled here.');
        }

        self::assertSame(PHP_BINARY, PhpBinary::cli());
    }
}
