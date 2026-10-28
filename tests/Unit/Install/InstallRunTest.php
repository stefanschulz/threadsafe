<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Install;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Archive\ZipReader;
use ThreadSafe\Archive\ZipWriter;
use ThreadSafe\Build\ReleaseManifest;
use ThreadSafe\Core\Home;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Install\InstallException;
use ThreadSafe\Install\InstallRun;
use ThreadSafe\Tests\Support\ReleasePackage;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * The installation in ticks: what it writes where, that the pointer comes last, that it goes on after a cut-off, and that a package
 * that is not what its manifest says is refused before anything runs.
 */
final class InstallRunTest extends TestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    /** @return array{string, string, string} the package, the document root and the home to be */
    private function host(string $version = '0.1.0'): array
    {
        $base = $this->tempDirectory();
        $docroot = $base . '/public_html';
        mkdir($docroot);

        return [ReleasePackage::build($base, $version), $docroot, $base . '/threadsafe'];
    }

    /**
     * Runs the task to its end, one entry per tick.
     *
     * @param array<string, mixed> $state
     *
     * @return array{array<string, mixed>, int, list<string>} the final state, the number of ticks and the progress lines
     */
    private function runToEnd(InstallRun $task, array $state, ?callable $afterTick = null): array
    {
        $ticks = 0;
        $progress = [];
        do {
            $outcome = $task->tick($state, new Budget(0.0));
            $state = $outcome->state;
            $progress[] = (string) $outcome->progress;
            ++$ticks;
            if ($afterTick !== null) {
                $afterTick($state);
            }
            self::assertLessThan(500, $ticks, 'the task does not end');
        } while (!$outcome->finished);

        return [$state, $ticks, $progress];
    }

    public function testItInstallsTheReleaseAndWritesThePointerLast(): void
    {
        [$package, $docroot, $home] = $this->host();
        $task = new InstallRun();
        $state = $task->initialState(['file' => $package, 'docroot' => $docroot, 'home' => $home]);
        $pointerSeenEarly = false;

        [$state, $ticks] = $this->runToEnd($task, $state, static function (array $state) use ($home, &$pointerSeenEarly): void {
            if ($state['phase'] !== 'done' && is_file($home . '/current.php')) {
                $pointerSeenEarly = true;
            }
        });

        self::assertFalse($pointerSeenEarly, 'until the last tick there is no pointer, so nothing half works');
        self::assertGreaterThan(5, $ticks, 'one file per tick with a budget of nothing');
        self::assertSame('done', $state['phase']);
        self::assertSame(['release' => '0.1.0', 'previous' => null], Home::readPointer($home));
        self::assertFileExists($home . '/releases/0.1.0/vendor/autoload.php');
        self::assertDirectoryDoesNotExist($home . '/releases/0.1.0.partial');
        self::assertFileExists($home . '/releases/0.1.0/release.json');
        self::assertSame('body { color: #000; }', file_get_contents($home . '/releases/0.1.0/public/app.css'));
        self::assertSame($state['expected'], $state['files'], 'every file of the manifest, and the manifest');
    }

    public function testTheStubTheCommandAndTheDataAreInPlace(): void
    {
        [$package, $docroot, $home] = $this->host();
        $task = new InstallRun();

        [$state] = $this->runToEnd($task, $task->initialState(['file' => $package, 'docroot' => $docroot, 'home' => $home]));

        self::assertStringContainsString("\$home = '$home';", (string) file_get_contents($docroot . '/index.php'));
        self::assertStringContainsString('RewriteRule ^ index.php [L]', (string) file_get_contents($docroot . '/.htaccess'));
        self::assertStringContainsString("\$home = '$home';", (string) file_get_contents($home . '/bin/threadsafe'));
        self::assertTrue(is_executable($home . '/bin/threadsafe'));
        self::assertTrue(is_executable($home . '/releases/0.1.0/bin/threadsafe'), 'the mode of the package is kept');
        self::assertFalse(is_executable($home . '/releases/0.1.0/public/app.css'));
        self::assertFileExists($home . '/data/.htaccess', 'the data directory is closed to the web');
        self::assertFileExists($home . '/data/index.html');
        self::assertSame("<?php return '" . $state['setupKey'] . "';\n", file_get_contents($home . '/data/setup-key.php'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{24}$/', (string) $state['setupKey']);
        if (DIRECTORY_SEPARATOR === '/') {
            self::assertSame('0600', substr(sprintf('%o', fileperms($home . '/data/setup-key.php')), -4));
            self::assertSame('0700', substr(sprintf('%o', fileperms($home . '/data')), -4));
        }
        self::assertSame([], glob($home . '/releases/0.1.0/**/*.tmp') ?: [], 'no temporary file is left');
    }

    public function testAHomeBelowTheDocumentRootIsClosedByAnHtaccess(): void
    {
        [$package, $docroot] = $this->host();
        $home = $docroot . '/threadsafe-home';
        $task = new InstallRun();

        $this->runToEnd($task, $task->initialState(['file' => $package, 'docroot' => $docroot, 'home' => $home]));

        self::assertStringContainsString('Require all denied', (string) file_get_contents($home . '/.htaccess'));
        self::assertFileExists($home . '/index.html');
    }

    public function testAHomeNextToTheDocumentRootGetsNoHtaccessOfItsOwn(): void
    {
        [$package, $docroot, $home] = $this->host();
        $task = new InstallRun();

        $this->runToEnd($task, $task->initialState(['file' => $package, 'docroot' => $docroot, 'home' => $home]));

        self::assertFileDoesNotExist($home . '/.htaccess');
    }

    public function testItGoesOnWhereItWasAfterACutOff(): void
    {
        [$package, $docroot, $home] = $this->host();
        $task = new InstallRun();
        $state = $task->initialState(['file' => $package, 'docroot' => $docroot, 'home' => $home]);
        for ($i = 0; $i < 6; ++$i) {
            $state = $task->tick($state, new Budget(0.0))->state;
        }
        $copy = json_decode((string) json_encode($state), true);   // the state goes through a file between two requests
        self::assertIsArray($copy);
        // a file that was cut off in the middle is written again
        @mkdir($home . '/releases/0.1.0.partial/src', 0777, true);
        file_put_contents($home . '/releases/0.1.0.partial/src/half.tmp', 'cut off');

        [$final] = $this->runToEnd(new InstallRun(), $copy);

        self::assertSame('done', $final['phase']);
        self::assertSame(['release' => '0.1.0', 'previous' => null], Home::readPointer($home));
    }

    public function testASecondTickOfTheLastPhaseChangesNothingThatMatters(): void
    {
        [$package, $docroot, $home] = $this->host();
        $task = new InstallRun();
        [$state] = $this->runToEnd($task, $task->initialState(['file' => $package, 'docroot' => $docroot, 'home' => $home]));
        $state['phase'] = 'finish';

        $outcome = $task->tick($state, new Budget(0.0));

        self::assertTrue($outcome->finished);
        self::assertSame($state['setupKey'], $outcome->state['setupKey'], 'the key is made once');
        self::assertSame(['release' => '0.1.0', 'previous' => null], Home::readPointer($home));
    }

    public function testADocumentRootThatHasAnIndexPhpIsNotOverwritten(): void
    {
        [$package, $docroot, $home] = $this->host();
        file_put_contents($docroot . '/index.php', '<?php // the site that is there');
        $task = new InstallRun();

        $this->expectException(InstallException::class);
        $this->expectExceptionMessage('"index.php" exists');

        $task->tick($task->initialState(['file' => $package, 'docroot' => $docroot, 'home' => $home]), new Budget(0.0));
    }

    public function testAReleaseThatIsThereAlreadyIsNotReplaced(): void
    {
        [$package, $docroot, $home] = $this->host();
        mkdir($home . '/releases/0.1.0', 0777, true);
        $task = new InstallRun();

        $this->expectException(InstallException::class);
        $this->expectExceptionMessage('already in');

        $task->tick($task->initialState(['file' => $package, 'docroot' => $docroot, 'home' => $home]), new Budget(0.0));
    }

    /**
     * A package made by hand, with a manifest that says something else than the files are.
     *
     * @param array<string, string>                          $entries  path below the top directory => content
     * @param array<string, array{size: int, sha256: string}> $listed   what the manifest lists
     */
    private function forged(array $entries, array $listed, string $top = 'threadsafe-0.1.0/'): string
    {
        $path = $this->tempDirectory() . '/forged.zip';
        $requirements = ReleaseManifest::requirements();
        $manifest = new ReleaseManifest('0.1.0', ReleasePackage::EPOCH, $requirements['php'], $requirements['extensions'], 1, $listed);
        $writer = ZipWriter::create($path, 6);
        $writer->addString('threadsafe-0.1.0/release.json', $manifest->toJson(), ReleasePackage::EPOCH);
        foreach ($entries as $name => $content) {
            $writer->addString($top . $name, $content, ReleasePackage::EPOCH);
        }
        $writer->close();

        return $path;
    }

    /** @return array{size: int, sha256: string} */
    private static function listing(string $content): array
    {
        return ['size' => strlen($content), 'sha256' => hash('sha256', $content)];
    }

    private function install(string $package): void
    {
        $base = $this->tempDirectory();
        mkdir($base . '/public_html');
        $task = new InstallRun();
        $this->runToEnd($task, $task->initialState(['file' => $package, 'docroot' => $base . '/public_html', 'home' => $base . '/threadsafe']));
    }

    public function testAFileThatDoesNotMatchItsHashStopsTheInstallation(): void
    {
        $package = $this->forged(['vendor/autoload.php' => '<?php // changed'], ['vendor/autoload.php' => self::listing('<?php // as listed')]);

        $this->expectException(InstallException::class);
        $this->expectExceptionMessage('does not match its hash');

        $this->install($package);
    }

    public function testAFileThatIsNotInTheManifestStopsTheInstallation(): void
    {
        $package = $this->forged(['vendor/autoload.php' => '<?php', 'evil.php' => '<?php system($_GET["c"]);'], ['vendor/autoload.php' => self::listing('<?php')]);

        $this->expectException(InstallException::class);
        $this->expectExceptionMessage('"evil.php" is in the package but not in its manifest');

        $this->install($package);
    }

    public function testAFileOutsideTheTopDirectoryStopsTheInstallation(): void
    {
        $package = $this->forged(['vendor/autoload.php' => '<?php'], ['vendor/autoload.php' => self::listing('<?php')]);
        // add an entry outside the top directory to the same archive
        $path = $this->tempDirectory() . '/outside.zip';
        $writer = ZipWriter::create($path, 6);
        $reader = ZipReader::open($package);
        foreach ($reader->entries() as $entry) {
            $writer->addString($entry->name, $reader->contents($entry), ReleasePackage::EPOCH);
        }
        $writer->addString('elsewhere/x.php', '<?php', ReleasePackage::EPOCH);
        $writer->close();

        $this->expectException(InstallException::class);
        $this->expectExceptionMessage('is outside the directory');

        $this->install($path);
    }

    public function testAPackageWithFewerFilesThanItsManifestListsIsRefused(): void
    {
        $package = $this->forged(['vendor/autoload.php' => '<?php'], ['vendor/autoload.php' => self::listing('<?php'), 'src/missing.php' => self::listing('<?php')]);

        $this->expectException(InstallException::class);
        $this->expectExceptionMessage('holds 2 files, its manifest lists 3');

        $this->install($package);
    }

    public function testAPackageWithoutAnAutoloaderIsRefusedBeforeItIsNamed(): void
    {
        $package = $this->forged(['src/a.php' => '<?php'], ['src/a.php' => self::listing('<?php')]);
        $base = $this->tempDirectory();
        mkdir($base . '/public_html');

        try {
            $this->install($package);
            self::fail('expected a refusal');
        } catch (InstallException $e) {
            self::assertStringContainsString('no vendor/autoload.php', $e->getMessage());
        }
    }

    public function testAnEntryThatIsNoPlainFileIsRefused(): void
    {
        $path = $this->tempDirectory() . '/link.zip';
        $requirements = ReleaseManifest::requirements();
        $manifest = new ReleaseManifest('0.1.0', ReleasePackage::EPOCH, $requirements['php'], $requirements['extensions'], 1, ['vendor/autoload.php' => self::listing('/etc/passwd')]);
        $writer = ZipWriter::create($path, 6);
        $writer->addString('threadsafe-0.1.0/release.json', $manifest->toJson(), ReleasePackage::EPOCH);
        $writer->addString('threadsafe-0.1.0/vendor/autoload.php', '/etc/passwd', ReleasePackage::EPOCH, 0120777);
        $writer->close();

        $this->expectException(InstallException::class);
        $this->expectExceptionMessage('is not a plain file');

        $this->install($path);
    }

    public function testNothingIsKeptAfterAFailureThatHappenedBeforeTheLastTick(): void
    {
        $package = $this->forged(['vendor/autoload.php' => '<?php // changed'], ['vendor/autoload.php' => self::listing('<?php // as listed')]);
        $base = $this->tempDirectory();
        mkdir($base . '/public_html');
        $task = new InstallRun();

        try {
            $this->runToEnd($task, $task->initialState(['file' => $package, 'docroot' => $base . '/public_html', 'home' => $base . '/threadsafe']));
            self::fail('expected a refusal');
        } catch (InstallException) {
            self::assertFileDoesNotExist($base . '/threadsafe/current.php');
            self::assertFileDoesNotExist($base . '/public_html/index.php');
            self::assertDirectoryDoesNotExist($base . '/threadsafe/releases/0.1.0');
        }
    }

    public function testAFileThatIsNoPackageIsRefusedAtTheStart(): void
    {
        $file = $this->tempDirectory() . '/threadsafe-install.php';
        file_put_contents($file, '<?php echo "not a zip";');

        $this->expectException(InstallException::class);
        $this->expectExceptionMessage('damaged');

        (new InstallRun())->initialState(['file' => $file, 'docroot' => dirname($file), 'home' => dirname($file) . '/home']);
    }
}
