<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Web;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Web\ByteRange;
use ThreadSafe\Web\Download;
use ThreadSafe\Web\Request;
use ThreadSafe\Web\Response;

/**
 * The Range header and the sending of a file.
 */
final class DownloadTest extends TestCase
{
    use TempDirectory;

    private string $file;

    private string $content;

    protected function setUp(): void
    {
        $this->content = random_bytes(2_500_000);
        $this->file = $this->tempDirectory() . '/shop_20261021-120000_abcd1234.zip';
        file_put_contents($this->file, $this->content);
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    /**
     * @return iterable<string, array{string|null, int, array{int, int}|null|false}>
     */
    public static function ranges(): iterable
    {
        yield 'none' => [null, 1000, null];
        yield 'from to' => ['bytes=0-9', 1000, [0, 9]];
        yield 'from' => ['bytes=990-', 1000, [990, 999]];
        yield 'suffix' => ['bytes=-10', 1000, [990, 999]];
        yield 'suffix longer than the file' => ['bytes=-5000', 1000, [0, 999]];
        yield 'to is cut at the end' => ['bytes=900-5000', 1000, [900, 999]];
        yield 'one byte' => ['bytes=5-5', 1000, [5, 5]];
        yield 'blanks and capitals' => [' Bytes = 1 - 2 ', 1000, [1, 2]];
        yield 'start behind the end' => ['bytes=1000-', 1000, false];
        yield 'start far behind the end' => ['bytes=99999999999-', 1000, false];
        yield 'empty suffix' => ['bytes=-0', 1000, false];
        yield 'last before first is ignored' => ['bytes=10-5', 1000, null];
        yield 'several ranges are ignored' => ['bytes=0-1,5-6', 1000, null];
        yield 'another unit is ignored' => ['items=0-1', 1000, null];
        yield 'nonsense is ignored' => ['bytes=abc', 1000, null];
        yield 'nothing' => ['bytes=-', 1000, null];
        yield 'empty file' => ['bytes=0-', 0, false];
        yield 'absurd numbers' => ['bytes=0-99999999999999999999999', 1000, [0, 999]];
    }

    /**
     * @param array{int, int}|null|false $expected
     */
    #[DataProvider('ranges')]
    public function testTheRangeHeaderIsReadAsTheStandardSays(?string $header, int $size, array|null|false $expected): void
    {
        self::assertSame($expected, ByteRange::parse($header, $size));
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(array $headers = [], string $method = 'GET'): Request
    {
        return new Request($method, headers: $headers);
    }

    private function body(Response $response): string
    {
        self::assertNotNull($response->stream);
        ob_start();
        try {
            ($response->stream)();
        } finally {
            $out = (string) ob_get_clean();
        }

        return $out;
    }

    public function testTheWholeFileIsSentInBlocksWithItsLengthAndETag(): void
    {
        $response = Download::response($this->request(), $this->file, 'shop_20261021-120000_abcd1234.zip', 'abc123');

        self::assertSame(200, $response->status);
        self::assertSame('2500000', $response->header('Content-Length'));
        self::assertSame('bytes', $response->header('Accept-Ranges'));
        self::assertSame('"abc123"', $response->header('ETag'));
        self::assertSame('attachment; filename="shop_20261021-120000_abcd1234.zip"', $response->header('Content-Disposition'));
        self::assertSame('application/zip', $response->header('Content-Type'));
        self::assertSame('private, no-transform', $response->header('Cache-Control'));
        self::assertSame('', $response->body, 'the content is in the stream, not in memory');
        self::assertSame($this->content, $this->body($response));
    }

    public function testARangeAnswersWithTheRightBytesAndContentRange(): void
    {
        foreach ([['bytes=0-9', 0, 9], ['bytes=1000000-1999999', 1000000, 1999999], ['bytes=2499990-', 2499990, 2499999], ['bytes=-10', 2499990, 2499999]] as [$header, $first, $last]) {
            $response = Download::response($this->request(['range' => $header]), $this->file, 'f.zip', 'abc123');

            self::assertSame(206, $response->status, $header);
            self::assertSame("bytes $first-$last/2500000", $response->header('Content-Range'));
            self::assertSame((string) ($last - $first + 1), $response->header('Content-Length'));
            self::assertSame(substr($this->content, $first, $last - $first + 1), $this->body($response), $header);
        }
    }

    public function testARangeBehindTheEndIsRefusedWithTheSizeOfTheFile(): void
    {
        $response = Download::response($this->request(['range' => 'bytes=2500000-']), $this->file, 'f.zip', 'abc123');

        self::assertSame(416, $response->status);
        self::assertSame('bytes */2500000', $response->header('Content-Range'));
        self::assertNull($response->stream);
    }

    public function testIfRangeMakesTheRangeCountOnlyForTheSameFile(): void
    {
        $same = Download::response($this->request(['range' => 'bytes=0-9', 'if-range' => '"abc123"']), $this->file, 'f.zip', 'abc123');
        $other = Download::response($this->request(['range' => 'bytes=0-9', 'if-range' => '"zzz"']), $this->file, 'f.zip', 'abc123');

        self::assertSame(206, $same->status);
        self::assertSame(200, $other->status, 'the file is not the one the browser started: everything');
        self::assertSame('2500000', $other->header('Content-Length'));

        // a web server in front may strip the ETag; the browser then asks with the date of the file
        $modified = (string) $same->header('Last-Modified');
        self::assertMatchesRegularExpression('/^[A-Z][a-z]{2}, \d{2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2}:\d{2} GMT$/', $modified);
        self::assertSame(206, Download::response($this->request(['range' => 'bytes=0-9', 'if-range' => $modified]), $this->file, 'f.zip', 'abc123')->status);
        self::assertSame(200, Download::response($this->request(['range' => 'bytes=0-9', 'if-range' => 'Mon, 01 Jan 2001 00:00:00 GMT']), $this->file, 'f.zip', 'abc123')->status);
    }

    public function testAHeadRequestGetsTheHeadersOnly(): void
    {
        $response = Download::response($this->request([], 'HEAD'), $this->file, 'f.zip', 'abc123');

        self::assertSame(200, $response->status);
        self::assertSame('2500000', $response->header('Content-Length'));
        self::assertNull($response->stream);
    }

    public function testTheWebServerCanSendTheFileItself(): void
    {
        $sendfile = Download::response($this->request(['range' => 'bytes=0-9']), $this->file, 'f.zip', 'abc123', 'sendfile');
        $nginx = Download::response($this->request(), $this->file, 'f.zip', 'abc123', 'nginx', '/ts-files/');

        self::assertSame($this->file, $sendfile->header('X-Sendfile'));
        self::assertNull($sendfile->stream);
        self::assertSame(200, $sendfile->status, 'the server does the ranges');
        self::assertSame('/ts-files/' . ltrim($this->file, '/'), $nginx->header('X-Accel-Redirect'));
        self::assertNull($nginx->stream);
        // nginx without its location falls back to sending the file here
        self::assertNotNull(Download::response($this->request(), $this->file, 'f.zip', 'abc123', 'nginx', '')->stream);
    }

    public function testTheStartAndTheEndAreReportedWithTheBytesSent(): void
    {
        $events = [];
        $response = Download::response($this->request(['range' => 'bytes=100-199']), $this->file, 'f.zip', 'abc123', report: static function (string $event, array $details) use (&$events): void {
            $events[] = [$event, $details];
        });

        $this->body($response);

        self::assertSame([['download.start', ['file' => 'f.zip', 'from' => 100, 'bytes' => 100]], ['download.end', ['file' => 'f.zip', 'from' => 100, 'sent' => 100, 'complete' => true]]], $events);
    }

    public function testAMissingFileOrAHostileNameIsNotFound(): void
    {
        self::assertSame(404, Download::response($this->request(), $this->file . '.missing', 'f.zip', 'abc')->status);
        self::assertSame(404, Download::response($this->request(), $this->file, "f.zip\r\nX-Evil: 1", 'abc')->status);
        self::assertSame(404, Download::response($this->request(), $this->file, '../f.zip', 'abc')->status);
    }

    public function testAnEmptyFileIsAnEmptyAnswer(): void
    {
        file_put_contents($this->file, '');

        $response = Download::response($this->request(), $this->file, 'f.zip', 'abc');

        self::assertSame(200, $response->status);
        self::assertSame('0', $response->header('Content-Length'));
        self::assertNull($response->stream);
    }
}
