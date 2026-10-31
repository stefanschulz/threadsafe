<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Admin\Context;
use ThreadSafe\Admin\Services;
use ThreadSafe\Admin\UpdateController;
use ThreadSafe\Admin\Urls;
use ThreadSafe\Core\Home;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Install\InstallRun;
use ThreadSafe\Tests\Support\ReleasePackage;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Update\HealthResult;
use ThreadSafe\Web\Request;
use ThreadSafe\Web\Response;

/**
 * The Update section of the System page: what it shows, the upload with the checksum, the update in steps, the result, the roll-back.
 */
final class UpdateControllerTest extends TestCase
{
    use TempDirectory;

    private string $base = '';

    private string $home = '';

    private string $data = '';

    /** @var (\Closure(string, string, string): HealthResult) */
    private \Closure $health;

    protected function setUp(): void
    {
        $this->base = $this->tempDirectory();
        mkdir($this->base . '/public_html');
        $this->home = $this->base . '/threadsafe';
        $this->data = $this->home . '/data';
        $package = ReleasePackage::build($this->base, '0.1.0');
        $task = new InstallRun();
        $state = $task->initialState(['file' => $package, 'docroot' => $this->base . '/public_html', 'home' => $this->home]);
        do {
            $outcome = $task->tick($state, Budget::unlimited());
            $state = $outcome->state;
        } while (!$outcome->finished);
        $this->health = static fn (): HealthResult => HealthResult::ok('The new version started and answered as version 0.2.0.');
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    private function controller(?string $home = null): UpdateController
    {
        $mover = static fn (string $from, string $to): bool => copy($from, $to);

        return new UpdateController(new Services($this->data, null, static fn (): Budget => new Budget(0.0)), $home ?? $this->home, $mover, $this->health);
    }

    /**
     * @param array<string, string>                                                           $post
     * @param array<string, array{name: string, tmp_name: string, size: int, error: int}> $files
     */
    private function context(array $post = [], array $files = [], string $method = 'POST'): Context
    {
        $request = new Request($method, [], $post + ['_csrf' => 'token'], [], false, '127.0.0.1', '/system/update', ['host' => 'example.org', 'x-csrf-token' => 'token'], $files);

        return new Context($request, new Urls('', true), ['csrf' => 'token', 'user' => 'admin']);
    }

    private function upload(string $package, ?string $sha = null, string $name = 'threadsafe-0.2.0.zip'): Response
    {
        $file = ['name' => $name, 'tmp_name' => $package, 'size' => (int) filesize($package), 'error' => UPLOAD_ERR_OK];

        return $this->controller()->upload($this->context(['sha256' => $sha ?? (string) hash_file('sha256', $package)], ['package' => $file]));
    }

    /** @return array<string, mixed> the answer of the last tick */
    private function tickToTheEnd(UpdateController $controller): array
    {
        $answer = ['status' => 'running'];
        for ($i = 0; $i < 100 && $answer['status'] === 'running'; ++$i) {
            $answer = json_decode($controller->tick($this->context())->body, true);
            self::assertIsArray($answer);
        }

        return $answer;
    }

    public function testACloneIsToldHowItIsUpdated(): void
    {
        $html = (new UpdateController(new Services($this->data), null))->section($this->context([], [], 'GET'));

        // the tests run in a clone, which has no home
        self::assertStringContainsString('installed from a clone', $html);
        self::assertStringContainsString('git pull', $html);
        self::assertStringNotContainsString('<form', $html);
    }

    public function testAnInstallationShowsItsVersionAndTheUploadForm(): void
    {
        $html = $this->controller()->section($this->context([], [], 'GET'));

        self::assertStringContainsString('Installed: version <strong>0.1.0</strong>', $html);
        self::assertStringContainsString('enctype="multipart/form-data"', $html);
        self::assertStringContainsString('name="package"', $html);
        self::assertStringContainsString('name="sha256"', $html);
        self::assertStringContainsString('makes no request to find a new version', $html);
        self::assertStringNotContainsString('Go back', $html, 'there is no previous release yet');
    }

    public function testTheUploadStartsARunThatTheTicksCarryToTheEnd(): void
    {
        $response = $this->upload($this->package('0.2.0'));

        self::assertSame(303, $response->status);
        self::assertStringEndsWith('/system?tab=update', (string) $response->header('Location'));
        $controller = $this->controller();
        $page = $controller->section($this->context([], [], 'GET'));
        self::assertStringContainsString('data-tick-url="/system/update/tick"', $page);
        self::assertStringContainsString('Updating to version', $page);

        $last = $this->tickToTheEnd($controller);

        self::assertSame('finished', $last['status']);
        self::assertSame(100, $last['percent']);
        self::assertSame(['release' => '0.2.0', 'previous' => '0.1.0'], Home::readPointer($this->home));
        $page = $controller->section($this->context([], [], 'GET'));
        self::assertStringContainsString('Updated from <strong>0.1.0</strong> to <strong>0.2.0</strong>', $page);
        self::assertStringContainsString('answered as version 0.2.0', $page);
        $log = (string) file_get_contents($this->data . '/audit.log');
        self::assertStringContainsString('update.started', $log);
        self::assertStringContainsString('"event":"update.applied"', $log);
    }

    public function testAPackageThatDoesNotMatchTheChecksumFailsAtTheFirstStepAndCanBeGivenUp(): void
    {
        $this->upload($this->package('0.2.0'), str_repeat('b', 64));
        $controller = $this->controller();

        $answer = $this->tickToTheEnd($controller);

        self::assertSame('failed', $answer['status']);
        self::assertStringContainsString('The checksum of the file is', (string) $answer['error']);
        $page = $controller->section($this->context([], [], 'GET'));
        self::assertStringContainsString('The update did not go on', $page);
        self::assertStringContainsString('The installation is as it was', $page);
        self::assertSame('0.1.0', Home::readPointer($this->home)['release'] ?? null);

        $controller->discard($this->context());

        self::assertStringContainsString('enctype="multipart/form-data"', $controller->section($this->context([], [], 'GET')), 'the form is back');
        self::assertSame([], glob($this->data . '/updates/upload-*.zip') ?: [], 'the uploaded file is removed with the run');
    }

    public function testAnUpdateThatWasTakenBackSaysSo(): void
    {
        $this->health = static fn (): HealthResult => HealthResult::failed('The new version answered with HTTP 500 and not as expected; it did not start properly.');
        $this->upload($this->package('0.2.0'));
        $controller = $this->controller();

        $answer = $this->tickToTheEnd($controller);

        self::assertSame('finished', $answer['status']);
        self::assertSame('0.1.0', Home::readPointer($this->home)['release'] ?? null);
        $page = $controller->section($this->context([], [], 'GET'));
        self::assertStringContainsString('did not start, so it was taken back', $page);
        self::assertStringContainsString('HTTP 500', $page);
        self::assertStringContainsString('<section class="failure" role="alert">', $page, 'one card');
        self::assertStringContainsString('What the check reported', $page);
        self::assertStringContainsString('<details class="failure-help"><summary>What you can do</summary>', $page, 'the way on opens on request');
        self::assertStringNotContainsString('<details class="failure-help" open', $page);
        self::assertStringContainsString('error log', $page);
        self::assertStringContainsString('"event":"update.rolled_back"', (string) file_get_contents($this->data . '/audit.log'));
    }

    public function testAHostThatCouldNotCheckGetsTheWayBack(): void
    {
        $this->health = static fn (): HealthResult => HealthResult::skipped('allow_url_fopen is off');
        $this->upload($this->package('0.2.0'));
        $controller = $this->controller();
        $this->tickToTheEnd($controller);

        $page = $controller->section($this->context([], [], 'GET'));

        self::assertStringContainsString('could not be started to check it', $page);
        self::assertStringContainsString('update --rollback', $page);
    }

    public function testTheTickBoxToSkipTheCheckIsOfferedAndCarriedIntoTheRun(): void
    {
        $html = $this->controller()->section($this->context([], [], 'GET'));
        self::assertStringContainsString('name="skipcheck"', $html);
        self::assertStringContainsString('Do not start the new version to check it', $html);

        $asked = false;
        $this->health = static function () use (&$asked): HealthResult {
            $asked = true;

            return HealthResult::failed('no answer');
        };
        $package = $this->package('0.2.0');
        $file = ['name' => 'threadsafe-0.2.0.zip', 'tmp_name' => $package, 'size' => (int) filesize($package), 'error' => UPLOAD_ERR_OK];
        $this->controller()->upload($this->context(['sha256' => (string) hash_file('sha256', $package), 'skipcheck' => '1'], ['package' => $file]));
        $controller = $this->controller();
        $this->tickToTheEnd($controller);

        self::assertFalse($asked, 'the new version was not asked');
        self::assertSame('0.2.0', Home::readPointer($this->home)['release'] ?? null);
        self::assertStringContainsString('update --rollback', $controller->section($this->context([], [], 'GET')));
    }

    public function testTheRollbackButtonGoesBackAndTheDataStays(): void
    {
        $this->upload($this->package('0.2.0'));
        $controller = $this->controller();
        $this->tickToTheEnd($controller);
        $controller->discard($this->context());
        self::assertStringContainsString('Go back to 0.1.0', $controller->section($this->context([], [], 'GET')));

        $response = $controller->rollback($this->context());

        self::assertSame(303, $response->status);
        self::assertSame('0.1.0', Home::readPointer($this->home)['release'] ?? null);
        self::assertStringContainsString('"by":"hand"', (string) file_get_contents($this->data . '/audit.log'));
    }

    public function testTheRollbackButtonWarnsThatAnOlderVersionKnowsNothingOfTheSecondFactor(): void
    {
        $this->upload($this->package('0.2.0'));
        $controller = $this->controller();
        $this->tickToTheEnd($controller);
        $controller->discard($this->context());
        self::assertStringNotContainsString('two-factor.json', $controller->section($this->context([], [], 'GET')));

        (new \ThreadSafe\Auth\TwoFactorStore($this->data . '/two-factor.json'))->enable('admin', 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', []);

        $section = $controller->section($this->context([], [], 'GET'));
        self::assertStringContainsString('The second factor is on.', $section);
        self::assertStringContainsString('the login asks for the password only', $section);
    }

    public function testNoFileAndAFileThatWasTooLargeAreMessagesNotRuns(): void
    {
        $none = $this->controller()->upload($this->context());
        $large = $this->controller()->upload($this->context([], ['package' => ['name' => 'x.zip', 'tmp_name' => '', 'size' => 0, 'error' => UPLOAD_ERR_INI_SIZE]]));

        self::assertSame(303, $none->status);
        self::assertSame(303, $large->status);
        self::assertSame([], glob($this->data . '/runs/update-*.json') ?: []);
    }

    public function testAPackageThatLiesInTheUpdatesDirectoryCanBeChosen(): void
    {
        mkdir($this->data . '/updates', 0700, true);
        copy($this->package('0.2.0'), $this->data . '/updates/threadsafe-0.2.0.zip');
        $controller = $this->controller();
        self::assertStringContainsString('<option value="threadsafe-0.2.0.zip"', $controller->section($this->context([], [], 'GET')));

        $response = $controller->start($this->context(['file' => 'threadsafe-0.2.0.zip', 'sha256' => (string) hash_file('sha256', $this->data . '/updates/threadsafe-0.2.0.zip')]));
        $answer = $this->tickToTheEnd($controller);

        self::assertSame(303, $response->status);
        self::assertSame('finished', $answer['status']);
        self::assertSame('0.2.0', Home::readPointer($this->home)['release'] ?? null);
    }

    public function testOnlyAFileOfTheUpdatesDirectoryCanBeChosen(): void
    {
        mkdir($this->data . '/updates', 0700, true);
        $outside = $this->package('0.2.0');

        $controller = $this->controller();
        $controller->start($this->context(['file' => '../../' . basename($outside), 'sha256' => str_repeat('a', 64)]));
        $controller->start($this->context(['file' => '/etc/passwd', 'sha256' => str_repeat('a', 64)]));
        $controller->start($this->context(['file' => 'missing.zip', 'sha256' => str_repeat('a', 64)]));

        self::assertSame([], glob($this->data . '/runs/update-*.json') ?: [], 'no run was made');
    }

    public function testASecondUpdateCannotBeStartedWhileOneIsOpen(): void
    {
        $this->upload($this->package('0.2.0'));

        $this->upload($this->package('0.3.0', 'second'));

        self::assertCount(1, glob($this->data . '/runs/update-*.json') ?: []);
    }

    public function testAHomeThatIsNotWritableIsSaidAndHasNoForm(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root can write everywhere.');
        }
        chmod($this->home . '/releases', 0555);

        try {
            $html = $this->controller()->section($this->context([], [], 'GET'));
        } finally {
            chmod($this->home . '/releases', 0755);
        }

        self::assertStringContainsString('PHP cannot write in', $html);
        self::assertStringNotContainsString('multipart', $html);
    }

    private function package(string $version, string $directory = 'packages'): string
    {
        return ReleasePackage::build($this->base . '/' . $directory . '-' . $version, $version);
    }
}
