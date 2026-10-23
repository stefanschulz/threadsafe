<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Admin\AuditLog;
use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Tests\Support\AdminBrowser;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * The profile pages: list, create, edit, test, remove, with the website type "any website" on a real directory.
 */
final class ProfilePagesTest extends TestCase
{
    use TempDirectory;

    private string $base;

    private AdminBrowser $browser;

    protected function setUp(): void
    {
        $this->base = $this->tempDirectory();
        mkdir($this->base . '/my-site', 0777, true);
        file_put_contents($this->base . '/my-site/index.php', '<?php echo 1;');
        $this->browser = new AdminBrowser($this->base . '/data');
        $this->browser->install();
        $this->browser->login();
        $this->browser->get('/');
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    /**
     * The fields of a valid new profile, with changes.
     *
     * @param array<string, string> $changes
     *
     * @return array<string, string>
     */
    private function fields(array $changes = []): array
    {
        return $changes + ['action' => 'save', 'id' => 'blog', 'name' => 'My Blog', 'type' => 'generic', 'path' => $this->base . '/my-site', 'storage' => $this->base . '/storage/blog',
            'compression' => '6', 'part_size' => '', 'db_mode' => 'site', 'default_filters' => '1', 'exclude' => '', 'structure_only' => '', 'note' => ''];
    }

    private function store(): ProfileStore
    {
        return new ProfileStore($this->base . '/data/profiles');
    }

    private function create(): void
    {
        $response = $this->browser->post('/profiles', $this->fields());
        self::assertSame(303, $response->status, $response->body);
    }

    public function testTheListStartsEmptyAndOffersToCreateOne(): void
    {
        $page = $this->browser->get('/profiles');

        self::assertSame(200, $page->status);
        self::assertStringContainsString('There are no site profiles yet', $page->body);
        self::assertStringContainsString('href="/new-profile"', $page->body);
        self::assertStringContainsString('title="Profiles" aria-current="page"', $page->body, 'the navigation marks the page');
    }

    public function testTheFormOffersTheWebsiteTypesAndNeverAPasswordValue(): void
    {
        $page = $this->browser->get('/new-profile');

        self::assertSame(200, $page->status);
        self::assertStringContainsString('<option value="generic"', $page->body);
        self::assertStringContainsString('<option value="prestashop9"', $page->body);
        self::assertMatchesRegularExpression('/<input id="f-db_password" name="db_password" type="password"(?![^>]*value=)/', $page->body);
        self::assertStringContainsString('name="csrf"', $page->body);
        // found in the browser: the button must work before the required fields are filled in, or it does nothing at all
        self::assertMatchesRegularExpression('/<button type="submit" name="action" value="detect"[^>]*formnovalidate>/', $page->body);
    }

    public function testTheButtonsOfAProfileSitInOneRowOutsideAParagraphThatWouldSplitThem(): void
    {
        $this->create();

        $page = $this->browser->get('/profiles/blog');

        self::assertStringContainsString('<div class="actions">', $page->body);
        self::assertDoesNotMatchRegularExpression('/<p[^>]*>\s*<form/', $page->body, 'a form inside a paragraph is torn out of it by the HTML parser');
        self::assertStringContainsString('Test the connection', $page->body);
    }

    public function testLookingAtTheSiteReportsItAndProposesAnId(): void
    {
        $page = $this->browser->post('/profiles', $this->fields(['action' => 'detect', 'id' => '']));

        self::assertSame(200, $page->status);
        self::assertStringContainsString('found in', $page->body);
        self::assertStringContainsString('value="my-site"', $page->body, 'the id comes from the directory name');
        self::assertSame([], $this->store()->ids(), 'nothing is stored by looking');

        $nothing = $this->browser->post('/profiles', $this->fields(['action' => 'detect', 'path' => $this->base . '/nowhere']));
        self::assertSame(400, $nothing->status);
        self::assertStringContainsString('not a readable directory', $nothing->body);
    }

    public function testAProfileIsCreatedTestedAndShownWithAMessage(): void
    {
        $response = $this->browser->post('/profiles', $this->fields(['name' => 'My Blog', 'note' => 'live', 'exclude' => "cache\n*.log\n", 'compression' => '9', 'part_size' => '500M']));

        self::assertSame(303, $response->status);
        self::assertSame('/profiles/blog', $response->header('Location'));
        $profile = $this->store()->load('blog');
        self::assertSame('My Blog', $profile->name);
        self::assertSame(['cache', '*.log'], $profile->excludePaths);
        self::assertSame(9, $profile->compression);
        self::assertSame(500 * 1048576, $profile->partSize);
        self::assertNull($profile->database);

        $page = $this->browser->get('/profiles/blog');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('The profile &quot;My Blog&quot; was created.', $page->body);
        self::assertStringContainsString('parts of 500 MB', $page->body);
        self::assertStringNotContainsString('was created', $this->browser->get('/profiles/blog')->body, 'the message is shown once');

        $audit = (new AuditLog($this->base . '/data/audit.log'))->recent(1)[0];
        self::assertSame('profile.created', $audit['event']);
        self::assertSame('blog', $audit['profile']);
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function mistakes(): iterable
    {
        yield 'bad id' => [['id' => 'My Blog'], 'lower-case'];
        yield 'compression' => [['compression' => 'x'], 'Compression'];
        yield 'part size' => [['part_size' => '10K'], 'at least 1M'];
        yield 'relative storage' => [['storage' => 'storage/blog'], 'absolute'];
        yield 'no such type' => [['type' => 'nonsense'], 'nonsense'];
        yield 'manual database without a name' => [['db_mode' => 'manual', 'db_host' => 'localhost', 'db_port' => '3306', 'db_name' => '', 'db_user' => 'u', 'db_driver' => 'auto'], 'database.name'];
        yield 'bad port' => [['db_mode' => 'manual', 'db_port' => 'abc'], 'port'];
    }

    /**
     * @param array<string, string> $changes
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('mistakes')]
    public function testMistakesComeBackWithTheFormAndAMessage(array $changes, string $message): void
    {
        $response = $this->browser->post('/profiles', $this->fields($changes));

        self::assertSame(400, $response->status);
        self::assertStringContainsString($message, $response->body);
        self::assertStringContainsString('name="storage"', $response->body, 'the form is shown again');
        self::assertSame([], $this->store()->ids());
    }

    public function testATakenIdIsRefused(): void
    {
        $this->create();

        $response = $this->browser->post('/profiles', $this->fields());

        self::assertSame(400, $response->status);
        self::assertStringContainsString('exists already', $response->body);
    }

    public function testAProfileThatFailsItsTestIsNotStoredUnlessTheTestIsSkipped(): void
    {
        file_put_contents($this->base . '/a-file', 'x');
        $fields = $this->fields(['storage' => $this->base . '/a-file/storage']);

        $rejected = $this->browser->post('/profiles', $fields);
        self::assertSame(400, $rejected->status);
        self::assertStringContainsString('was not stored', $rejected->body);
        self::assertStringContainsString('Failed', $rejected->body);
        self::assertStringContainsString('cannot be used or created', $rejected->body);
        self::assertSame([], $this->store()->ids());

        $forced = $this->browser->post('/profiles', $fields + ['skip_test' => '1']);
        self::assertSame(303, $forced->status);
        self::assertSame(['blog'], $this->store()->ids());
    }

    public function testOnlyTestingShowsTheReportAndStoresNothing(): void
    {
        $response = $this->browser->post('/profiles', $this->fields(['action' => 'test']));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('The profile can be backed up.', $response->body);
        self::assertSame([], $this->store()->ids());
    }

    public function testEditingKeepsTheStoredPasswordWhenTheFieldIsEmptyAndNeverShowsIt(): void
    {
        $manual = ['db_mode' => 'manual', 'db_host' => 'db.example', 'db_port' => '3307', 'db_name' => 'shop', 'db_user' => 'shopuser', 'db_prefix' => 'ps_', 'db_driver' => 'pdo', 'skip_test' => '1'];
        $response = $this->browser->post('/profiles', $this->fields($manual) + ['db_password' => 'S3cret-Pass-word']);
        self::assertSame(303, $response->status, $response->body);
        self::assertSame('S3cret-Pass-word', $this->store()->load('blog')->database?->password);

        $edit = $this->browser->get('/profiles/blog/edit');
        self::assertSame(200, $edit->status);
        self::assertStringContainsString('value="db.example"', $edit->body);
        self::assertStringContainsString('value="3307"', $edit->body);
        self::assertStringNotContainsString('S3cret', $edit->body);
        self::assertStringNotContainsString('S3cret', $this->browser->get('/profiles/blog')->body);

        $this->browser->post('/profiles/blog', $this->fields($manual + ['note' => 'changed', 'name' => 'Renamed']));
        $changed = $this->store()->load('blog');
        self::assertSame('S3cret-Pass-word', $changed->database?->password, 'an empty password field keeps the password');
        self::assertSame('changed', $changed->note);
        self::assertSame('Renamed', $changed->name);

        $this->browser->post('/profiles/blog', $this->fields($manual) + ['db_password' => 'Another-Pass-word']);
        self::assertSame('Another-Pass-word', $this->store()->load('blog')->database?->password);

        $this->browser->post('/profiles/blog', $this->fields(['db_mode' => 'site', 'skip_test' => '1']));
        self::assertNull($this->store()->load('blog')->database, 'back to the database of the site');

        $log = (string) file_get_contents($this->base . '/data/audit.log');
        self::assertStringContainsString('profile.changed', $log);
        self::assertStringNotContainsString('Pass-word', $log);
        self::assertStringNotContainsString('S3cret', $log);
    }

    public function testEditingInTheBrowserKeepsScheduleRetentionAndEncryption(): void
    {
        $this->create();
        $key = 'age1ql3z7hjy54pw3hyww5ayyfg7zqgvc7w3j2elw8zmrj2kg5sfn9aqmcac8p';
        $this->store()->save($this->store()->load('blog')->with([
            'schedule' => ['cron' => '30 3 * * *'], 'retention' => ['keep' => 7], 'encryption' => ['recipients' => [$key]],
        ]));

        $this->browser->post('/profiles/blog', $this->fields(['note' => 'changed in the browser', 'skip_test' => '1']));

        $profile = $this->store()->load('blog');
        self::assertSame('changed in the browser', $profile->note);
        self::assertSame('30 3 * * *', $profile->schedule?->cron);
        self::assertSame(7, $profile->retention?->keep);
        self::assertSame([$key], $profile->encryption?->recipients);
    }

    public function testTheIdOfAStoredProfileCannotBeChangedByTheForm(): void
    {
        $this->create();

        $this->browser->post('/profiles/blog', $this->fields(['id' => 'other', 'note' => 'x']));

        self::assertSame(['blog'], $this->store()->ids());
        self::assertSame('x', $this->store()->load('blog')->note);
    }

    public function testTheTestPageShowsTheReportOfAStoredProfile(): void
    {
        $this->create();

        $page = $this->browser->post('/profiles/blog/test');

        self::assertSame(200, $page->status);
        self::assertStringContainsString('The profile can be backed up.', $page->body);
        self::assertStringContainsString('Storage', $page->body);
        self::assertSame('profile.tested', (new AuditLog($this->base . '/data/audit.log'))->recent(1)[0]['event']);
    }

    public function testRemovingNeedsAConfirmationAPostAndTheTokenAndKeepsTheBackups(): void
    {
        $this->create();
        mkdir($this->base . '/storage/blog', 0777, true);
        file_put_contents($this->base . '/storage/blog/blog_1.zip', 'archive');

        $confirm = $this->browser->get('/profiles/blog/delete');
        self::assertSame(200, $confirm->status);
        self::assertStringContainsString('stay where they are', $confirm->body);
        self::assertSame(['blog'], $this->store()->ids(), 'looking at the question removes nothing');

        $forged = $this->browser->send('POST', '/profiles/blog/delete');
        self::assertSame(403, $forged->status);
        self::assertSame(['blog'], $this->store()->ids());

        $done = $this->browser->post('/profiles/blog/delete');
        self::assertSame(303, $done->status);
        self::assertSame([], $this->store()->ids());
        self::assertFileExists($this->base . '/storage/blog/blog_1.zip');
        self::assertStringContainsString('was removed', $this->browser->get('/profiles')->body);
        self::assertSame('profile.removed', (new AuditLog($this->base . '/data/audit.log'))->recent(1)[0]['event']);
    }

    public function testUnknownProfilesAreNotFoundAndGetsDoNotChangeAnything(): void
    {
        $this->create();

        foreach (['/profiles/nope', '/profiles/nope/edit', '/profiles/nope/delete'] as $route) {
            self::assertSame(404, $this->browser->get($route)->status, $route);
        }
        self::assertSame(404, $this->browser->post('/profiles/nope/test')->status);
        self::assertSame(404, $this->browser->post('/profiles/nope/delete')->status);
        self::assertSame(404, $this->browser->get('/profiles/Bad..Id')->status);
        self::assertSame(405, $this->browser->get('/profiles/blog/test')->status, 'a link must not run the test');
        self::assertSame(['blog'], $this->store()->ids());
    }

    public function testANameFullOfMarkupIsEscapedEverywhere(): void
    {
        $hostile = '"><script>alert(1)</script>';
        $this->browser->post('/profiles', $this->fields(['name' => $hostile, 'note' => $hostile]));

        foreach (['/profiles', '/profiles/blog', '/profiles/blog/edit', '/profiles/blog/delete', '/'] as $route) {
            $body = $this->browser->get($route)->body;
            self::assertStringNotContainsString('<script>alert(1)', $body, $route);
            self::assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)/i', $body, $route);
        }
        self::assertStringContainsString('&lt;script&gt;', $this->browser->get('/profiles')->body);
    }

    public function testADamagedProfileFileIsListedWithItsReasonAndDoesNotBreakThePage(): void
    {
        $this->create();
        file_put_contents($this->base . '/data/profiles/broken.json', '{ nope');

        $page = $this->browser->get('/profiles');

        self::assertSame(200, $page->status);
        self::assertStringContainsString('broken', $page->body);
        self::assertStringContainsString('damaged', $page->body);
        self::assertStringContainsString('My Blog', $page->body);
        self::assertSame(404, $this->browser->get('/profiles/broken')->status);
    }

    public function testTheDashboardLinksToTheProfiles(): void
    {
        self::assertStringContainsString('href="/new-profile"', $this->browser->get('/')->body);

        $this->create();

        $dashboard = $this->browser->get('/');
        self::assertStringContainsString('<strong>1</strong> site &middot;', $dashboard->body);
        self::assertStringContainsString('href="/profiles"', $dashboard->body);
    }
}
