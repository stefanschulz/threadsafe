<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Cli\Application;
use ThreadSafe\Cli\BufferedOutput;
use ThreadSafe\Cli\UpdateCommand;
use ThreadSafe\Core\Home;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Install\InstallRun;
use ThreadSafe\Tests\Support\ReleasePackage;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * `threadsafe update <package> --sha256=...` and `update --rollback`.
 */
final class UpdateCommandTest extends TestCase
{
    use TempDirectory;

    private string $base = '';

    private string $home = '';

    protected function setUp(): void
    {
        $this->base = $this->tempDirectory();
        mkdir($this->base . '/public_html');
        $this->home = $this->base . '/threadsafe';
        $task = new InstallRun();
        $state = $task->initialState(['file' => ReleasePackage::build($this->base, '0.1.0'), 'docroot' => $this->base . '/public_html', 'home' => $this->home]);
        do {
            $outcome = $task->tick($state, Budget::unlimited());
            $state = $outcome->state;
        } while (!$outcome->finished);
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string, string}
     */
    private function cli(array $arguments, ?string $home = null): array
    {
        $output = new BufferedOutput();
        $code = (new Application([new UpdateCommand($this->home . '/data', $home ?? $this->home)]))->run(['threadsafe', 'update', ...$arguments], $output);

        return [$code, $output->stdout(), $output->stderr()];
    }

    private function package(string $version): string
    {
        return ReleasePackage::build($this->base . '/p-' . $version, $version);
    }

    public function testItUpdatesAndSaysThatTheNewVersionWasNotStartedWithoutAnAddress(): void
    {
        $package = $this->package('0.2.0');

        [$code, $out] = $this->cli([$package, '--sha256=' . hash_file('sha256', $package)]);

        self::assertSame(0, $code);
        self::assertStringContainsString('Updated from 0.1.0 to 0.2.0', $out);
        self::assertStringContainsString('was not started to check it (give --url', $out);
        self::assertStringContainsString('kept for a roll-back', $out);
        self::assertSame(['release' => '0.2.0', 'previous' => '0.1.0'], Home::readPointer($this->home));
        self::assertStringContainsString('"event":"update.applied"', (string) file_get_contents($this->home . '/data/audit.log'));
    }

    public function testAWrongChecksumIsRefusedAndNothingChanges(): void
    {
        [$code, , $err] = $this->cli([$this->package('0.2.0'), '--sha256=' . str_repeat('a', 64)]);

        self::assertSame(2, $code);
        self::assertStringContainsString('The checksum of the file is', $err);
        self::assertStringContainsString('The installation is as it was', $err);
        self::assertSame('0.1.0', Home::readPointer($this->home)['release'] ?? null);
        self::assertSame([], glob($this->home . '/data/runs/update-*.json') ?: [], 'no run is left behind');
        self::assertStringContainsString('"event":"update.failed"', (string) file_get_contents($this->home . '/data/audit.log'));
    }

    public function testTheChecksumIsRequired(): void
    {
        [$code, , $err] = $this->cli([$this->package('0.2.0')]);

        self::assertSame(1, $code);
        self::assertStringContainsString('--sha256 is required', $err);
    }

    public function testThePackageIsRequired(): void
    {
        [$code, , $err] = $this->cli(['--sha256=' . str_repeat('a', 64)]);

        self::assertSame(1, $code);
        self::assertStringContainsString('Give the package', $err);
    }

    public function testAnAddressThatIsNoAddressIsAUsageError(): void
    {
        $package = $this->package('0.2.0');

        [$code, , $err] = $this->cli([$package, '--sha256=' . hash_file('sha256', $package), '--url=example.org']);

        self::assertSame(1, $code);
        self::assertStringContainsString('--url must be an address', $err);
    }

    public function testTheRollbackGoesBack(): void
    {
        $package = $this->package('0.2.0');
        $this->cli([$package, '--sha256=' . hash_file('sha256', $package)]);

        [$code, $out] = $this->cli(['--rollback']);

        self::assertSame(0, $code);
        self::assertStringContainsString('Version 0.1.0 is active again (was 0.2.0)', $out);
        self::assertSame('0.1.0', Home::readPointer($this->home)['release'] ?? null);
    }

    public function testThereIsNothingToRollBackToAfterAFreshInstallation(): void
    {
        [$code, , $err] = $this->cli(['--rollback']);

        self::assertSame(2, $code);
        self::assertStringContainsString('no previous release', $err);
    }

    public function testARollbackTakesNoPackage(): void
    {
        [$code, , $err] = $this->cli(['--rollback', 'x.zip']);

        self::assertSame(1, $code);
        self::assertStringContainsString('takes no package', $err);
    }

    public function testACloneHasNothingToUpdateFromAPackage(): void
    {
        $output = new BufferedOutput();
        $code = (new Application([new UpdateCommand($this->home . '/data')]))->run(['threadsafe', 'update', '--rollback'], $output);

        self::assertSame(2, $code);
        self::assertStringContainsString('installed from a clone', $output->stderr());
    }
}
