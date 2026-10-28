<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Build;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use ThreadSafe\Archive\ZipException;
use ThreadSafe\Archive\ZipReader;
use ThreadSafe\Archive\ZipWriter;
use ThreadSafe\Build\ZipShift;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * An archive behind a stub: without moving its offsets `unzip` warns and our reader refuses; with them both read it
 * (docs/PHASE7.md section 8, finding 2).
 */
final class ZipShiftTest extends TestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    private function archive(): string
    {
        $path = $this->tempDirectory() . '/a.zip';
        $writer = ZipWriter::create($path, 6);
        $writer->addString('top/one.txt', str_repeat('one ', 500), 1761955200);
        $writer->addString('top/two/three.txt', 'three', 1761955200);
        $writer->addString('top/four.php', '<?php // four', 1761955200, 0100755);
        $writer->close();

        return (string) file_get_contents($path);
    }

    public function testTheArchiveBehindAStubIsReadAfterTheShift(): void
    {
        $stub = "<?php exit; __halt_compiler();" . str_repeat('x', 1234);
        $file = $this->tempDirectory() . '/threadsafe-install.php';
        file_put_contents($file, $stub . ZipShift::shift($this->archive(), strlen($stub)));

        $zip = ZipReader::open($file);

        self::assertSame(3, $zip->count());
        self::assertTrue($zip->verify()->ok());
        $entry = $zip->find('top/two/three.txt');
        self::assertNotNull($entry);
        self::assertSame('three', $zip->contents($entry));
        $exec = $zip->find('top/four.php');
        self::assertSame(0100755, $exec?->mode);
    }

    public function testWithoutTheShiftTheReaderRefusesAndUnzipWarns(): void
    {
        $stub = '<?php exit; __halt_compiler();';
        $file = $this->tempDirectory() . '/threadsafe-install.php';
        file_put_contents($file, $stub . $this->archive());

        try {
            $problems = ZipReader::open($file)->verify()->problems;
        } catch (ZipException) {
            $problems = ['refused'];
        }

        self::assertNotSame([], $problems, 'the offsets point to the wrong places');
    }

    public function testUnzipOpensTheShiftedFileWithoutAWarning(): void
    {
        if ((string) shell_exec('command -v unzip') === '') {
            self::markTestSkipped('unzip is not installed.');
        }
        $stub = "<?php exit; __halt_compiler();" . str_repeat('y', 77);
        $file = $this->tempDirectory() . '/threadsafe-install.php';
        file_put_contents($file, $stub . ZipShift::shift($this->archive(), strlen($stub)));

        $output = [];
        exec('unzip -tq ' . escapeshellarg($file) . ' 2>&1', $output, $code);

        self::assertSame(0, $code, implode("\n", $output));
        self::assertStringNotContainsString('extra bytes', implode("\n", $output));
    }

    public function testAShiftOfZeroChangesNothing(): void
    {
        $zip = $this->archive();

        self::assertSame($zip, ZipShift::shift($zip, 0));
    }

    public function testWhatIsNoArchiveIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no end record');

        ZipShift::shift('not an archive at all, not even close', 10);
    }

    public function testAnArchiveWithZip64RecordsIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ZIP64');

        ZipShift::shift($this->archive() . "PK\x06\x07", 10);
    }

    public function testADamagedCentralDirectoryIsRefused(): void
    {
        $zip = $this->archive();
        $end = (int) strrpos($zip, "PK\x05\x06");
        /** @var array{1: int} $offset */
        $offset = unpack('V', substr($zip, $end + 16, 4));
        $zip = substr_replace($zip, 'XXXX', $offset[1], 4);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('damaged at record 0');

        ZipShift::shift($zip, 10);
    }
}
