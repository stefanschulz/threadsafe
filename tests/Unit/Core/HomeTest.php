<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ThreadSafe\Core\Home;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * The home of an installation from a release: the pointer to the active release, and how a program directory finds its home.
 */
final class HomeTest extends TestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    /** A home with the given releases unpacked (just their autoloader). */
    private function home(string ...$releases): string
    {
        $home = $this->tempDirectory();
        foreach ($releases as $release) {
            mkdir($home . '/releases/' . $release . '/vendor', 0777, true);
            file_put_contents($home . '/releases/' . $release . '/vendor/autoload.php', '<?php');
        }

        return $home;
    }

    public function testThePointerEndsTheScriptBeforeItsData(): void
    {
        $source = Home::pointerSource('0.2.0', '0.1.0');

        self::assertStringStartsWith("<?php http_response_code(404); exit; ?>\n", $source);
        self::assertSame(['release' => '0.2.0', 'previous' => '0.1.0'], Home::parsePointer($source));
        self::assertSame(['release' => '0.1.0-rc.1', 'previous' => null], Home::parsePointer(Home::pointerSource('0.1.0-rc.1')));
    }

    public function testFetchedOverHttpTheFileExecutesAndPrintsNothing(): void
    {
        $file = $this->tempDirectory() . '/current.php';
        file_put_contents($file, Home::pointerSource('0.2.0'));

        $output = (string) shell_exec('php ' . escapeshellarg($file) . ' 2>&1');

        self::assertSame('', $output);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notPointers(): iterable
    {
        yield 'empty' => [''];
        yield 'without the first line' => ['{"release": "0.2.0"}'];
        yield 'not json' => [Home::PREFIX . 'nope'];
        yield 'a list' => [Home::PREFIX . '["0.2.0"]'];
        yield 'no release' => [Home::PREFIX . '{"previous": "0.1.0"}'];
        yield 'a release that is a path' => [Home::PREFIX . '{"release": "../../etc"}'];
        yield 'a release with a slash' => [Home::PREFIX . '{"release": "0.1.0/../x"}'];
        yield 'a number' => [Home::PREFIX . '{"release": 1}'];
        yield 'a previous that is a path' => [Home::PREFIX . '{"release": "0.2.0", "previous": "../x"}'];
    }

    #[DataProvider('notPointers')]
    public function testWhatIsNoPointerIsNotRead(string $content): void
    {
        self::assertNull(Home::parsePointer($content));
    }

    public function testAPointerIsWrittenAndReadBack(): void
    {
        $home = $this->home('0.1.0', '0.2.0');

        Home::writePointer($home, '0.1.0');
        self::assertSame(['release' => '0.1.0', 'previous' => null], Home::readPointer($home));

        Home::writePointer($home, '0.2.0', '0.1.0');
        self::assertSame(['release' => '0.2.0', 'previous' => '0.1.0'], Home::readPointer($home));
        self::assertSame([], glob($home . '/current.php.*') ?: [], 'no temporary file is left');
    }

    public function testAReadOnlyPointerIsReplacedNotWritten(): void
    {
        $home = $this->home('0.1.0', '0.2.0');
        Home::writePointer($home, '0.1.0');
        chmod($home . '/current.php', 0444);

        Home::writePointer($home, '0.2.0', '0.1.0');

        self::assertSame('0.2.0', Home::readPointer($home)['release'] ?? null);
    }

    public function testAReleaseThatIsNotThereIsNotMadeActive(): void
    {
        $home = $this->home('0.1.0');
        Home::writePointer($home, '0.1.0');

        try {
            Home::writePointer($home, '0.2.0', '0.1.0');
            self::fail('expected a refusal');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('not unpacked', $e->getMessage());
        }
        self::assertSame('0.1.0', Home::readPointer($home)['release'] ?? null, 'the old pointer stays');
    }

    public function testAMissingOrDamagedPointerReadsAsNull(): void
    {
        $home = $this->home('0.1.0');
        self::assertNull(Home::readPointer($home));

        file_put_contents($home . '/current.php', "<?php exit;\n{broken");
        self::assertNull(Home::readPointer($home));
    }

    public function testANameThatIsNotAVersionNeverBecomesAPath(): void
    {
        $this->expectException(RuntimeException::class);

        Home::releaseDirectory('/home', '../../etc');
    }

    public function testTheReleaseDirectoryIsBelowTheHome(): void
    {
        self::assertSame('/srv/ts/releases/0.1.0', Home::releaseDirectory('/srv/ts/', '0.1.0'));
    }

    public function testAProgramDirectoryOfAReleaseFindsItsHome(): void
    {
        $home = $this->home('0.1.0');
        Home::writePointer($home, '0.1.0');

        self::assertSame($home, Home::detect($home . '/releases/0.1.0'));
        self::assertSame($home, Home::detect($home . '/releases/0.1.0/'));
    }

    public function testOtherDirectoriesHaveNoHome(): void
    {
        $home = $this->home('0.1.0');

        self::assertNull(Home::detect($home . '/releases/0.1.0'), 'no pointer: a package unpacked by hand');
        Home::writePointer($home, '0.1.0');
        self::assertNull(Home::detect($home . '/releases/latest'), 'not a version');
        self::assertNull(Home::detect($home), 'the home itself');
        self::assertNull(Home::detect(dirname(__DIR__, 3)), 'a clone');
    }
}
