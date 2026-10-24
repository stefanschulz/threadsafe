<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Joomla;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Joomla\Steps\BlockSearchEngines;
use ThreadSafe\Migration\Mode;
use ThreadSafe\Tests\Support\JoomlaSite;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * Dev mode, the file part: robots.txt forbids everything, a block at the top of .htaccess sends the noindex header.
 */
final class BlockSearchEnginesTest extends TestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    private function root(bool $htaccess = true): string
    {
        $root = (string) realpath($this->tempDirectory());
        JoomlaSite::build($root, $htaccess);
        file_put_contents($root . '/robots.txt', "User-agent: *\nDisallow: /administrator/\n");

        return $root;
    }

    public function testAProductionRunDoesNothing(): void
    {
        $root = $this->root();
        $before = [(string) file_get_contents($root . '/robots.txt'), (string) file_get_contents($root . '/.htaccess')];
        $context = JoomlaSite::context($root);
        $step = new BlockSearchEngines();

        $plan = $step->plan($context);
        $result = $step->apply($context, $plan);

        self::assertFalse($plan->hasChanges());
        self::assertSame(0, $result->applied);
        self::assertSame($before, [(string) file_get_contents($root . '/robots.txt'), (string) file_get_contents($root . '/.htaccess')]);
    }

    public function testADevRunForbidsEverythingAndPutsTheHeaderAtTheTopOfTheHtaccess(): void
    {
        $root = $this->root();
        $original = (string) file_get_contents($root . '/.htaccess');
        $context = JoomlaSite::context($root, mode: Mode::Dev);
        $step = new BlockSearchEngines();

        $plan = $step->plan($context);
        $result = $step->apply($context, $plan);

        self::assertSame(2, $plan->changeCount());
        self::assertSame(2, $result->applied);
        self::assertSame("User-agent: *\nDisallow: /\n", file_get_contents($root . '/robots.txt'));
        $htaccess = (string) file_get_contents($root . '/.htaccess');
        self::assertStringStartsWith('# BEGIN ThreadSafe dev mode', $htaccess);
        self::assertStringContainsString('Header set X-Robots-Tag "noindex, nofollow"', $htaccess);
        self::assertNotSame('', $original);
        self::assertSame($original, substr($htaccess, -strlen($original)), 'what Joomla ships follows the block unchanged');
        self::assertSame(1, substr_count($htaccess, 'BEGIN ThreadSafe dev mode'));
    }

    public function testASecondRunChangesNothing(): void
    {
        $root = $this->root();
        $context = JoomlaSite::context($root, mode: Mode::Dev);
        $step = new BlockSearchEngines();
        $step->apply($context, $step->plan($context));
        $once = (string) file_get_contents($root . '/.htaccess');

        self::assertFalse($step->plan($context)->hasChanges(), 'idempotent');
        $step->apply($context, $step->plan($context));
        self::assertSame($once, (string) file_get_contents($root . '/.htaccess'));
    }

    public function testWithoutAnHtaccessOneIsMadeWithTheBlockAlone(): void
    {
        $root = $this->root(htaccess: false);
        $context = JoomlaSite::context($root, mode: Mode::Dev);
        $step = new BlockSearchEngines();

        $plan = $step->plan($context);
        $step->apply($context, $plan);

        self::assertSame(2, $plan->changeCount());
        $htaccess = (string) file_get_contents($root . '/.htaccess');
        self::assertStringStartsWith('# BEGIN ThreadSafe dev mode', $htaccess);
        self::assertStringNotContainsString('RewriteEngine', $htaccess, 'nothing of Joomla\'s rules is invented');
        self::assertStringEndsWith("# END ThreadSafe dev mode\n", $htaccess);
    }

    public function testWithoutARobotsFileOneIsMade(): void
    {
        $root = $this->root();
        unlink($root . '/robots.txt');
        $context = JoomlaSite::context($root, mode: Mode::Dev);

        (new BlockSearchEngines())->apply($context, (new BlockSearchEngines())->plan($context));

        self::assertSame("User-agent: *\nDisallow: /\n", file_get_contents($root . '/robots.txt'));
    }

    public function testTheBlockIsReplacedNotRepeatedWhenAnOldOneIsThere(): void
    {
        $old = "# BEGIN ThreadSafe dev mode - old\n<IfModule mod_headers.c>\n    Header set X-Robots-Tag \"none\"\n</IfModule>\n# END ThreadSafe dev mode\n\nRewriteEngine On\n";

        $new = BlockSearchEngines::withBlock($old);

        self::assertSame(1, substr_count($new, 'BEGIN ThreadSafe dev mode'));
        self::assertStringNotContainsString('"none"', $new);
        self::assertStringEndsWith("RewriteEngine On\n", $new);
    }
}
