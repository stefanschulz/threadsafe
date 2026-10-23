<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Admin\AuditLog;
use ThreadSafe\Admin\Config;
use ThreadSafe\Admin\RestoreKit;
use ThreadSafe\Admin\SystemChecks;
use ThreadSafe\Backup\BackupName;
use ThreadSafe\Schedule\Heartbeat;
use ThreadSafe\Tests\Support\AdminBrowser;
use ThreadSafe\Tests\Support\BackupFixture;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Web\Response;

/**
 * The System page with its settings, the dashboard cards, and the restore help with the tool and the key.
 */
final class SystemAndRestorePagesTest extends TestCase
{
    use TempDirectory;

    private BackupFixture $fixture;

    private AdminBrowser $browser;

    protected function setUp(): void
    {
        $this->fixture = new BackupFixture($this->tempDirectory());
        $this->browser = new AdminBrowser($this->fixture->data);
        $this->browser->now = time();
        $this->browser->install();
        $this->browser->login();
        $this->browser->get('/');
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    private function backup(): BackupName
    {
        return $this->fixture->backup();
    }

    private function body(Response $response): string
    {
        if ($response->stream === null) {
            return $response->body;
        }
        ob_start();
        try {
            ($response->stream)();
        } finally {
            $out = (string) ob_get_clean();
        }

        return $out;
    }

    // ------------------------------------------------------------------------------------------------- dashboard

    public function testTheDashboardShowsACardPerSiteWithItsNewestBackupAndTheTotals(): void
    {
        $name = $this->backup();

        $page = $this->browser->get('/');

        self::assertStringContainsString('<strong>1</strong> site &middot; <strong>1</strong> backup &middot;', $page->body);
        self::assertStringContainsString('<article class="card ok">', $page->body);
        self::assertStringContainsString('<span class="badge ok">OK</span>', $page->body, 'the state is written, not only coloured');
        self::assertStringContainsString('href="/profiles/blog/backups/' . $name->base() . '"', $page->body);
        self::assertMatchesRegularExpression('/Last backup: .*just now|Last backup: .*minute/s', $page->body);
        self::assertStringContainsString('Back up now', $page->body);
    }

    public function testACardWithAWarningNamesItAndNeverRelyOnColourAlone(): void
    {
        $page = $this->browser->get('/');

        self::assertStringContainsString('<article class="card warning">', $page->body);
        self::assertStringContainsString('<span class="badge warning">Needs a look</span>', $page->body);
        self::assertStringContainsString('<strong>Warning</strong> There is no backup yet.', $page->body);
    }

    public function testTheDashboardOfAnInstallationWithoutSitesLeadsToTheFirstProfile(): void
    {
        foreach (glob($this->fixture->data . '/profiles/*.json') ?: [] as $file) {
            unlink($file);
        }

        $page = $this->browser->get('/');

        self::assertStringContainsString('There are no sites yet', $page->body);
        self::assertStringContainsString('Create the first profile', $page->body);
    }

    // ------------------------------------------------------------------------------------------------- system page

    public function testTheSystemPageChecksTheInstallationAndWritesTheProbeFile(): void
    {
        $page = $this->browser->get('/system');

        self::assertSame(200, $page->status);
        self::assertStringContainsString('Installation check', $page->body);
        self::assertStringContainsString('Data directory', $page->body);
        self::assertStringContainsString('Profile blog', $page->body);
        self::assertStringContainsString('PHP command line', $page->body);
        self::assertFileExists($this->fixture->data . '/probe.txt');
        self::assertSame(SystemChecks::PROBE, trim((string) file_get_contents($this->fixture->data . '/probe.txt')));
        self::assertStringContainsString('data-probe="/data/probe.txt"', $page->body);
        self::assertStringContainsString('data-expect="' . SystemChecks::PROBE . '"', $page->body);
        self::assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)/i', $page->body);
        self::assertStringContainsString('title="System" aria-current="page"', $page->body);
    }

    public function testTheProbeLooksWhereDataWouldBeIfTheTreeLayBelowTheWebRoot(): void
    {
        $browser = new AdminBrowser($this->fixture->data, '/ts/public');
        $browser->now = time();
        $browser->login();

        $page = $browser->get('/system');

        self::assertStringContainsString('data-probe="/ts/data/probe.txt"', $page->body);
        self::assertStringContainsString('data-probe="/ts/public/data/probe.txt"', $page->body);
    }

