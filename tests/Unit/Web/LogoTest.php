<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Web;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Web\Html;
use ThreadSafe\Web\Logo;

/**
 * The logo as markup: valid, two colours, no `style` attribute (the policy of the interface and of the wizards refuses it), the same shapes as the
 * design file, and the favicon as a file and as an address.
 */
final class LogoTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';

    /**
     * The numbers that make the shapes: coordinates, sizes and paths.
     *
     * @return list<string>
     */
    private static function geometry(string $svg): array
    {
        preg_match_all('/(?<![\w-])(?:x|y|width|height|rx|r|cx|cy|d|transform)="([^"]*)"/', (string) preg_replace('/<svg[^>]*>/', '', $svg, 1), $m);
        $values = $m[1];
        sort($values);

        return $values;
    }

    public function testTheLogoIsValidMarkupInTwoColours(): void
    {
        $svg = Logo::svg();

        self::assertNotFalse(simplexml_load_string($svg), 'well-formed XML');
        preg_match_all('/#[0-9a-f]{6}/i', $svg, $colours);
        self::assertEqualsCanonicalizing(['#1b232c', '#e0a13d'], array_unique($colours[0]), 'graphite and brass, the tokens of docs/DESIGN.md');
        self::assertStringContainsString('aria-hidden="true"', $svg, 'it is decoration; the name stands next to it');
        self::assertStringContainsString('class="logo"', $svg);
    }

    public function testNeitherLogoNorFaviconHasAStyleAttributeOrElement(): void
    {
        foreach ([Logo::svg(), Logo::favicon()] as $svg) {
            self::assertStringNotContainsString('style=', $svg, 'a policy without unsafe-inline refuses it');
            self::assertStringNotContainsString('<style', $svg);
            self::assertStringNotContainsString('<script', $svg);
        }
    }

    public function testTheClassIsEscaped(): void
    {
        self::assertStringContainsString('class="a&quot; onload=&quot;x"', Logo::svg('a" onload="x'));
    }

    public function testTheMarkupHasTheShapesOfTheDesignFile(): void
    {
        $design = (string) file_get_contents(self::ROOT . '/.design/logo.svg');

        self::assertSame(self::geometry($design), self::geometry(Logo::svg()), 'the logo in the code and .design/logo.svg have drifted apart');
        self::assertSame(self::geometry((string) file_get_contents(self::ROOT . '/.design/favicon.svg')), self::geometry(Logo::favicon()));
    }

    public function testTheFaviconFileOfTheInterfaceIsTheFaviconOfTheCode(): void
    {
        self::assertSame(Logo::favicon(), trim((string) file_get_contents(self::ROOT . '/public/favicon.svg')), 'public/favicon.svg is Logo::favicon(), as a file');
        self::assertNotFalse(simplexml_load_string(Logo::favicon()));
    }

    public function testTheFaviconAddressHoldsTheFavicon(): void
    {
        $uri = Logo::faviconUri();

        self::assertStringStartsWith('data:image/svg+xml;base64,', $uri);
        self::assertSame(Logo::favicon(), base64_decode(substr($uri, strlen('data:image/svg+xml;base64,')), true));
    }

    public function testAWizardPageCarriesTheLogoAndTheFaviconAndNoStyleAttribute(): void
    {
        $page = Html::page('Access key', '<p>x</p>', 'nonce1', null, '', 'installer');

        self::assertStringContainsString('<h1><svg class="logo"', $page);
        self::assertStringContainsString('<span>ThreadSafe installer</span></h1>', $page);
        self::assertStringContainsString('<link rel="icon" type="image/svg+xml" href="data:image/svg+xml;base64,', $page);
        self::assertStringNotContainsString(' style="', $page, 'the wizard allows styles only by nonce');
    }
}
