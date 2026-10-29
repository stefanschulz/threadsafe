<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\WordPress;

use PHPUnit\Framework\TestCase;
use ThreadSafe\WordPress\Backup\OptionStrings;

/**
 * The strings of a serialized list of plugin files, read without `unserialize()`.
 */
final class OptionStringsTest extends TestCase
{
    public function testTheStringsOfAListAreRead(): void
    {
        self::assertSame(
            ['akismet/akismet.php', 'hello.php'],
            OptionStrings::list('a:2:{i:0;s:19:"akismet/akismet.php";i:1;s:9:"hello.php";}'),
        );
        self::assertSame([], OptionStrings::list('a:0:{}'));
    }

    public function testAStringWithAQuoteInItIsReadByItsLength(): void
    {
        self::assertSame(['a";b', 'c'], OptionStrings::list('a:2:{i:0;s:4:"a";b";i:1;s:1:"c";}'));
    }

    public function testWhatIsNotAListOfStringsGivesWhatCouldBeRead(): void
    {
        self::assertSame([], OptionStrings::list(''));
        self::assertSame([], OptionStrings::list('not serialized'));
        self::assertSame([], OptionStrings::list('s:5:"hello";'));
        self::assertSame(['a'], OptionStrings::list('a:2:{i:0;s:1:"a";i:1;a:0:{}}'), 'a nested array ends the reading');
        self::assertSame(['a'], OptionStrings::list('a:2:{i:0;s:1:"a";i:1;s:9:"too long";}'), 'a length that does not fit ends it');
        self::assertSame([], OptionStrings::list('a:1:{i:0;O:8:"stdClass":0:{}}'), 'an object is never made');
    }
}
