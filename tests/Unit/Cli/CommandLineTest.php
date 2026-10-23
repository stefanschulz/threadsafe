<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Cli\CommandLine;

/**
 * The arguments of the command line where PHP hands over no `$argv` (the CGI binary with `register_argc_argv` off).
 */
final class CommandLineTest extends TestCase
{
    private string $script;

    protected function setUp(): void
    {
        $this->script = dirname(__DIR__, 3) . '/bin/threadsafe';
    }

    public function testArgvAsPhpHasItIsUsedAsItIs(): void
    {
        self::assertSame(['bin/threadsafe', 'backup', 'shop'], CommandLine::arguments(['bin/threadsafe', 'backup', 'shop'], $this->script));
    }

    public function testTheScriptAndItsArgumentsAreTakenFromTheProcessAfterTheBinaryAndItsOptions(): void
    {
        $raw = implode("\0", ['php-cgi', '-q', '-d', 'register_argc_argv=0', $this->script, 'backup', 'shop', '--yes']) . "\0";

        self::assertSame([$this->script, 'backup', 'shop', '--yes'], CommandLine::fromProcess($raw, $this->script));
    }

    public function testAnEmptyArgumentIsKept(): void
    {
        $raw = implode("\0", ['php-cgi', $this->script, 'profile', '', 'x']) . "\0";

        self::assertSame([$this->script, 'profile', '', 'x'], CommandLine::fromProcess($raw, $this->script));
    }

    public function testARelativePathIsLookedUpFromTheDirectoryTheShellWasIn(): void
    {
        $raw = implode("\0", ['php-cgi', '-q', 'bin/threadsafe', '--version']) . "\0";

        self::assertSame(['bin/threadsafe', '--version'], CommandLine::fromProcess($raw, $this->script, dirname($this->script, 2)));
    }

    public function testAWordThatEndsInTheNameOfTheScriptIsTheLastResort(): void
    {
        $raw = implode("\0", ['php-cgi', '-q', '../somewhere/threadsafe', 'list']) . "\0";

        self::assertSame(['../somewhere/threadsafe', 'list'], CommandLine::fromProcess($raw, $this->script, null));
    }

    public function testWithoutTheScriptInTheCommandLineThereAreNoArguments(): void
    {
        self::assertNull(CommandLine::fromProcess("php-cgi\0-q\0other.php\0", $this->script, null));
        self::assertNull(CommandLine::arguments(null, $this->script, '/nonexistent/cmdline'), 'a system without /proc');
    }

    public function testTheCommandLineIsReadFromTheFileOfTheProcess(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'cmdline');
        self::assertNotFalse($file);
        file_put_contents($file, implode("\0", ['php-cgi', $this->script, 'list']) . "\0");

        try {
            self::assertSame([$this->script, 'list'], CommandLine::arguments(null, $this->script, $file));
        } finally {
            unlink($file);
        }
    }
}