    public function testLeftoversOfARestoreAndOfTheSetupAreReported(): void
    {
        file_put_contents($this->fixture->site . '/restore.php', '<?php');
        file_put_contents($this->fixture->site . '/restore-key.php', "<?php return 'x';");
        file_put_contents($this->fixture->data . '/setup-key.php', "<?php return 'y';");

        $page = $this->browser->get('/system')->body;

        self::assertStringContainsString('&quot;restore.php&quot; is in the site directory', $page);
        self::assertStringContainsString('&quot;restore-key.php&quot; is in the site directory', $page);
        self::assertStringContainsString('setup key file setup-key.php is still in the data directory', $page);
    }

    public function testASiteDirectoryThatIsGoneIsAFailureInTheCheck(): void
    {
        $profile = $this->fixture->profiles->load('blog');
        $this->fixture->profiles->save($profile->with(['path' => $this->fixture->site . '/gone']));

        self::assertStringContainsString('does not exist', $this->browser->get('/system')->body);
    }

    public function testTheSettingsAreShownChangedValidatedSavedAndAudited(): void
    {
        $page = $this->browser->get('/system');
        self::assertStringContainsString('name="idle" type="text" value="120"', $page->body);
        self::assertStringContainsString('name="warn_mb" type="text" value="500"', $page->body);

        $fields = ['idle' => '45', 'days' => '3', 'warn_mb' => '250.5', 'accel' => 'none', 'prefix' => '', 'audit_mb' => '2', 'pretty' => '1'];
        $saved = $this->browser->post('/system/settings', $fields);

        self::assertSame(303, $saved->status);
        $config = Config::load($this->fixture->data . '/config.json');
        self::assertTrue($config->enabled(), 'the switch of the interface is not touched');
        self::assertSame(2700, $config->sessionIdleSeconds());
        self::assertSame(3, $config->get('dashboard.warnAfterDays'));
        self::assertSame(262668288, $config->get('download.warnAbove'));
        self::assertSame(2097152, $config->get('audit.maxBytes'));
        self::assertStringContainsString('The settings were saved.', $this->browser->get('/system')->body);
        $audit = (new AuditLog($this->fixture->data . '/audit.log'))->recent(1)[0];
        self::assertSame('settings.changed', $audit['event']);
        self::assertStringContainsString('web.sessionIdleMinutes', $audit['changed']);

        // the same values again change nothing
        $this->browser->post('/system/settings', $fields);
        self::assertStringContainsString('Nothing was changed.', $this->browser->get('/system')->body);
        self::assertSame('settings.changed', (new AuditLog($this->fixture->data . '/audit.log'))->recent(1)[0]['event']);
    }

    public function testTheTriggerSettingsAreOnThePageAndChecked(): void
    {
        $page = $this->browser->get('/system');
        self::assertStringContainsString('name="grace" type="text" value="360"', $page->body);
        self::assertStringContainsString('name="interval" type="text" value="15"', $page->body);

        $fields = ['idle' => '120', 'days' => '7', 'warn_mb' => '500', 'accel' => 'none', 'prefix' => '', 'audit_mb' => '5', 'pretty' => '1', 'tz' => 'Europe/Berlin', 'grace' => '120', 'interval' => '1440', 'cron_mode' => 'ticks'];
        self::assertSame(303, $this->browser->post('/system/settings', $fields)->status);
        $config = Config::load($this->fixture->data . '/config.json');
        self::assertSame('Europe/Berlin', $config->scheduleZone()->getName());
        self::assertSame([120, 1440, 'ticks'], [$config->scheduleGraceMinutes(), $config->expectedIntervalMinutes(), $config->cronMode()]);

        $wrong = $this->browser->post('/system/settings', ['tz' => 'Mars/Olympus', 'grace' => '0', 'interval' => '2000', 'cron_mode' => 'daemon'] + $fields);
        self::assertSame(400, $wrong->status);
        self::assertStringContainsString('time zone', $wrong->body);
        self::assertSame('Europe/Berlin', Config::load($this->fixture->data . '/config.json')->scheduleZone()->getName(), 'nothing was saved');
    }

