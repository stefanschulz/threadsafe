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

    /**
     * A host like a managed shared host: PHP may use the document root, a "private" directory next to it, and a few others - not their parent.
     *
     * @return array{string, string, string} the document root, the directory above it, and the open_basedir setting
     */
    private function restrictedHost(): array
    {
        $docroot = $this->host();
        $base = dirname($docroot);
        mkdir($base . '/private');
        mkdir($base . '/tmp');
        mkdir($base . '/.composer');

        return [$docroot, $base, $docroot . ':' . $base . '/private:' . $base . '/tmp:' . $base . '/.composer:/usr/bin'];
    }

    public function testWhereOpenBasedirForbidsTheDirectoryNextToTheDocumentRootAnAllowedOneIsProposed(): void
    {
        [$docroot, $base, $setting] = $this->restrictedHost();

        $places = Places::candidates($docroot, $setting);

        self::assertSame(['outside', 'allowed-1', 'inside'], array_column($places, 'id'));
        self::assertFalse($places[0]['usable']);
        self::assertStringContainsString('open_basedir', $places[0]['reason']);
        self::assertSame('allowed', $places[1]['kind']);
        self::assertSame($base . '/private/threadsafe', $places[1]['path']);
        self::assertTrue($places[1]['usable'], $places[1]['reason']);
        self::assertFalse($places[1]['inside']);
        self::assertTrue($places[2]['usable']);
    }

    public function testTemporaryHiddenAndSystemDirectoriesAreNoHomes(): void
    {
        [$docroot, $base, $setting] = $this->restrictedHost();

        $homes = Places::allowedHomes($docroot, $setting);

        self::assertSame([$base . '/private'], $homes, 'not tmp, not .composer, not /usr/bin, not the document root itself');
    }

    public function testWithoutARestrictionThereIsNoExtraPlace(): void
    {
        self::assertSame(['outside', 'inside'], array_column(Places::candidates($this->host(), ''), 'id'));
    }

    public function testAPathOutsideWhatPhpMayUseIsRefusedWithTheAllowedDirectoriesNamed(): void
    {
        [$docroot, $base, $setting] = $this->restrictedHost();

        $problem = (string) Places::problem($base . '/elsewhere', $docroot, $setting);

        self::assertStringContainsString('outside the directories that this host lets PHP use (open_basedir: ', $problem);
        self::assertStringContainsString($base . '/private', $problem);
    }

    public function testNothingOutsideOpenBasedirIsTouchedAndNoWarningIsPrinted(): void
    {
        [$docroot, $base] = $this->restrictedHost();
        $root = dirname(__DIR__, 3);
        $code = 'require $argv[1] . "/vendor/autoload.php"; echo json_encode([array_column(ThreadSafe\Install\Places::candidates($argv[2]), "usable", "id"), array_map(static fn ($c) => $c->message, ThreadSafe\Core\Environment::dataDirectory("/etc/threadsafe-data")), ThreadSafe\Install\Places::problem("/home/nobody/threadsafe", $argv[2])]);';
        $process = proc_open(
            [PHP_BINARY, '-d', 'open_basedir=' . $root . ':' . $docroot . ':' . $base . '/private', '-d', 'display_errors=1', '-d', 'error_reporting=-1', '-r', $code, $root, $docroot],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        proc_close($process);

        self::assertStringNotContainsString('Warning', $output . $errors, 'a warning would end the headers of the page');
        $result = json_decode($output, true);
        self::assertIsArray($result, $output . $errors);
        self::assertFalse($result[0]['outside'], 'the directory above the document root is not allowed');
        self::assertTrue($result[0]['allowed-1'], 'the private directory is');
        self::assertTrue($result[0]['inside']);
        self::assertStringContainsString('open_basedir', $result[1][0]);
        self::assertStringContainsString('open_basedir', $result[2]);
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
