<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Web;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Web\ReachProbe;

/**
 * The files that ask the web server whether a directory of a single-file tool can be fetched.
 */
final class ReachProbeTest extends TestCase
{
    use TempDirectory;

    private string $tool;

    protected function setUp(): void
    {
        $this->tool = $this->tempDirectory() . '/site/tools';
        mkdir($this->tool . '/.state', 0777, true);
        mkdir($this->tool . '/.other', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    public function testEveryDirectoryGetsTheSameFileWithTheSameToken(): void
    {
        $directories = [$this->tool . '/.state', $this->tool . '/.other'];
        $record = (new ReachProbe($this->tool))->prepare([], $directories);

        self::assertMatchesRegularExpression('/^probe-[0-9a-f]{16}\.txt$/', $record['file']);
        self::assertSame('', $record['result']);
        foreach ($directories as $directory) {
            self::assertSame('ThreadSafe probe ' . $record['token'], file_get_contents($directory . '/' . $record['file']));
        }
    }

    public function testTheRecordOfTheSessionIsKeptWhileAllFilesAreThere(): void
    {
        $probe = new ReachProbe($this->tool);
        $directories = [$this->tool . '/.state', $this->tool . '/.other'];
        $first = $probe->prepare([], $directories);

        $again = $probe->prepare([...$first, 'result' => 'closed'], $directories);

        self::assertSame($first['file'], $again['file']);
        self::assertSame('closed', $again['result']);
        self::assertCount(1, glob($directories[0] . '/probe-*.txt') ?: []);
    }

    public function testAMissingFileMakesANewProbeAndTheOldFilesGo(): void
    {
        $probe = new ReachProbe($this->tool);
        $directories = [$this->tool . '/.state', $this->tool . '/.other'];
        $first = $probe->prepare([], $directories);
        unlink($directories[1] . '/' . $first['file']);

        $second = $probe->prepare([...$first, 'result' => 'reachable'], $directories);

        self::assertNotSame($first['file'], $second['file']);
        self::assertSame('', $second['result'], 'a result belongs to the files it was found with');
        self::assertFileDoesNotExist($directories[0] . '/' . $first['file'], 'no straggler is left behind');
        self::assertFileExists($directories[0] . '/' . $second['file']);
        self::assertFileExists($directories[1] . '/' . $second['file']);
    }

    public function testOnlyAFileItMadeIsRemoved(): void
    {
        $probe = new ReachProbe($this->tool);
        $directories = [$this->tool . '/.state'];
        $record = $probe->prepare([], $directories);
        file_put_contents($directories[0] . '/index.html', 'mine');

        $probe->remove(['file' => 'index.html'], $directories);
        $probe->remove(['file' => '../index.php'], $directories);
        self::assertFileExists($directories[0] . '/index.html');

        $probe->remove($record, $directories);
        self::assertFileDoesNotExist($directories[0] . '/' . $record['file']);
    }

    public function testTheAddressesAreRelativeToTheDirectoryOfTheTool(): void
    {
        $archives = dirname($this->tool) . '/archives';
        mkdir($archives);
        $elsewhere = dirname($this->tool, 2) . '/elsewhere';
        mkdir($elsewhere);
        file_put_contents($this->tool . '/.state/probe-1.txt', 'x');
        file_put_contents($archives . '/probe-2.txt', 'x');
        file_put_contents($elsewhere . '/probe 3.txt', 'x');

        self::assertSame('.state/probe-1.txt', ReachProbe::relativeUrl($this->tool, $this->tool . '/.state/probe-1.txt'));
        self::assertSame('../archives/probe-2.txt', ReachProbe::relativeUrl($this->tool, $archives . '/probe-2.txt'));
        self::assertSame('../../elsewhere/probe%203.txt', ReachProbe::relativeUrl($this->tool, $elsewhere . '/probe 3.txt'));
        self::assertSame(['.state/probe-1.txt', '.other/probe-1.txt'], (new ReachProbe($this->tool))->urls('probe-1.txt', [$this->tool . '/.state', $this->tool . '/.other']));
    }

    public function testTheScriptAsksForEveryAddressAndKeepsTheValuesAwayFromTheMarkup(): void
    {
        $script = (new ReachProbe($this->tool))->script('csrf</script>', ['.state/probe-1.txt', '.other/probe-1.txt'], 'tok"en', ['closed' => 'No', 'reachable' => 'Yes', 'unknown' => '?']);

        self::assertStringContainsString('urls = [".state/probe-1.txt",".other/probe-1.txt"]', $script);
        self::assertStringContainsString('found.indexOf(true) >= 0', $script, 'one open address is enough');
        self::assertStringNotContainsString('</script>', $script);
        self::assertStringContainsString('"tok\"en"', $script);
    }
}
