<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Admin\View;

/**
 * The warnings of a backup as a table of findings, like the checks (field test: they were a bare list).
 */
final class ViewWarningsTest extends TestCase
{
    public function testTheWarningsAreATableWithTheSubjectInItsOwnPlace(): void
    {
        $html = View::warnings(['PHP: memory_limit is low', 'A file could not be read: /var/x'], 20);

        self::assertStringContainsString('<h2>Warnings (2)</h2>', $html);
        self::assertStringContainsString('<table class="checks">', $html);
        self::assertSame(2, substr_count($html, '<tr class="warning">'));
        self::assertStringContainsString('<strong>PHP:</strong> memory_limit is low', $html);
        self::assertStringContainsString('<strong>A file could not be read:</strong> /var/x', $html);
        self::assertStringNotContainsString('<ul>', $html);
    }

    public function testAWarningWithoutAShortSubjectIsOneCell(): void
    {
        $html = View::warnings(['3 more warnings are not listed.', 'A long sentence that only has a colon far too late in it to be a subject: yes'], 20);

        self::assertStringNotContainsString('<strong>', $html);
        self::assertStringContainsString('3 more warnings are not listed.', $html);
    }

    public function testMoreThanTheLimitIsCountedNotListed(): void
    {
        $html = View::warnings(['a: 1', 'b: 2', 'c: 3', 'd: 4'], 2);

        self::assertStringContainsString('Warnings (4)', $html);
        self::assertSame(2, substr_count($html, '<tr class="warning">'));
        self::assertStringContainsString('<tr class="info">', $html);
        self::assertStringContainsString('... and 2 more', $html);
    }

    public function testTextIsEscaped(): void
    {
        $html = View::warnings(['PHP: <script>alert(1)</script>'], 5);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }
}
