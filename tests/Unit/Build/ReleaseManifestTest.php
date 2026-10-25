<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Build;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Archive\ZipReader;
use ThreadSafe\Archive\ZipWriter;
use ThreadSafe\Build\ReleaseManifest;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * release.json: what it says, what it refuses, and the check of a package against it (the check that the installer and the update
 * make before any code of a package runs).
 */
final class ReleaseManifestTest extends TestCase
{
    use TempDirectory;

    private const int EPOCH = 1761955200;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    /**
     * @param array<string, string> $files path => content
     */
    private function manifest(array $files, string $version = '1.2.3'): ReleaseManifest
    {
        $listed = [];
        foreach ($files as $path => $content) {
            $listed[$path] = ['size' => strlen($content), 'sha256' => hash('sha256', $content)];
        }
        $requirements = ReleaseManifest::requirements();

        return new ReleaseManifest($version, self::EPOCH, $requirements['php'], $requirements['extensions'], 1, $listed);
    }

    /**
     * @param array<string, string> $entries name in the archive => content
     */
    private function zip(array $entries): ZipReader
    {
        $path = $this->tempDirectory() . '/package.zip';
        $writer = ZipWriter::create($path, 6);
        foreach ($entries as $name => $content) {
            $writer->addString($name, $content, self::EPOCH);
        }
        $writer->close();

        return ZipReader::open($path);
    }

