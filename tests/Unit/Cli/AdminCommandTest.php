<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Admin\AdminStore;
use ThreadSafe\Admin\AuditLog;
use ThreadSafe\Admin\Config;
use ThreadSafe\Cli\AdminCommand;
use ThreadSafe\Cli\Application;
use ThreadSafe\Cli\BufferedOutput;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * `threadsafe admin`: the administrator and the switch of the web interface.
 */
final class AdminCommandTest extends TestCase
{
    use TempDirectory;

    private string $data;

    protected function setUp(): void
    {
        $this->data = $this->tempDirectory() . '/data';
        putenv('THREADSAFE_ADMIN_PASSWORD');
    }

    protected function tearDown(): void
    {
        putenv('THREADSAFE_ADMIN_PASSWORD');
        $this->cleanUpTempDirectories();
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string, string}
     */
    private function cli(array $arguments): array
    {
        $output = new BufferedOutput();
        $code = (new Application([new AdminCommand($this->data)]))->run(['threadsafe', 'admin', ...$arguments], $output);

        return [$code, $output->stdout(), $output->stderr()];
    }

    private function passwordFile(string $password = "a long enough password\n"): string
    {
        $file = $this->tempDirectory() . '/password.txt';
        file_put_contents($file, $password);

        return $file;
    }

    public function testThePasswordComesFromAFileAndTheInterfaceStaysOff(): void
    {
        [$code, $out] = $this->cli(['password', '--password-file=' . $this->passwordFile()]);

        self::assertSame(0, $code);
        self::assertStringContainsString('is set', $out);
        self::assertStringContainsString('still switched off', $out);
        self::assertTrue((new AdminStore($this->data . '/admin.json'))->verify('admin', 'a long enough password'), 'the line break of the file is not part of it');
        self::assertFalse(Config::load($this->data . '/config.json')->enabled());
        self::assertStringContainsString('Require all denied', (string) file_get_contents($this->data . '/.htaccess'), 'the data directory is closed to the web');
    }

    public function testThePasswordCanComeFromTheEnvironment(): void
    {
        putenv('THREADSAFE_ADMIN_PASSWORD=from the environment');

        [$code] = $this->cli(['password']);

        self::assertSame(0, $code);
        self::assertTrue((new AdminStore($this->data . '/admin.json'))->verify('admin', 'from the environment'));
    }

    public function testWithoutAFileOrTheEnvironmentAndWithoutATerminalItSaysWhatToDo(): void
    {
        [$code, , $err] = $this->cli(['password']);

        self::assertSame(1, $code);
        self::assertStringContainsString('--password-file', $err);
        self::assertFileDoesNotExist($this->data . '/admin.json');
    }

    public function testAWeakPasswordIsRefusedAndNothingIsWritten(): void
    {
        [$code, , $err] = $this->cli(['password', '--password-file=' . $this->passwordFile('short')]);

        self::assertSame(2, $code);
        self::assertStringContainsString('at least', $err);
        self::assertFileDoesNotExist($this->data . '/admin.json');
    }

    public function testPasswordsAreNeverOptionValues(): void
    {
        [$code, , $err] = $this->cli(['password', '--password=secret secret']);

        self::assertSame(1, $code);
        self::assertStringContainsString('Unknown option --password', $err);
    }

    public function testTheInterfaceCannotBeSwitchedOnWithoutAnAdministrator(): void
    {
        [$code, , $err] = $this->cli(['enable']);

        self::assertSame(2, $code);
        self::assertStringContainsString('no administrator', $err);
        self::assertFalse(Config::load($this->data . '/config.json')->enabled());
    }

    public function testEnableAndDisableChangeOnlyTheSwitchAndAreRecorded(): void
    {
        $this->cli(['password', '--password-file=' . $this->passwordFile()]);
        Config::load($this->data . '/config.json')->with('web.sessionIdleMinutes', 30)->save($this->data . '/config.json');

        [$code, $out] = $this->cli(['enable']);
        self::assertSame(0, $code);
        self::assertStringContainsString('switched on', $out);
        $config = Config::load($this->data . '/config.json');
        self::assertTrue($config->enabled());
        self::assertSame(1800, $config->sessionIdleSeconds(), 'the other settings stay');

        $this->cli(['disable']);
        self::assertFalse(Config::load($this->data . '/config.json')->enabled());
        $events = array_column((new AuditLog($this->data . '/audit.log'))->recent(10), 'event');
        self::assertSame(['web.disabled', 'web.enabled', 'password.changed'], $events);
    }

