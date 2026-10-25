<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Update;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Archive\ZipWriter;
use ThreadSafe\Build\ReleaseManifest;
use ThreadSafe\Core\Home;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Engine\Lock;
use ThreadSafe\Install\InstallRun;
use ThreadSafe\Tests\Support\ReleasePackage;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Update\DataFormat;
use ThreadSafe\Update\HealthResult;
use ThreadSafe\Update\UpdateException;
use ThreadSafe\Update\UpdateRun;

/**
 * The update in ticks: verified before anything changes, unpacked beside the running release, switched by the pointer, taken back when the
 * new version does not start, and refused when the package, the host or the moment is wrong.
 */
final class UpdateRunTest extends TestCase
{
    use TempDirectory;

    private const int NOW = 1800000000;

    private string $base = '';

    private string $home = '';

    private string $data = '';

    protected function setUp(): void
    {
        $this->base = $this->tempDirectory();
        mkdir($this->base . '/public_html');
        $this->home = $this->base . '/threadsafe';
        $this->data = $this->home . '/data';
        $this->install('0.1.0');
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    /** Installs a release with the real installer task: the starting point of every update. */
    private function install(string $version): void
    {
        $package = ReleasePackage::build($this->base, $version);
        $task = new InstallRun();
        $state = $task->initialState(['file' => $package, 'docroot' => $this->base . '/public_html', 'home' => $this->home]);
        do {
            $outcome = $task->tick($state, Budget::unlimited());
            $state = $outcome->state;
        } while (!$outcome->finished);
    }

    private function package(string $version, string $directory = ''): string
    {
        return ReleasePackage::build($directory !== '' ? $directory : $this->tempDirectory(), $version);
    }

    /**
     * @param (\Closure(string, string, string): HealthResult)|null $health
     *
     * @return array<string, mixed>
     */
    private function update(string $package, ?\Closure $health = null, ?string $sha = null, bool $downgrade = false): array
    {
        $task = new UpdateRun($health ?? static fn (): HealthResult => HealthResult::ok('started'), static fn (): int => self::NOW);
        $state = $task->initialState(['file' => $package, 'sha256' => $sha ?? hash_file('sha256', $package), 'home' => $this->home, 'data' => $this->data, 'allowDowngrade' => $downgrade]);
        $ticks = 0;
        do {
            $outcome = $task->tick($state, new Budget(0.0));
            $state = $outcome->state;
            self::assertLessThan(500, ++$ticks);
        } while (!$outcome->finished);

        return $state;
    }

    private function release(): ?string
    {
        return Home::readPointer($this->home)['release'] ?? null;
    }

    public function testItSwitchesToTheNewReleaseAndKeepsTheOldOneForARollback(): void
    {
        $state = $this->update($this->package('0.2.0'));

        self::assertSame(['release' => '0.2.0', 'previous' => '0.1.0'], Home::readPointer($this->home));
        self::assertFileExists($this->home . '/releases/0.1.0/vendor/autoload.php');
        self::assertFileExists($this->home . '/releases/0.2.0/vendor/autoload.php');
        self::assertDirectoryDoesNotExist($this->home . '/releases/0.2.0.partial');
        self::assertFalse($state['result']['rolledBack']);
        self::assertSame(['from' => '0.1.0', 'to' => '0.2.0', 'health' => 'ok'], array_intersect_key($state['result'], array_flip(['from', 'to', 'health'])));
        self::assertDirectoryExists($state['result']['copy'], 'the copy of the state files was made');
        self::assertStringContainsString('0.1.0-20270115', $state['result']['copy']);
    }

    public function testThePackageIsRemovedWhenItIsInstalled(): void
    {
        $package = $this->package('0.2.0');

        $this->update($package);

        self::assertFileDoesNotExist($package);
    }

    public function testOnlyTheActiveAndThePreviousReleaseStay(): void
    {
        $this->update($this->package('0.2.0'));
        mkdir($this->home . '/releases/0.0.9/vendor', 0777, true);
        file_put_contents($this->home . '/releases/0.0.9/vendor/autoload.php', '<?php');

        $this->update($this->package('0.3.0'));

        self::assertSame(['0.2.0', '0.3.0'], array_values(array_diff(scandir($this->home . '/releases') ?: [], ['.', '..'])));
        self::assertSame(['release' => '0.3.0', 'previous' => '0.2.0'], Home::readPointer($this->home));
    }

    public function testTheRunningProgramIsNotTouched(): void
    {
        $before = hash_file('sha256', $this->home . '/releases/0.1.0/release.json');
        $stub = (string) file_get_contents($this->base . '/public_html/index.php');

        $this->update($this->package('0.2.0'));

        self::assertSame($before, hash_file('sha256', $this->home . '/releases/0.1.0/release.json'));
        self::assertSame($stub, file_get_contents($this->base . '/public_html/index.php'), 'the stub never changes with a release');
    }

    public function testAHealthCheckThatFailsTakesTheSwitchBack(): void
    {
        file_put_contents($this->data . '/config.json', '{"web": {"enabled": true}}');
        @mkdir($this->data . '/profiles');
        file_put_contents($this->data . '/profiles/shop.json', '{"id": "shop"}');
        $health = function (string $home, string $data, string $version): HealthResult {
            // what a migration that stopped half way would leave behind
            file_put_contents($data . '/config.json', 'half written');
            unlink($data . '/profiles/shop.json');
            file_put_contents($data . '/profiles/new-format.json', '{}');

            return HealthResult::failed('The new version did not answer.');
        };

        $state = $this->update($this->package('0.2.0'), $health);

        self::assertTrue($state['result']['rolledBack']);
        self::assertSame(['release' => '0.1.0', 'previous' => null], Home::readPointer($this->home), 'the old release is active again');
        self::assertSame('{"web": {"enabled": true}}', file_get_contents($this->data . '/config.json'), 'the state files are as they were');
        self::assertFileExists($this->data . '/profiles/shop.json');
        self::assertFileDoesNotExist($this->data . '/profiles/new-format.json', 'what the failed update made is gone');
        self::assertDirectoryDoesNotExist($this->home . '/releases/0.2.0', 'the release that did not start is removed');
        self::assertStringContainsString('did not answer', $state['result']['message']);
    }

    public function testARollbackKeepsThePreviousOfTheReleaseThatWasActive(): void
    {
        $this->update($this->package('0.2.0'));

        $this->update($this->package('0.3.0'), static fn (): HealthResult => HealthResult::failed('no'));

        self::assertSame(['release' => '0.2.0', 'previous' => '0.1.0'], Home::readPointer($this->home));
    }

    public function testAHostThatCannotCheckIsToldSoAndTheUpdateStands(): void
    {
        $state = $this->update($this->package('0.2.0'), static fn (): HealthResult => HealthResult::skipped('allow_url_fopen is off'));

        self::assertFalse($state['result']['rolledBack']);
        self::assertSame('skipped', $state['result']['health']);
        self::assertSame('0.2.0', $this->release());
    }

    public function testWithoutAHealthCheckTheResultSaysItWasNotAsked(): void
    {
        $task = new UpdateRun();
        $package = $this->package('0.2.0');
        $state = $task->initialState(['file' => $package, 'sha256' => hash_file('sha256', $package), 'home' => $this->home, 'data' => $this->data]);
        do {
            $outcome = $task->tick($state, new Budget(0.0));
            $state = $outcome->state;
        } while (!$outcome->finished);

        self::assertSame('skipped', $state['result']['health']);
    }

    public function testAChecksumThatIsNotTheOneOfTheFileChangesNothing(): void
    {
        $package = $this->package('0.2.0');

        try {
            $this->update($package, null, str_repeat('a', 64));
            self::fail('expected a refusal');
        } catch (UpdateException $e) {
            self::assertStringContainsString('The checksum of the file is ' . hash_file('sha256', $package), $e->getMessage());
        }
        self::assertSame('0.1.0', $this->release());
        self::assertDirectoryDoesNotExist($this->home . '/releases/0.2.0.partial');
        self::assertFileExists($package, 'the package stays');
    }

    public function testAChecksumInTheWrongShapeIsRefusedWithTheShapeItNeeds(): void
    {
        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage('64 hexadecimal characters');

        $this->update($this->package('0.2.0'), null, 'abc');
    }

    public function testACapitalLetterInTheChecksumDoesNotMatter(): void
    {
        $package = $this->package('0.2.0');

        $this->update($package, null, strtoupper((string) hash_file('sha256', $package)));

        self::assertSame('0.2.0', $this->release());
    }

    public function testTheSameVersionIsNotInstalledAgain(): void
    {
        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage('Version 0.1.0 is installed already');

        $this->update($this->package('0.1.0'));
    }

    public function testAnOlderVersionNeedsToBeAskedFor(): void
    {
        $this->update($this->package('0.2.0'));
        $older = $this->package('0.1.5');

        try {
            $this->update($older);
            self::fail('expected a refusal');
        } catch (UpdateException $e) {
            self::assertStringContainsString('older than the installed 0.2.0', $e->getMessage());
        }

        $this->update($older, null, null, downgrade: true);
        self::assertSame('0.1.5', $this->release());
    }

    public function testDataOfANewerFormatThanThePackageWritesIsNotGivenToIt(): void
    {
        DataFormat::write($this->data, 2, '0.9.0');

        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage('has format 2; version 0.2.0 writes format 1');

        $this->update($this->package('0.2.0'));
    }

    public function testAPackageThatTheHostCannotRunIsRefusedBeforeAnythingIsUnpacked(): void
    {
        $path = $this->tempDirectory() . '/needs-more.zip';
        $requirements = ReleaseManifest::requirements();
        $listed = ['vendor/autoload.php' => ['size' => 5, 'sha256' => hash('sha256', '<?php')]];
        $manifest = new ReleaseManifest('0.2.0', self::NOW, '99.0', $requirements['extensions'], 1, $listed);
        $writer = ZipWriter::create($path, 6);
        $writer->addString('threadsafe-0.2.0/release.json', $manifest->toJson(), self::NOW);
        $writer->addString('threadsafe-0.2.0/vendor/autoload.php', '<?php', self::NOW);
        $writer->close();

        try {
            $this->update($path);
            self::fail('expected a refusal');
        } catch (UpdateException $e) {
            self::assertStringContainsString('This host cannot run version 0.2.0', $e->getMessage());
            self::assertStringContainsString('needs PHP 99.0 or newer', $e->getMessage());
        }
        self::assertDirectoryDoesNotExist($this->home . '/releases/0.2.0.partial');
    }

    public function testAFileThatIsNoPackageIsRefused(): void
    {
        $file = $this->tempDirectory() . '/not.zip';
        file_put_contents($file, 'not a package');

        $this->expectException(UpdateException::class);

        $this->update($file);
    }

    public function testNothingStartsWhileSomethingRuns(): void
    {
        $lock = Lock::tryAcquire($this->data . '/locks/backup.lock', 'backup of shop');
        self::assertNotNull($lock);

        try {
            $this->update($this->package('0.2.0'));
            self::fail('expected a refusal');
        } catch (UpdateException $e) {
            self::assertStringContainsString('backup of shop', $e->getMessage());
        } finally {
            $lock->release();
        }
        self::assertSame('0.1.0', $this->release());
        self::assertDirectoryDoesNotExist($this->home . '/releases/0.2.0');
    }

    public function testASwitchThatMeetsARunningBackupWaitsAndGoesOnWhenItIsOver(): void
    {
        $package = $this->package('0.2.0');
        $task = new UpdateRun(static fn (): HealthResult => HealthResult::ok(), static fn (): int => self::NOW);
        $state = $task->initialState(['file' => $package, 'sha256' => hash_file('sha256', $package), 'home' => $this->home, 'data' => $this->data]);
        while ($state['phase'] !== 'switch') {
            $state = $task->tick($state, new Budget(0.0))->state;
        }
        $lock = Lock::tryAcquire($this->data . '/locks/backup.lock', 'backup of shop');
        self::assertNotNull($lock);

        try {
            $task->tick($state, new Budget(0.0));
            self::fail('expected a refusal');
        } catch (UpdateException $e) {
            self::assertStringContainsString('The package is unpacked; continue when it has finished', $e->getMessage());
        }
        self::assertSame('0.1.0', $this->release());
        $lock->release();

        $final = $task->tick($state, new Budget(0.0));

        self::assertTrue($final->finished);
        self::assertSame('0.2.0', $this->release());
    }

    public function testTwoUpdatesAtOnceAreNotPossible(): void
    {
        $package = $this->package('0.2.0');
        $task = new UpdateRun(static fn (): HealthResult => HealthResult::ok(), static fn (): int => self::NOW);
        $state = $task->initialState(['file' => $package, 'sha256' => hash_file('sha256', $package), 'home' => $this->home, 'data' => $this->data]);
        while ($state['phase'] !== 'switch') {
            $state = $task->tick($state, new Budget(0.0))->state;
        }
        $other = Lock::tryAcquire($this->data . '/locks/update.lock', 'another update');
        self::assertNotNull($other);

        try {
            $task->tick($state, new Budget(0.0));
            self::fail('expected a refusal');
        } catch (UpdateException $e) {
            self::assertStringContainsString('Another update is working (pid', $e->getMessage());
        } finally {
            $other->release();
        }
        self::assertSame('0.1.0', $this->release());
    }

    public function testAnUpdateThatFailsWhileUnpackingLeavesNoHalfUnpackedDirectory(): void
    {
        $path = $this->tempDirectory() . '/bad.zip';
        $requirements = ReleaseManifest::requirements();
        $manifest = new ReleaseManifest('0.2.0', self::NOW, $requirements['php'], $requirements['extensions'], 1, ['vendor/autoload.php' => ['size' => 5, 'sha256' => hash('sha256', '<?php')]]);
        $writer = ZipWriter::create($path, 6);
        $writer->addString('threadsafe-0.2.0/release.json', $manifest->toJson(), self::NOW);
        $writer->addString('threadsafe-0.2.0/vendor/autoload.php', '<?php // changed', self::NOW);
        $writer->close();

        try {
            $this->update($path);
            self::fail('expected a refusal');
        } catch (UpdateException $e) {
            self::assertStringContainsString('does not match its hash', $e->getMessage());
        }
        self::assertSame('0.1.0', $this->release());
        self::assertDirectoryDoesNotExist($this->home . '/releases/0.2.0.partial');
        self::assertDirectoryDoesNotExist($this->home . '/releases/0.2.0');
    }

    public function testLeftoversOfAnEarlierTryAreRemovedAtTheStart(): void
    {
        mkdir($this->home . '/releases/0.2.0.partial/src', 0777, true);
        file_put_contents($this->home . '/releases/0.2.0.partial/src/old.php', '<?php // from before');
        mkdir($this->home . '/releases/0.2.0/vendor', 0777, true);
        file_put_contents($this->home . '/releases/0.2.0/vendor/autoload.php', '<?php // a release that was taken back');

        $this->update($this->package('0.2.0'));

        self::assertSame('0.2.0', $this->release());
        self::assertFileDoesNotExist($this->home . '/releases/0.2.0/vendor/../src/old.php');
        self::assertNotSame('<?php // a release that was taken back', file_get_contents($this->home . '/releases/0.2.0/vendor/autoload.php'));
    }

    public function testAHomeThatCannotBeWrittenIsAnotherKindOfRefusal(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root can write everywhere.');
        }
        chmod($this->home . '/releases', 0555);

        try {
            $this->update($this->package('0.2.0'));
            self::fail('expected a refusal');
        } catch (UpdateException $e) {
            self::assertStringContainsString('PHP cannot write in', $e->getMessage());
        } finally {
            chmod($this->home . '/releases', 0755);
        }
    }

    public function testTheNewVersionMigratesTheDataAtItsFirstStartNotBeforeTheSwitch(): void
    {
        $this->update($this->package('0.2.0'));

        self::assertSame(1, DataFormat::read($this->data)['dataFormat'] ?? null, 'the format is the one of the data until the new program has started');
    }
}
