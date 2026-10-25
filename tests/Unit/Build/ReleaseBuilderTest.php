<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Build;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use ThreadSafe\Archive\ZipReader;
use ThreadSafe\Build\ReleaseBuilder;
use ThreadSafe\Build\ReleaseManifest;
use ThreadSafe\Core\Version;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * The release package: which files go in, the stamp of the version, that two builds are the same bytes, and that a package matches
 * its manifest.
 */
final class ReleaseBuilderTest extends TestCase
{
    use TempDirectory;

    private const int EPOCH = 1761955200;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    /** A small tree that has one of everything the builder looks at, and some of what it must leave out. */
    private function tree(string $version = '0.0.0-dev', bool $vendor = true): string
    {
        $root = $this->tempDirectory();
        $files = [
            'bin/threadsafe' => "#!/usr/bin/env php\n<?php // cli\n",
            'src/Core/Version.php' => "<?php\nfinal class Version\n{\n    /** Semantic version; replaced by the release process. */\n    public const string CURRENT = '$version';\n}\n",
            'src/Admin/Page.php' => '<?php // page',
            'plugins/demo/plugin.json' => '{"id": "demo"}',
            'plugins/demo/src/Demo.php' => '<?php // demo',
            'public/.htaccess' => 'RewriteEngine On',
            'public/app.css' => 'body {}',
            'composer.json' => '{"name": "x/y"}',
            'composer.lock' => '{"packages": []}',
            'LICENSE' => 'GPL',
            'README.md' => '# readme',
            'docs/WEB.md' => 'web',
            'docs/PHASE1.md' => 'a plan, not for users',
            'docs/CONCEPT.md' => 'not for users',
            'tests/Unit/FooTest.php' => '<?php // test',
            'tools/build-release.php' => '<?php // tool',
            '.docker/docker-compose.yml' => 'services:',
            '.github/workflows/ci.yml' => 'on: push',
            '.gitignore' => '/vendor',
            'src/.gitkeep' => '',
            'data/profiles/secret.json' => '{"password": "x"}',
        ];
        if ($vendor) {
            $files += ['vendor/autoload.php' => '<?php // autoload', 'vendor/composer/ClassLoader.php' => '<?php // loader'];
        }
        foreach ($files as $path => $content) {
            @mkdir(dirname($root . '/' . $path), 0777, true);
            file_put_contents($root . '/' . $path, $content);
        }

        return $root;
    }

    public function testTheProgramAndTheUserDocumentsGoIn(): void
    {
        $builder = new ReleaseBuilder($this->tree());

        self::assertSame([
            'LICENSE', 'README.md', 'bin/threadsafe', 'composer.json', 'docs/WEB.md', 'plugins/demo/plugin.json',
            'plugins/demo/src/Demo.php', 'public/.htaccess', 'public/app.css', 'src/Admin/Page.php', 'src/Core/Version.php',
        ], $builder->programFiles());
    }

    public function testNothingForDevelopmentOrOfTheUsersDataGoesIn(): void
    {
        $files = (new ReleaseBuilder($this->tree()))->packageFiles();

        foreach (['tests/', 'tools/', '.docker/', '.github/', 'data/', 'docs/PHASE', 'docs/CONCEPT', 'composer.lock', '.gitignore', '.gitkeep'] as $forbidden) {
            foreach ($files as $file) {
                self::assertStringNotContainsString($forbidden, $file, "$file must not be in the package");
            }
        }
    }

    public function testTheVendorFilesAreAddedWhenThereAre(): void
    {
        self::assertSame(['vendor/autoload.php', 'vendor/composer/ClassLoader.php'], array_values(array_filter((new ReleaseBuilder($this->tree()))->packageFiles(), static fn (string $f): bool => str_starts_with($f, 'vendor/'))));
        self::assertSame([], array_values(array_filter((new ReleaseBuilder($this->tree(vendor: false)))->packageFiles(), static fn (string $f): bool => str_starts_with($f, 'vendor/'))));
    }