    public function testStatusTellsWhatIsSetAndNeverShowsAHash(): void
    {
        $this->cli(['password', '--password-file=' . $this->passwordFile()]);
        file_put_contents($this->data . '/setup-key.php', "<?php return 'x';");

        [$code, $out] = $this->cli(['status']);

        self::assertSame(0, $code);
        self::assertStringContainsString('Web interface:  off', $out);
        self::assertStringContainsString('Administrator:  set', $out);
        self::assertStringContainsString('Setup key file: present', $out);
        self::assertStringNotContainsString('$argon2', $out);
        self::assertStringNotContainsString('$2y$', $out);

        [, $json] = $this->cli(['status', '--json']);
        $state = json_decode($json, true);
        self::assertIsArray($state);
        self::assertFalse($state['enabled']);
        self::assertTrue($state['administrator']);
        self::assertSame(1, preg_match('/^(argon2id|bcrypt)$/', $state['passwordAlgorithm']));
    }

    public function testUnknownActionsAndMissingActionsAreUsageErrors(): void
    {
        self::assertSame(1, $this->cli([])[0]);
        self::assertSame(1, $this->cli(['frobnicate'])[0]);
        self::assertSame(1, $this->cli(['status', 'extra'])[0]);
        self::assertSame(1, $this->cli(['set'])[0]);
        self::assertSame(1, $this->cli(['set', 'schedule.timezone'])[0]);
        self::assertSame(1, $this->cli(['set', 'schedule.timezone', 'UTC', 'extra'])[0]);
    }

    public function testSettingsAreChangedAndListedAndTheChangeIsAudited(): void
    {
        [$code, $out] = $this->cli(['set', 'schedule.timezone', 'Europe/Berlin']);
        self::assertSame(0, $code, $out);
        self::assertStringContainsString("schedule.timezone is now 'Europe/Berlin'", $out);
        [$code] = $this->cli(['set', 'cron.mode', 'ticks']);
        self::assertSame(0, $code);
        [$code] = $this->cli(['set', 'schedule.graceMinutes', '120']);
        self::assertSame(0, $code);

        $config = Config::load($this->data . '/config.json');
        self::assertSame('Europe/Berlin', $config->scheduleZone()->getName());
        self::assertSame('ticks', $config->cronMode());
        self::assertSame(120, $config->scheduleGraceMinutes());
        self::assertSame(15, $config->expectedIntervalMinutes(), 'what was not set has its default');

        [, $out] = $this->cli(['status']);
        self::assertStringContainsString('schedule.timezone', $out);
        self::assertStringContainsString('Europe/Berlin', $out);
        self::assertStringContainsString('cron.mode', $out);
        self::assertStringContainsString('settings.changed', (string) file_get_contents($this->data . '/audit.log'));

        [$code] = $this->cli(['set', 'schedule.timezone', '']);
        self::assertSame(0, $code, 'an empty value means the time zone of PHP');
        self::assertSame(date_default_timezone_get(), Config::load($this->data . '/config.json')->scheduleZone()->getName());
    }

    public function testTheSecretOfTheTriggerIsMadeShownReplacedAndRemoved(): void
    {
        [$code, $out] = $this->cli(['cron-secret']);

        self::assertSame(0, $code, $out);
        $secret = trim((string) file_get_contents($this->data . '/cron-secret'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $secret);
        self::assertStringContainsString('/cron/' . $secret, $out);
        self::assertStringContainsString('threadsafe cron -q', $out, 'the crontab line for the command');
        self::assertStringContainsString('curl -fsS', $out, 'and the one for the address');
        if (DIRECTORY_SEPARATOR === '/') {
            self::assertSame(0600, fileperms($this->data . '/cron-secret') & 0777);
        }
        $audit = (string) file_get_contents($this->data . '/audit.log');
        self::assertStringContainsString('cron.secret.made', $audit);
        self::assertStringNotContainsString($secret, $audit, 'the secret is in no log');

        [, $shown] = $this->cli(['cron-secret', '--show']);
        self::assertStringContainsString($secret, $shown);
        self::assertSame($secret, trim((string) file_get_contents($this->data . '/cron-secret')), '--show makes none');

        [, $again] = $this->cli(['cron-secret']);
        self::assertStringContainsString('an older one no longer works', $again);
        self::assertNotSame($secret, trim((string) file_get_contents($this->data . '/cron-secret')));

        [$code] = $this->cli(['cron-secret', '--remove']);
        self::assertSame(0, $code);
        self::assertFileDoesNotExist($this->data . '/cron-secret');
        self::assertSame(2, $this->cli(['cron-secret', '--show'])[0], 'nothing to show');
        self::assertSame(1, $this->cli(['cron-secret', '--show', '--remove'])[0]);
    }

    public function testASettingThatDoesNotExistOrIsOutOfRangeIsRefusedAndNothingIsWritten(): void
    {
        foreach ([['nonsense.key', '1'], ['schedule.timezone', 'Mars/Olympus'], ['cron.mode', 'sometimes'], ['schedule.graceMinutes', '0'], ['schedule.expectedIntervalMinutes', '2000']] as [$key, $value]) {
            [$code, , $err] = $this->cli(['set', $key, $value]);
            self::assertSame(2, $code, "$key $value: $err");
        }
        self::assertFileDoesNotExist($this->data . '/config.json');
    }
}
