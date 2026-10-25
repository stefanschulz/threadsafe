<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Install;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Install\Places;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * Where the home can go, and what the document root must look like.
 */
final class PlacesTest extends TestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    /** A host: a home directory of the person with a document root in it. */
    private function host(): string
    {
        $docroot = $this->tempDirectory() . '/public_html';
        mkdir($docroot);

        return $docroot;
    }

    public function testTheTwoPlacesAreProposedAndBothAreUsableOnAWritableHost(): void
    {
        $docroot = $this->host();

        $places = Places::candidates($docroot);

        self::assertCount(2, $places);
        self::assertSame(dirname($docroot) . '/threadsafe', $places[0]['path']);
        self::assertFalse($places[0]['inside']);
        self::assertTrue($places[0]['usable']);
        self::assertSame($docroot . '/threadsafe-home', $places[1]['path']);
        self::assertTrue($places[1]['inside']);
        self::assertTrue($places[1]['usable']);
    }

    public function testAPlaceThatExistsAndIsNotEmptyIsNotUsableAndSaysWhy(): void
    {
        $docroot = $this->host();
        mkdir(dirname($docroot) . '/threadsafe');
        file_put_contents(dirname($docroot) . '/threadsafe/old.txt', 'x');

        $places = Places::candidates($docroot);

        self::assertFalse($places[0]['usable']);
        self::assertStringContainsString('exists and is not empty', $places[0]['reason']);
        self::assertTrue($places[1]['usable']);
    }

    public function testAnEmptyDirectoryThatExistsIsFine(): void
    {
        $docroot = $this->host();
        mkdir(dirname($docroot) . '/threadsafe');

        self::assertNull(Places::problem(dirname($docroot) . '/threadsafe', $docroot));
    }

    public function testAFileInTheWayIsAProblem(): void
    {
        $docroot = $this->host();
        file_put_contents(dirname($docroot) . '/threadsafe', 'x');

        self::assertStringContainsString('is not a directory', (string) Places::problem(dirname($docroot) . '/threadsafe', $docroot));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badHomes(): iterable
    {
        yield 'empty' => [''];
        yield 'relative' => ['threadsafe'];
        yield 'with dots' => ['/srv/../etc/threadsafe'];
        yield 'nul' => ["/srv/th\0readsafe"];
    }

    #[DataProvider('badHomes')]
    public function testAHomeThatIsNotAFullPathIsRefused(string $home): void
    {
        self::assertStringContainsString('full path', (string) Places::problem($home, '/srv/www'));
    }

    public function testTheDocumentRootAndItsParentsAreNoHome(): void
    {
        $docroot = $this->host();

        self::assertStringContainsString('cannot be the document root', (string) Places::problem($docroot, $docroot));
        self::assertStringContainsString('cannot be the document root', (string) Places::problem(dirname($docroot), $docroot));
        self::assertStringContainsString('installer\'s own directory', (string) Places::problem($docroot . '/.threadsafe-install/home', $docroot));
    }

    public function testAHomeThatCannotBeMadeBecauseNothingAboveIsWritableIsAProblem(): void
    {
        $file = $this->tempDirectory() . '/a-file';
        file_put_contents($file, 'x');

        self::assertNotNull(Places::problem($file . '/sub/home', $this->tempDirectory()));
    }

    public function testTheDocumentRootMustBeWritableAndFreeOfIndexPhpAndHtaccess(): void
    {
        $docroot = $this->host();
        self::assertNull(Places::documentRootProblem($docroot));

        file_put_contents($docroot . '/index.html', 'a placeholder');
        self::assertNull(Places::documentRootProblem($docroot), 'a placeholder page is only a note');

        file_put_contents($docroot . '/index.php', '<?php // theirs');
        self::assertStringContainsString('"index.php" exists', (string) Places::documentRootProblem($docroot));
        unlink($docroot . '/index.php');

        file_put_contents($docroot . '/.htaccess', 'theirs');
        self::assertStringContainsString('".htaccess" exists', (string) Places::documentRootProblem($docroot));

        self::assertStringContainsString('not writable', (string) Places::documentRootProblem($docroot . '/missing'));
    }

    public function testWhetherAPathLiesBelowAnother(): void
    {
        self::assertTrue(Places::isBelow('/srv/www/home', '/srv/www'));
        self::assertTrue(Places::isBelow('/srv/www', '/srv/www'));
        self::assertFalse(Places::isBelow('/srv/www2/home', '/srv/www'), 'a longer name is not below');
        self::assertFalse(Places::isBelow('/srv', '/srv/www'));
        self::assertTrue(Places::isInside('/srv/www/threadsafe-home/', '/srv/www/'));
    }
}