    public function testTheVersionIsStampedOnce(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Core/Version.php');

        $stamped = ReleaseBuilder::stampVersion($source, '1.2.3-rc.1');

        self::assertStringContainsString("public const string CURRENT = '1.2.3-rc.1';", $stamped);
        self::assertStringNotContainsString('0.0.0-dev', $stamped);
        self::assertSame(substr_count($source, "\n"), substr_count($stamped, "\n"), 'nothing else changed');
    }

    public function testAVersionThatIsNotOneIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not a valid version');

        ReleaseBuilder::stampVersion('public const string CURRENT = \'0.0.0-dev\';', "1.0'; system('x'); //");
    }

    public function testASourceWithoutTheLineIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not exactly one line');

        ReleaseBuilder::stampVersion('<?php // nothing here', '1.0.0');
    }

    public function testStagingCopiesTheProgramAndStampsTheCopy(): void
    {
        $root = $this->tree();
        $stage = $this->tempDirectory() . '/stage';

        (new ReleaseBuilder($root))->stage($stage, '2.0.0');

        self::assertFileExists($stage . '/bin/threadsafe');
        self::assertFileExists($stage . '/composer.lock', 'Composer needs the lock to make the same autoloader');
        self::assertFileDoesNotExist($stage . '/tests/Unit/FooTest.php');
        self::assertFileDoesNotExist($stage . '/data/profiles/secret.json');
        self::assertStringContainsString("'2.0.0'", (string) file_get_contents($stage . '/src/Core/Version.php'));
        self::assertStringContainsString("'0.0.0-dev'", (string) file_get_contents($root . '/src/Core/Version.php'), 'the source is not changed');
        self::assertSame('2.0.0', (new ReleaseBuilder($stage))->stampedVersion());
    }

    public function testStagingIntoAFullDirectoryIsRefused(): void
    {
        $stage = $this->tempDirectory();
        file_put_contents($stage . '/old', 'x');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not empty');

        (new ReleaseBuilder($this->tree()))->stage($stage, '1.0.0');
    }

    public function testATreeThatIsNotStampedMakesNoPackage(): void
    {
        $zip = $this->tempDirectory() . '/out.zip';

        try {
            (new ReleaseBuilder($this->tree('0.0.0-dev')))->write($zip, '1.0.0', self::EPOCH);
            self::fail('expected a refusal');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('stamp it first', $e->getMessage());
        }
        self::assertFileDoesNotExist($zip);
    }

    /** @return array{ZipReader, ReleaseManifest, string} */
    private function built(string $version = '1.2.3', int $epoch = self::EPOCH): array
    {
        $stage = $this->tempDirectory() . '/stage';
        (new ReleaseBuilder($this->tree()))->stage($stage, $version);
        mkdir($stage . '/vendor/composer', 0777, true);
        file_put_contents($stage . '/vendor/autoload.php', '<?php // autoload');
        file_put_contents($stage . '/vendor/composer/ClassLoader.php', '<?php // loader');
        $zip = $this->tempDirectory() . '/threadsafe-' . $version . '.zip';
        $manifest = (new ReleaseBuilder($stage))->write($zip, $version, $epoch);

        return [ZipReader::open($zip), $manifest, $zip];
    }

    public function testThePackageHasTheManifestFirstAndMatchesIt(): void
    {
        [$zip, $manifest] = $this->built();
        $top = 'threadsafe-1.2.3/';

        $first = $zip->entries()->current();
        self::assertSame($top . 'release.json', $first->name);
        $read = ReleaseManifest::fromJson($zip->contents($first));
        self::assertSame('1.2.3', $read->version);
        self::assertSame(Version::DATA_FORMAT, $read->dataFormat);
        self::assertSame(self::EPOCH, $read->built);
        self::assertSame(array_keys($manifest->files), array_keys($read->files));
        self::assertSame([], $read->problems($zip, $top));
        self::assertCount(count($manifest->files) + 1, iterator_to_array($zip->entries()), 'the files and the manifest');
        self::assertArrayHasKey('vendor/autoload.php', $read->files);
        self::assertArrayNotHasKey('composer.lock', $read->files);
    }

    public function testEveryFileHasTheTimeOfTheBuildAndAFixedMode(): void
    {
        [$zip] = $this->built();

        foreach ($zip->entries() as $entry) {
            self::assertSame(self::EPOCH, $entry->mtime, $entry->name);
            $expected = str_starts_with($entry->name, 'threadsafe-1.2.3/bin/') ? 0100755 : 0100644;
            self::assertSame($expected, $entry->mode, $entry->name);
        }
    }

    public function testTheVersionInThePackageIsTheStampedOne(): void
    {
        [$zip] = $this->built('1.2.3');

        $entry = $zip->find('threadsafe-1.2.3/src/Core/Version.php');

        self::assertNotNull($entry);
        self::assertStringContainsString("CURRENT = '1.2.3';", $zip->contents($entry));
    }

    public function testTwoBuildsAreTheSameBytes(): void
    {
        [, , $one] = $this->built();
        [, , $two] = $this->built();

        self::assertNotSame($one, $two);
        self::assertSame(hash_file('sha256', $one), hash_file('sha256', $two));
    }

    public function testAnotherTimeOrVersionIsAnotherPackage(): void
    {
        [, , $one] = $this->built('1.2.3', self::EPOCH);
        [, , $later] = $this->built('1.2.3', self::EPOCH + 86400);
        [, , $next] = $this->built('1.2.4', self::EPOCH);

        self::assertNotSame(hash_file('sha256', $one), hash_file('sha256', $later));
        self::assertNotSame(hash_file('sha256', $one), hash_file('sha256', $next));
    }

    public function testTheFileOfChecksumsIsInTheFormatOfSha256sum(): void
    {
        $directory = $this->tempDirectory();
        file_put_contents($directory . '/b.zip', 'bbb');
        file_put_contents($directory . '/a.php', 'aaa');

        $sums = ReleaseBuilder::checksums(['b.zip' => $directory . '/b.zip', 'a.php' => $directory . '/a.php']);

        self::assertSame(hash('sha256', 'aaa') . "  a.php\n" . hash('sha256', 'bbb') . "  b.zip\n", $sums);
    }

    public function testTheRealTreeMakesAPackageOfTheExpectedShape(): void
    {
        $builder = new ReleaseBuilder(dirname(__DIR__, 3));

        $files = $builder->programFiles();

        foreach (['bin/threadsafe', 'public/index.php', 'public/.htaccess', 'public/app.css', 'public/app.js', 'src/Core/Version.php', 'src/Admin/Kernel.php', 'src/Build/RestoreBuilder.php', 'plugins/prestashop9/plugin.json', 'plugins/joomla/plugin.json', 'plugins/generic/plugin.json', 'composer.json', 'LICENSE', 'docs/WEB.md'] as $expected) {
            self::assertContains($expected, $files);
        }
        foreach ($files as $file) {
            self::assertDoesNotMatchRegularExpression('#^(tests|tools|\.docker|\.github|data|vendor|build|dist)/#', $file);
        }
        self::assertGreaterThan(300, count($files));
        self::assertLessThan(600, count($files));
        $bytes = array_sum(array_map(static fn (string $f): int => (int) filesize(dirname(__DIR__, 3) . '/' . $f), $files));
        self::assertLessThan(4 * 1048576, $bytes, 'the package stays small enough for any upload limit');
        $stage = $this->tempDirectory() . '/stage';
        $builder->stage($stage, '9.9.9');
        self::assertSame('9.9.9', (new ReleaseBuilder($stage))->stampedVersion());
    }
}
