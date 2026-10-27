<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Web;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Restore\Check;
use ThreadSafe\Web\Html;

/**
 * The findings of the wizards as a table: a symbol and the written name of the level, the finding next to it.
 */
final class HtmlChecksTest extends TestCase
{
    public function testEveryLevelGetsItsOwnSymbolAndItsWrittenName(): void
    {
        $html = Html::checkTable([
            ['level' => 'info', 'subject' => 'PHP', 'message' => 'is 8.4', 'hint' => ''],
            ['level' => 'warning', 'subject' => 'Memory', 'message' => 'is low', 'hint' => ''],
            ['level' => 'error', 'subject' => 'Home', 'message' => 'cannot be made', 'hint' => ''],
            ['level' => 'ok', 'subject' => 'Zip', 'message' => 'is there', 'hint' => ''],
        ]);

        self::assertSame(4, substr_count($html, '<svg class="sym"'));
        self::assertMatchesRegularExpression('#<tr class="info"><td class="level"><svg[^>]*>.*?</svg><span>Note</span></td><td><strong>PHP:</strong> is 8\.4</td></tr>#s', $html);
        self::assertStringContainsString('<span>Warning</span>', $html);
        self::assertStringContainsString('<span>Problem</span>', $html);
        self::assertStringContainsString('<span>OK</span>', $html);
        self::assertSame(4, substr_count($html, 'aria-hidden="true"'), 'the symbol is decoration, the name is the information');
    }

    public function testNothingInTheTableCanCarryAStyleAttributeOrScript(): void
    {
        $html = Html::checkTable([['level' => 'warning', 'subject' => '<b>x</b>', 'message' => '<script>alert(1)</script>', 'hint' => '"quoted"']]);

        self::assertStringNotContainsString(' style=', $html, 'the pages allow no style attribute');
        self::assertStringNotContainsString('<script', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringContainsString('&quot;quoted&quot;', $html);
    }

    public function testAnUnknownLevelIsShownAsANote(): void
    {
        self::assertStringContainsString('<span>Note</span>', Html::checkTable([['level' => 'strange', 'subject' => '', 'message' => 'm', 'hint' => '']]));
    }

    public function testTheChecksOfTheRestoreWizardUseTheSameTable(): void
    {
        $html = Html::checks([Check::warning('open_basedir is set', 'Ask the host.'), Check::error('No extension.')]);

        self::assertStringContainsString('<table class="checks">', $html);
        self::assertStringContainsString('<span>Warning</span>', $html);
        self::assertStringContainsString('<span class="hint">Ask the host.</span>', $html);
        self::assertStringContainsString('<span>Problem</span>', $html);
        self::assertStringContainsString('Nothing to report', Html::checks([]));
    }
}
