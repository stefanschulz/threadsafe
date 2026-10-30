<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ThreadSafe\Core\Home;
use ThreadSafe\Core\Layout;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * The stub of the document root and the command of the home: that they parse on old PHP, and that they find the active release and hand
 * over to it (run for real in a PHP process, against a release whose autoloader only says what it was called with).
 */
final class LayoutTest extends TestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    /** A home whose release 1.0.0 has an autoloader that declares the entry of the interface and a command that report where they run. */
    private function home(): string
    {
        $home = $this->tempDirectory();
        mkdir($home . '/releases/1.0.0/vendor', 0777, true);
        mkdir($home . '/releases/1.0.0/bin', 0777, true);
        mkdir($home . '/data', 0777, true);
        file_put_contents($home . '/releases/1.0.0/vendor/autoload.php', <<<'PHP'
<?php
namespace ThreadSafe\Admin;
final class Entry
{
    public static function run(): void
    {
        echo 'entry program=' . THREADSAFE_PROGRAM_DIR . ' data=' . getenv('THREADSAFE_DATA');
    }
}
PHP);
        file_put_contents($home . '/releases/1.0.0/bin/threadsafe', "<?php\necho 'command script=' . (defined('THREADSAFE_SCRIPT') ? THREADSAFE_SCRIPT : '-') . ' data=' . getenv('THREADSAFE_DATA') . ' args=' . implode(',', array_slice(\$_SERVER['argv'], 1));\n");
        Home::writePointer($home, '1.0.0');

        return $home;
    }

    /** @return array{string, int} output and exit code */
    private function execute(string $file, string $arguments = ''): array
    {
        $output = [];
        exec('php ' . escapeshellarg($file) . ' ' . $arguments . ' 2>&1', $output, $code);

        return [implode("\n", $output), $code];
    }

    public function testTheStubHandsOverToTheActiveRelease(): void
    {
        $home = $this->home();
        file_put_contents($home . '/index.php', Layout::stub($home));

        [$output, $code] = $this->execute($home . '/index.php');

        self::assertSame(0, $code);
        self::assertSame('entry program=' . $home . '/releases/1.0.0 data=' . $home . '/data', $output);
    }

    public function testANewPointerIsAllThatAnUpdateChanges(): void
    {
        $home = $this->home();
        file_put_contents($home . '/index.php', Layout::stub($home));
        mkdir($home . '/releases/1.1.0/vendor', 0777, true);
        copy($home . '/releases/1.0.0/vendor/autoload.php', $home . '/releases/1.1.0/vendor/autoload.php');
        $stub = (string) file_get_contents($home . '/index.php');

        Home::writePointer($home, '1.1.0', '1.0.0');

        [$output] = $this->execute($home . '/index.php');
        self::assertStringContainsString('program=' . $home . '/releases/1.1.0', $output);
        self::assertSame($stub, file_get_contents($home . '/index.php'), 'the stub is not touched');
    }

    public function testWithoutAPointerTheStubSaysSoAndLoadsNothing(): void
    {
        $home = $this->home();
        unlink($home . '/current.php');
        file_put_contents($home . '/index.php', Layout::stub($home));

        [$output] = $this->execute($home . '/index.php');

        self::assertStringContainsString('not installed correctly', $output);
        self::assertStringNotContainsString('entry program', $output);
        self::assertStringNotContainsString($home, $output, 'no path is shown to a visitor');
    }

    public function testAPointerToARemovedReleaseIsRefused(): void
    {
        $home = $this->home();
        file_put_contents($home . '/index.php', Layout::stub($home));
        $this->removeTree($home . '/releases/1.0.0');

        [$output] = $this->execute($home . '/index.php');

        self::assertStringContainsString('not installed correctly', $output);
    }

    public function testAPointerWithAPathInsteadOfAVersionIsRefused(): void
    {
        $home = $this->home();
        file_put_contents($home . '/index.php', Layout::stub($home));
        file_put_contents($home . '/current.php', Home::PREFIX . '{"release": "../../../tmp"}');

        [$output] = $this->execute($home . '/index.php');

        self::assertStringContainsString('not installed correctly', $output);
    }

    public function testTheCommandRunsTheCommandOfTheActiveRelease(): void
    {
        $home = $this->home();
        mkdir($home . '/bin');
        file_put_contents($home . '/bin/threadsafe', Layout::command($home));

        [$output, $code] = $this->execute($home . '/bin/threadsafe', 'cron -q');

        self::assertSame(0, $code);
        self::assertSame('command script=' . $home . '/bin/threadsafe data=' . $home . '/data args=cron,-q', $output, 'the script is the command of the home, so that the arguments are found in a CGI binary');
    }

    public function testTheCommandWithoutAPointerFails(): void
    {
        $home = $this->home();
        unlink($home . '/current.php');
        mkdir($home . '/bin');
        file_put_contents($home . '/bin/threadsafe', Layout::command($home));

        [$output, $code] = $this->execute($home . '/bin/threadsafe');

        self::assertSame(1, $code);
        self::assertStringContainsString('not installed correctly', $output);
    }

    public function testAHomeWithOddCharactersIsQuoted(): void
    {
        $stub = Layout::stub("/srv/it's/home/");

        self::assertStringContainsString("\$home = '/srv/it\\'s/home';", $stub);
    }

    public function testAHomeThatCannotBeWrittenIntoAFileIsRefused(): void
    {
        $this->expectException(RuntimeException::class);

        Layout::stub("/srv/home\n; system('x'); //");
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function sources(): iterable
    {
        yield 'the stub' => [Layout::stub('/srv/home')];
        yield 'the command' => [Layout::command('/srv/home')];
    }

    #[DataProvider('sources')]
    public function testTheyUseOnlyWhatOldPhpCanRead(string $source): void
    {
        $forbidden = [
            T_NAMESPACE => 'namespace', T_NS_SEPARATOR => 'a name with a backslash', T_NAME_QUALIFIED => 'a qualified name', T_NAME_FULLY_QUALIFIED => 'a qualified name',
            T_FN => 'fn', T_DIR => '__DIR__', T_COALESCE => '??', T_POW => '**', T_USE => 'use', T_CLASS => 'class', T_TRAIT => 'trait',
            T_YIELD => 'yield', T_FINALLY => 'finally', T_GOTO => 'goto', T_SPACESHIP => '<=>', T_ELLIPSIS => '...', T_PUBLIC => 'visibility', T_STATIC => 'static',
            T_FUNCTION => 'a function or closure', T_NULLSAFE_OBJECT_OPERATOR => '?->', T_MATCH => 'match', T_ENUM => 'enum',
        ];
        $tokens = token_get_all(str_starts_with($source, '#!') ? substr($source, (int) strpos($source, "\n") + 1) : $source);
        $previous = null;
        foreach ($tokens as $index => $token) {
            $next = $tokens[$index + 1] ?? null;
            if (is_array($token) && isset($forbidden[$token[0]])) {
                self::fail('Uses ' . $forbidden[$token[0]] . ' (line ' . $token[2] . '), which old PHP cannot parse.');
            }
            if ($token === '[' && ($previous === '=' || $previous === '(' || $previous === ',' || (is_array($previous) && in_array($previous[0], [T_RETURN, T_DOUBLE_ARROW], true)))) {
                self::fail('Uses a short array.');
            }
            if ($token === '?' && ($next === ':' || (is_array($next) && $next[0] === T_WHITESPACE && ($tokens[$index + 2] ?? null) === ':'))) {
                self::fail('Uses ?: (PHP 5.3).');
            }
            if (!is_array($token) || $token[0] !== T_WHITESPACE) {
                $previous = $token;
            }
        }
        self::assertStringNotContainsString('?->', $source);
    }

    public function testTheStubCallsTheEntryByName(): void
    {
        self::assertStringContainsString("call_user_func('ThreadSafe\\\\Admin\\\\Entry::run')", Layout::stub('/srv/home'));
    }

    public function testTheStubChecksThePhpVersionFirst(): void
    {
        $stub = Layout::stub('/srv/home');

        self::assertLessThan(strpos($stub, 'file_get_contents'), strpos($stub, "version_compare(PHP_VERSION, '8.4.0', '<')"));
        self::assertLessThan(strpos($stub, 'require '), strpos($stub, 'version_compare'));
    }

    public function testTheHtaccessOfTheDocumentRootSendsEverythingButFilesToTheStub(): void
    {
        $htaccess = Layout::documentRootHtaccess();

        self::assertStringContainsString('RewriteRule ^ index.php [L]', $htaccess);
        self::assertStringContainsString('RewriteCond %{REQUEST_FILENAME} !-f', $htaccess);
    }

    public function testTheHtaccessOfTheDocumentRootSaysWhereItLivesInTheAddressSoThatTheRewriteCannotLoop(): void
    {
        self::assertStringContainsString("RewriteEngine On\n    RewriteBase /\n    RewriteCond", Layout::documentRootHtaccess(''), 'the root of a site');
        self::assertStringContainsString("    RewriteBase /blog/\n", Layout::documentRootHtaccess('/blog'));
        self::assertStringContainsString("    RewriteBase /a/b.c-d/\n", Layout::documentRootHtaccess('/a/b.c-d'));
        self::assertStringNotContainsString('RewriteBase', Layout::documentRootHtaccess(), 'not known: as before');
        self::assertStringNotContainsString('RewriteBase', Layout::documentRootHtaccess('/a b'), 'a path that is not plain is not written into a rule');
        self::assertStringNotContainsString('RewriteBase', Layout::documentRootHtaccess("/a\nRewriteRule"), 'and no way to add a line');
        self::assertStringContainsString('RewriteRule ^ index.php [L]', Layout::documentRootHtaccess('/blog'));
    }

    public function testTheHtaccessThatClosesADirectoryDeniesInBothGenerationsOfApache(): void
    {
        $htaccess = Layout::closedHtaccess();

        self::assertStringContainsString('Require all denied', $htaccess);
        self::assertStringContainsString('Deny from all', $htaccess);
    }

    private function removeTree(string $path): void
    {
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->removeTree($path . '/' . $entry);
                }
            }
            rmdir($path);
        } else {
            unlink($path);
        }
    }
}