    public function testTheTriggerSectionShowsTheCommandAndTheSecretIsMadeReplacedAndRemoved(): void
    {
        $page = $this->browser->get('/system');
        self::assertStringContainsString('<h2>Trigger</h2>', $page->body);
        self::assertStringContainsString('The trigger has not called yet.', $page->body);
        self::assertStringContainsString('threadsafe cron -q', $page->body);
        self::assertStringContainsString('There is no secret, so there is no address.', $page->body);
        self::assertStringContainsString('Make the secret', $page->body);

        self::assertSame(303, $this->browser->post('/system/cron-secret', ['action' => 'make'])->status);
        $secret = trim((string) file_get_contents($this->fixture->data . '/cron-secret'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $secret);
        $page = $this->browser->get('/system');
        self::assertStringContainsString('http://ts.example/cron/' . $secret, $page->body);
        self::assertStringContainsString('curl -fsS', $page->body);
        self::assertStringContainsString('A new secret was made', $page->body);

        $this->browser->post('/system/cron-secret', ['action' => 'make']);
        self::assertNotSame($secret, trim((string) file_get_contents($this->fixture->data . '/cron-secret')));

        $this->browser->post('/system/cron-secret', ['action' => 'remove']);
        self::assertFileDoesNotExist($this->fixture->data . '/cron-secret');
        $audit = (string) file_get_contents($this->fixture->data . '/audit.log');
        self::assertStringContainsString('cron.secret.made', $audit);
        self::assertStringContainsString('cron.secret.removed', $audit);
        self::assertStringNotContainsString($secret, $audit);
    }

    public function testTheSecretNeedsThePostAndTheToken(): void
    {
        self::assertSame(405, $this->browser->get('/system/cron-secret')->status);
        self::assertSame(403, $this->browser->send('POST', '/system/cron-secret', ['action' => 'make'])->status);
        self::assertFileDoesNotExist($this->fixture->data . '/cron-secret');
    }

    public function testTheDashboardShowsTheNextRunAndWarnsWhenTheTriggerStaysAway(): void
    {
        $this->fixture->profiles->save($this->fixture->profiles->load('blog')->with(['schedule' => ['cron' => '30 3 * * *']]));
        $now = $this->browser->now;

        $page = $this->browser->get('/');
        self::assertStringContainsString('Next scheduled backup:', $page->body);
        self::assertStringContainsString('(30 3 * * *)', $page->body);
        self::assertStringContainsString('No trigger has called yet', $page->body);

        $heartbeat = new Heartbeat($this->fixture->data . '/heartbeat.json');
        $heartbeat->record('cli', 'nothing to do', $now - 5 * 60);
        self::assertStringNotContainsString('trigger', strtolower(strip_tags(explode('<div class="cards">', $this->browser->get('/')->body)[0])), 'a trigger that called five minutes ago is fine');

        $heartbeat->record('cli', 'nothing to do', $now - 3 * 3600);
        $late = $this->browser->get('/')->body;
        self::assertStringContainsString('The trigger last called 3 hours ago', $late);
        self::assertStringContainsString('every 15 minutes', $late);

        $config = Config::load($this->fixture->data . '/config.json')->with('schedule.expectedIntervalMinutes', 1440);
        $config->save($this->fixture->data . '/config.json');
        self::assertStringNotContainsString('The trigger last called', $this->browser->get('/')->body, 'for a trigger meant to come once a day, three hours is nothing');
    }

    public function testTheDashboardSaysNothingAboutTheTriggerWithoutAnySchedule(): void
    {
        $page = $this->browser->get('/');

        self::assertStringNotContainsString('No trigger has called yet', $page->body);
        self::assertStringNotContainsString('Next scheduled backup', $page->body);
    }

    public function testMistakesInTheSettingsComeBackWithTheFormAndNothingIsSaved(): void
    {
        $before = (string) file_get_contents($this->fixture->data . '/config.json');

        $response = $this->browser->post('/system/settings', ['idle' => '1', 'days' => 'x', 'warn_mb' => 'lots', 'accel' => 'magic', 'prefix' => 'no-slashes', 'audit_mb' => '-1', 'pretty' => '1']);

        self::assertSame(400, $response->status);
        foreach (['web.sessionIdleMinutes', 'dashboard.warnAfterDays', 'Warn above must be a number of megabytes', 'download.accel', 'download.accelPrefix', 'Audit log size must be a number of megabytes'] as $message) {
            self::assertStringContainsString($message, $response->body, $message);
        }
        self::assertStringContainsString('value="lots"', $response->body, 'what was typed is not lost');
        self::assertSame($before, (string) file_get_contents($this->fixture->data . '/config.json'));
    }

    public function testTheSettingsNeedTheTokenAndAPost(): void
    {
        self::assertSame(403, $this->browser->send('POST', '/system/settings', ['idle' => '30'])->status);
        self::assertSame(405, $this->browser->get('/system/settings')->status);
    }

    // ------------------------------------------------------------------------------------------------- restore help

    public function testTheRestoreHelpExplainsTheStepsAndListsThePartsToUpload(): void
    {
        $name = $this->fixture->backup(['partSize' => 1048576]);

        $page = $this->browser->get('/profiles/blog/backups/' . $name->base() . '/restore-help');

        self::assertSame(200, $page->status);
        self::assertStringContainsString('Restoring <strong>' . $name->base() . '</strong>', $page->body);
        self::assertStringContainsString('Download restore.php', $page->body);
        self::assertStringContainsString('Download restore-key.php with a new key', $page->body);
        self::assertStringContainsString('all 2 parts of the backup', $page->body);
        self::assertStringContainsString($name->base() . '-p02.zip', $page->body);
        self::assertStringContainsString('An <strong>empty directory</strong>', $page->body);
        self::assertStringNotContainsString('empty database', $page->body, 'this site has no database');
        self::assertStringContainsString('restore-help"', $this->browser->get('/profiles/blog')->body, 'linked from the list');
    }

    public function testTheKeyIsAPhpFileWithANewKeyEveryTimeAndIsNotStored(): void
    {
        $first = $this->browser->post('/restore-key', ['mode' => 'file']);
        $second = $this->browser->post('/restore-key', ['mode' => 'file']);

        self::assertSame(200, $first->status);
        self::assertSame('attachment; filename="restore-key.php"', $first->header('Content-Disposition'));
        self::assertSame('no-store', $first->header('Cache-Control'));
        self::assertMatchesRegularExpression("/^<\\?php return '[A-Za-z0-9]{24}';\n\$/", $first->body);
        self::assertNotSame($first->body, $second->body);
        $key = substr($first->body, strlen("<?php return '"), 24);
        self::assertStringNotContainsString($key, (string) file_get_contents($this->fixture->data . '/audit.log'), 'the key is not written anywhere');
        self::assertSame(['restore.key', 'restore.key'], array_column((new AuditLog($this->fixture->data . '/audit.log'))->recent(2), 'event'));
        self::assertSame($first->body, RestoreKit::keyFile($key));
    }

    public function testTheKeyCanBeShownOnThePageInstead(): void
    {
        $page = $this->browser->post('/restore-key', ['mode' => 'show']);

        self::assertSame(200, $page->status);
        self::assertStringContainsString('shown once', $page->body);
        self::assertMatchesRegularExpression('/<pre><code>&lt;\?php return &apos;[A-Za-z0-9]{24}&apos;;/', $page->body);
    }

    public function testTheKeyNeedsThePostAndTheToken(): void
    {
        self::assertSame(405, $this->browser->get('/restore-key')->status);
        self::assertSame(403, $this->browser->send('POST', '/restore-key', ['mode' => 'file'])->status);
    }

    public function testTheToolIsBuiltFromThisInstallationOnceAndSentWithRangeSupport(): void
    {
        $first = $this->browser->get('/restore-php');

        self::assertSame(200, $first->status);
        self::assertSame('attachment; filename="restore.php"', $first->header('Content-Disposition'));
        self::assertSame('application/octet-stream', $first->header('Content-Type'));
        $tool = $this->body($first);
        self::assertStringStartsWith("<?php\n// ThreadSafe restore tool", $tool);
        self::assertGreaterThan(100_000, strlen($tool));
        self::assertLessThan(500_000, strlen($tool));
        self::assertSame((string) strlen($tool), $first->header('Content-Length'));
        self::assertSame('"' . hash('sha256', $tool) . '"', $first->header('ETag'));

        $built = glob($this->fixture->data . '/cache/restore-*.php') ?: [];
        self::assertCount(1, $built);
        $time = filemtime($built[0]);
        $second = $this->browser->send('GET', '/restore-php', [], ['range' => 'bytes=0-9']);
        self::assertSame(206, $second->status);
        self::assertSame(substr($tool, 0, 10), $this->body($second));
        self::assertSame($time, filemtime($built[0]), 'the second request used the built file');
        self::assertSame('restore.tool', (new AuditLog($this->fixture->data . '/audit.log'))->recent(1)[0]['event']);
    }

    public function testNothingOfTheKitIsServedWithoutALogin(): void
    {
        $stranger = new AdminBrowser($this->fixture->data);
        $stranger->now = $this->browser->now;

        self::assertSame(303, $stranger->get('/restore-php')->status);
        self::assertSame(303, $stranger->get('/system')->status);
        self::assertSame(303, $stranger->get('/profiles/blog/backups/x/restore-help')->status);
    }

    public function testAnUnknownBackupHasNoRestoreHelp(): void
    {
        self::assertSame(404, $this->browser->get('/profiles/blog/backups/blog_20261021-120000_abcd1234/restore-help')->status);
    }
}