    public function testItIsWrittenAndReadBack(): void
    {
        $manifest = $this->manifest(['src/b.php' => '<?php // b', 'bin/a' => 'a', 'src/a.php' => '<?php // a']);

        $json = $manifest->toJson();
        $back = ReleaseManifest::fromJson($json);

        self::assertEquals($manifest->files + [], $back->files + [], 'the files');
        self::assertSame('1.2.3', $back->version);
        self::assertSame(self::EPOCH, $back->built);
        self::assertSame(1, $back->dataFormat);
        self::assertSame($manifest->extensions, $back->extensions);
        self::assertSame(['bin/a', 'src/a.php', 'src/b.php'], array_keys($back->files), 'sorted, so that the text is the same for the same input');
        self::assertSame($json, $back->toJson());
        self::assertStringContainsString('"built": "2025-11-01T00:00:00Z"', $json);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function badManifests(): iterable
    {
        $good = [
            'version' => '1.0.0', 'built' => '2025-11-01T00:00:00Z', 'php' => '8.4',
            'extensions' => ['required' => [], 'oneOf' => [], 'recommended' => []], 'dataFormat' => 1,
            'files' => ['a.php' => ['size' => 1, 'sha256' => str_repeat('a', 64)]],
        ];
        $with = static fn (array $change): string => (string) json_encode(array_replace($good, $change));

        yield 'not json' => ['{nope', 'not valid JSON'];
        yield 'not an object' => ['"text"', 'not a JSON object'];
        yield 'no version' => [$with(['version' => 'latest']), 'valid version'];
        yield 'no time' => [$with(['built' => 'yesterday']), 'build time'];
        yield 'no php' => [$with(['php' => '>=8.4']), 'PHP version'];
        yield 'data format 0' => [$with(['dataFormat' => 0]), 'data format'];
        yield 'data format as text' => [$with(['dataFormat' => '1']), 'data format'];
        yield 'no extensions' => [$with(['extensions' => 'zlib']), 'extensions'];
        yield 'no files' => [$with(['files' => []]), 'no files'];
        yield 'a path that leaves' => [$with(['files' => ['../x.php' => ['size' => 1, 'sha256' => str_repeat('a', 64)]]]), 'unsafe path'];
        yield 'an absolute path' => [$with(['files' => ['/etc/passwd' => ['size' => 1, 'sha256' => str_repeat('a', 64)]]]), 'unsafe path'];
        yield 'the manifest itself' => [$with(['files' => ['release.json' => ['size' => 1, 'sha256' => str_repeat('a', 64)]]]), 'unsafe path'];
        yield 'a hash that is short' => [$with(['files' => ['a.php' => ['size' => 1, 'sha256' => 'abc']]]), 'size and hash'];
        yield 'a hash in capitals' => [$with(['files' => ['a.php' => ['size' => 1, 'sha256' => str_repeat('A', 64)]]]), 'size and hash'];
        yield 'a negative size' => [$with(['files' => ['a.php' => ['size' => -1, 'sha256' => str_repeat('a', 64)]]]), 'size and hash'];
    }

    #[DataProvider('badManifests')]
    public function testAManifestThatIsNotUsableIsRefused(string $json, string $why): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($why);

        ReleaseManifest::fromJson($json);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function paths(): iterable
    {
        yield 'a file' => ['src/Core/Version.php', true];
        yield 'a dot file' => ['public/.htaccess', true];
        yield 'at the top' => ['composer.json', true];
        yield 'empty' => ['', false];
        yield 'the manifest' => ['release.json', false];
        yield 'absolute' => ['/etc/passwd', false];
        yield 'a drive' => ['C:/x', false];
        yield 'up' => ['src/../../x', false];
        yield 'up at the start' => ['../x', false];
        yield 'dot segment' => ['src/./x', false];
        yield 'empty segment' => ['src//x', false];
        yield 'a directory' => ['src/', false];
        yield 'backslash' => ['src\\x', false];
        yield 'nul' => ["src/x\0.php", false];
    }

    #[DataProvider('paths')]
    public function testWhichPathsAreSafe(string $path, bool $safe): void
    {
        self::assertSame($safe, ReleaseManifest::safePath($path));
    }

    public function testAPackageThatIsWhatTheManifestSaysHasNoProblems(): void
    {
        $files = ['src/a.php' => '<?php // a', 'bin/threadsafe' => "#!/usr/bin/env php\n", 'public/.htaccess' => 'x'];
        $manifest = $this->manifest($files);

        $zip = $this->zip(['threadsafe-1.2.3/release.json' => $manifest->toJson()] + array_combine(array_map(static fn (string $p): string => 'threadsafe-1.2.3/' . $p, array_keys($files)), $files));

        self::assertSame([], $manifest->problems($zip, 'threadsafe-1.2.3/'));
    }

    public function testAChangedFileIsFound(): void
    {
        $manifest = $this->manifest(['src/a.php' => 'original', 'src/b.php' => 'same']);

        $zip = $this->zip(['t/release.json' => '{}', 't/src/a.php' => 'tampered', 't/src/b.php' => 'same']);

        $problems = $manifest->problems($zip, 't/');
        self::assertCount(1, $problems);
        self::assertStringContainsString('"src/a.php" does not match its hash', $problems[0]);
    }

    public function testAFileOfAnotherSizeIsFound(): void
    {
        $manifest = $this->manifest(['src/a.php' => 'original']);

        $zip = $this->zip(['t/src/a.php' => 'originalplus']);

        self::assertStringContainsString('has 12 bytes, the manifest says 8', $manifest->problems($zip, 't/')[0]);
    }

    public function testAFileThatIsNotListedIsFound(): void
    {
        $manifest = $this->manifest(['src/a.php' => 'a']);

        $zip = $this->zip(['t/src/a.php' => 'a', 't/src/extra.php' => '<?php system($_GET["c"]);']);

        self::assertSame(['"src/extra.php" is in the package but not in the manifest.'], $manifest->problems($zip, 't/'));
    }

    public function testAFileThatIsMissingIsFound(): void
    {
        $manifest = $this->manifest(['src/a.php' => 'a', 'src/b.php' => 'b']);

        $zip = $this->zip(['t/src/a.php' => 'a']);

        self::assertSame(['"src/b.php" is in the manifest but not in the package.'], $manifest->problems($zip, 't/'));
    }

    public function testAFileOutsideTheTopDirectoryIsFound(): void
    {
        $manifest = $this->manifest(['src/a.php' => 'a']);

        $zip = $this->zip(['t/src/a.php' => 'a', 'elsewhere/x.php' => 'x']);

        $problems = $manifest->problems($zip, 't/');
        self::assertCount(1, $problems);
        self::assertStringContainsString('"elsewhere/x.php" is outside the directory "t/"', $problems[0]);
    }

    public function testAPathThatCouldNameADriveIsFound(): void
    {
        $manifest = $this->manifest(['src/a.php' => 'a']);

        $zip = $this->zip(['t/src/a.php' => 'a', 't/C:/evil.php' => 'x']);

        $problems = $manifest->problems($zip, 't/');

        self::assertCount(1, $problems);
        self::assertStringContainsString('is not a safe path', $problems[0]);
    }

    public function testADamagedArchiveIsReportedNotThrown(): void
    {
        $manifest = $this->manifest(['src/a.php' => str_repeat('abcdefgh', 4000)]);
        $path = $this->tempDirectory() . '/damaged.zip';
        $writer = ZipWriter::create($path, 6);
        $writer->addString('t/src/a.php', str_repeat('abcdefgh', 4000), self::EPOCH);
        $writer->close();
        $bytes = (string) file_get_contents($path);
        $bytes[60] = $bytes[60] === "\x00" ? "\x01" : "\x00"; // inside the compressed data
        file_put_contents($path, $bytes);

        $problems = $manifest->problems(ZipReader::open($path), 't/');

        self::assertNotSame([], $problems);
    }
}
