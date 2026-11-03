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

        self::assertStringContainsString('<dl class="stats"><div class="stat"><dt>Sites</dt><dd>1</dd></div><div class="stat"><dt>Backups</dt><dd>1</dd></div><div class="stat"><dt>In storage</dt><dd>', $page->body, 'the figures as a strip');
        self::assertStringContainsString('<div class="stat"><dt>Needs a look</dt><dd>0</dd></div>', $page->body, 'a healthy site needs no look');
        self::assertStringContainsString('<article class="card ok">', $page->body);
        self::assertStringContainsString('<span class="badge ok">Healthy</span>', $page->body, 'the state is written, not only coloured');
        self::assertStringContainsString('href="/profiles/blog/backups/' . $name->base() . '"', $page->body);
        self::assertStringContainsString('Back up now', $page->body);
    }

    public function testTheCardIsShortAndItsTitleIsTextWithAPencilBesideIt(): void
    {
        $name = $this->backup();

        $page = $this->browser->get('/')->body;

        // the facts: date, time and state of the newest backup - no age, no size, no count
        self::assertMatchesRegularExpression('~<p>Last backup: <a href="/profiles/blog/backups/' . preg_quote($name->base(), '~') . '"><time datetime="[0-9TZ:-]+" data-minutes>\d{4}-\d\d-\d\d \d\d:\d\d</time></a> &middot; ok</p>~', $page);
        $card = substr($page, (int) strpos($page, '<article class="card '), (int) strpos($page, '</article>') - (int) strpos($page, '<article class="card '));
        self::assertDoesNotMatchRegularExpression('~ago|[0-9] (KB|MB|GB)|[0-9] backups?,~', $card, 'no "hours ago", no size, no number of backups');

        // the title is text, emphasised; the pencil beside it edits, has a tooltip and a name, and no label
        self::assertStringContainsString('<div class="card-title"><h2>blog</h2><a class="icon-button" href="/profiles/blog/edit" title="Edit profile" aria-label="Edit blog"><svg class="icon"', $card);
        self::assertStringNotContainsString('>Edit</a>', $card);
        self::assertStringNotContainsString('<h2><a', $card, 'the title is not a link: the button leads to the profile');

        // the buttons: the filled one backs up, the secondary one is the profile
        self::assertStringContainsString('<a class="button secondary" href="/profiles/blog">Profile</a>', $card);
        self::assertStringNotContainsString('>Backups</a>', $card);
    }

    public function testAnEmptyTileAfterTheCardsIsTheWayToANewProfile(): void
    {
        $page = $this->browser->get('/');

        self::assertMatchesRegularExpression('~<div class="cards"><article class="card .*</article><a class="card new" href="/new-profile"><svg class="icon"[^>]*>.*?</svg><span>New profile</span></a></div>~s', $page->body, 'the tile is the last card, in the same grid');
        self::assertStringNotContainsString('>New profile</a></p>', $page->body, 'the plain link below the cards is gone');
        self::assertSame(1, substr_count($page->body, 'href="/new-profile"'), 'one way, and it is the whole tile');
    }

    public function testTheDashboardLeadsToTheActivitySectionOfTheSystemPage(): void
    {
        $dashboard = $this->browser->get('/')->body;
        $activity = $this->browser->get('/system?tab=activity')->body;

        self::assertStringContainsString('<a class="button secondary" href="/system?tab=activity">See more</a>', $dashboard, 'a button, with a name that says what it does');
        self::assertStringNotContainsString('More on the System page', $dashboard);
        self::assertStringContainsString('<h2>Recent activity</h2>', $activity, 'and the section it leads to');
        self::assertStringContainsString('<a href="/system?tab=activity" aria-current="page">Activity</a>', $activity);
    }

    public function testTheSystemPageHasASectionForEachKindOfContentAndShowsOneAtATime(): void
    {
        $sections = ['general' => '/system', 'cron' => '/system?tab=cron', 'settings' => '/system?tab=settings', 'activity' => '/system?tab=activity'];
        $marks = ['general' => '<h2>Installation check</h2>', 'cron' => '<h3>The address</h3>', 'settings' => 'name="idle"', 'activity' => '<h2>Recent activity</h2>'];

        foreach ($sections as $id => $route) {
            $page = $this->browser->get($route)->body;

            self::assertStringContainsString('<nav class="tabs" aria-label="Sections of the System page">', $page, $id);
            self::assertSame(1, substr_count($page, 'aria-current="page">') - 1, "$id: the menu has its own mark, the tabs one");
            foreach (['General' => '/system', 'Cron' => '/system?tab=cron', 'Settings' => '/system?tab=settings', 'Activity' => '/system?tab=activity'] as $name => $href) {
                self::assertStringContainsString('<a href="' . $href . '"', $page, "$id has the link to $name");
            }
            foreach ($marks as $other => $mark) {
                self::assertSame($other === $id, str_contains($page, $mark), "$id shows $other's content only if it is its own");
            }
        }
        self::assertStringContainsString('<a href="/system?tab=settings" aria-current="page">Settings</a>', $this->browser->get('/system?tab=settings')->body);
        self::assertStringContainsString('<h2>Installation check</h2>', $this->browser->get('/system?tab=nonsense')->body, 'an unknown section is the general one');
    }

    public function testSavingAndMakingTheSecretLeadBackToTheirOwnSection(): void
    {
        self::assertSame('/system?tab=settings', $this->browser->post('/system/settings', ['idle' => '45', 'days' => '3', 'warn_mb' => '250', 'accel' => 'none', 'prefix' => '', 'audit_mb' => '2', 'pretty' => '1'])->header('Location'));
        self::assertSame('/system?tab=cron', $this->browser->post('/system/cron-secret', ['action' => 'make'])->header('Location'));
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
        self::assertStringContainsString('<a class="card new" href="/new-profile">', $page->body, 'the tile is there when there is nothing else');
        self::assertStringNotContainsString('class="filter"', $page->body, 'nothing to filter');
    }

    // ------------------------------------------------------------------------------------------------- system page

    public function testTheSystemPageChecksTheInstallationAndWritesTheProbeFile(): void
    {
        $page = $this->browser->get('/system');

        self::assertSame(200, $page->status);
        self::assertStringContainsString('Installation check', $page->body);
        self::assertStringContainsString('<table class="checks"><thead><tr><th scope="col">Result</th><th scope="col">Finding</th></tr></thead>', $page->body, 'the checks are a table, as in the installer');
        self::assertMatchesRegularExpression('#<tr class="(ok|info|warning|error)"><td class="level"><svg class="sym"#', $page->body, 'with a symbol for each level');
        self::assertStringNotContainsString('<ul class="checks">', $page->body);
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

    public function testAStorageBelowTheWebRootGetsAProbeFileAndTheBrowserIsAskedForIt(): void
    {
        $storage = $this->fixture->storageDirectory;
        is_dir($storage) || mkdir($storage, 0777, true);

        $page = $this->systemPageWithScriptIn(dirname($storage));

        self::assertSame(SystemChecks::PROBE, trim((string) file_get_contents($storage . '/probe.txt')), 'the backups of a profile are asked for like the data directory');
        self::assertStringContainsString('data-probe="/storage/probe.txt"', $page->body);
        self::assertStringContainsString('A storage directory of a profile lies below the web root', $page->body);
    }

    public function testAStorageOutsideTheWebRootGetsNoProbeFile(): void
    {
        $storage = $this->fixture->storageDirectory;
        is_dir($storage) || mkdir($storage, 0777, true);

        $page = $this->systemPageWithScriptIn($this->fixture->site);

        self::assertFileDoesNotExist($storage . '/probe.txt');
        self::assertStringNotContainsString('/storage/probe.txt', $page->body);
        self::assertStringNotContainsString('lies below the web root', $page->body);
    }

    private function systemPageWithScriptIn(string $directory): Response
    {
        $before = $_SERVER['SCRIPT_FILENAME'] ?? null;
        $_SERVER['SCRIPT_FILENAME'] = $directory . '/index.php';
        try {
            return $this->browser->get('/system');
        } finally {
            if ($before === null) {
                unset($_SERVER['SCRIPT_FILENAME']);
            } else {
                $_SERVER['SCRIPT_FILENAME'] = $before;
            }
        }
    }

    public function testLeftoversOfARestoreAndOfTheSetupAreReported(): void
    {
        file_put_contents($this->fixture->site . '/threadsafe-restore.php', '<?php');
        file_put_contents($this->fixture->site . '/threadsafe-restore-key.php', "<?php return 'x';");
        file_put_contents($this->fixture->data . '/setup-key.php', "<?php return 'y';");

        $page = $this->browser->get('/system')->body;

        self::assertStringContainsString('&quot;threadsafe-restore.php&quot; is in the site directory', $page);
        self::assertStringContainsString('&quot;threadsafe-restore-key.php&quot; is in the site directory', $page);
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
        $page = $this->browser->get('/system?tab=settings');
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
        self::assertStringContainsString('The settings were saved.', $this->browser->get('/system?tab=settings')->body);
        $audit = (new AuditLog($this->fixture->data . '/audit.log'))->recent(1)[0];
        self::assertSame('settings.changed', $audit['event']);
        self::assertStringContainsString('web.sessionIdleMinutes', $audit['changed']);

        // the same values again change nothing
        $this->browser->post('/system/settings', $fields);
        self::assertStringContainsString('Nothing was changed.', $this->browser->get('/system?tab=settings')->body);
        self::assertSame('settings.changed', (new AuditLog($this->fixture->data . '/audit.log'))->recent(1)[0]['event']);
    }

    public function testTheTriggerSettingsAreOnThePageAndChecked(): void
    {
        $page = $this->browser->get('/system?tab=settings');
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
        $general = $this->browser->get('/system');
        self::assertStringContainsString('<h2>Trigger</h2>', $general->body);
        self::assertStringContainsString('The trigger has not called yet.', $general->body);
        self::assertStringNotContainsString('threadsafe cron -q', $general->body, 'the command is on the cron section');

        $page = $this->browser->get('/system?tab=cron');
        self::assertStringContainsString('threadsafe cron -q', $page->body);
        self::assertMatchesRegularExpression('~<code id="cron-command">[^<]*threadsafe cron -q</code> <button type="button" class="secondary small" data-copy="#cron-command">Copy</button>~', $page->body, 'the line for the crontab can be copied');
        self::assertStringContainsString('There is no secret, so there is no address.', $page->body);
        self::assertStringContainsString('Make the secret', $page->body);

        self::assertSame(303, $this->browser->post('/system/cron-secret', ['action' => 'make'])->status);
        $secret = trim((string) file_get_contents($this->fixture->data . '/cron-secret'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $secret);
        $page = $this->browser->get('/system?tab=cron');
        self::assertStringContainsString('http://ts.example/cron/' . $secret, $page->body);
        self::assertStringContainsString('curl -fsS', $page->body);
        self::assertStringContainsString('<code id="cron-address">http://ts.example/cron/' . $secret . '</code> <button type="button" class="secondary small" data-copy="#cron-address">Copy</button>', $page->body);
        self::assertStringContainsString('data-copy="#cron-address-line"', $page->body, 'and the crontab line for the address');
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
        $this->fixture->profiles->save($this->fixture->profiles->load('blog')->with(['schedule' => ['every' => 'day', 'at' => '03:30']]));
        $now = $this->browser->now;

        $page = $this->browser->get('/');
        self::assertMatchesRegularExpression('~<p>Next backup: <time datetime="[^"]+" data-minutes>\d{4}-\d\d-\d\d \d\d:\d\d [A-Z+0-9-]+</time></p>~', $page->body);
        self::assertStringNotContainsString('(30 3 * * *)', $page->body, 'the expression is on the profile, not on the card');
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
        self::assertStringNotContainsString('Next backup', $page->body);
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
        self::assertStringContainsString('Download threadsafe-restore.php', $page->body);
        self::assertStringContainsString('Download threadsafe-restore-key.php with a new key', $page->body);
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
        self::assertSame('attachment; filename="threadsafe-restore-key.php"', $first->header('Content-Disposition'));
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
        self::assertSame('attachment; filename="threadsafe-restore.php"', $first->header('Content-Disposition'));
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
