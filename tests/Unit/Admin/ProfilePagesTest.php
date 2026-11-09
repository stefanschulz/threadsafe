<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Admin\AuditLog;
use ThreadSafe\Crypto\AgeKeys;
use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Profile\SiteProfile;
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
        self::assertStringContainsString('<a class="card new" href="/new-profile">', $page->body, 'the empty tile of the dashboard');
        self::assertStringContainsString('<div class="page-actions"><a class="button" href="/new-profile">New profile</a> <a class="button secondary" href="/new-remote-profile">New site with an agent</a></div>', $page->body, 'the buttons stand at the right of the title');
        self::assertStringContainsString('title="Profiles" aria-current="page"', $page->body, 'the navigation marks the page');
    }

    public function testEachProfileIsACardWithItsPathsAndOptions(): void
    {
        $this->create();
        $this->store()->save($this->store()->load('blog')->with([
            'schedule' => ['every' => 'day', 'at' => '03:30'],
            'retention' => ['keep' => 14, 'maxAgeDays' => 30],
            'encryption' => ['recipients' => [AgeKeys::generate()['public']]],
        ]));

        $page = $this->browser->get('/profiles')->body;

        self::assertStringContainsString('<article class="card profile"><header><div class="card-title"><h2>My Blog</h2>', $page);
        self::assertStringContainsString('<span class="badge">Other website</span>', $page, 'the title of the type, not its id');
        self::assertStringContainsString('<dt>Site</dt><dd><code class="path">' . str_replace('/', '/<wbr>', $this->base . '/my-site') . '</code></dd>', $page, 'a path may break after a slash');
        self::assertStringContainsString('<dt>Backups</dt><dd><code class="path">' . str_replace('/', '/<wbr>', $this->base . '/storage/blog') . '</code></dd>', $page);
        // the options have an icon in place of the label, with a tooltip and a name for a screen reader
        self::assertMatchesRegularExpression('~<dt title="Schedule"><svg class="icon"[^>]*>.*?</svg><span class="visually-hidden">Schedule</span></dt><dd>Every day at 03:30</dd>~', $page);
        self::assertMatchesRegularExpression('~<dt title="Retention"><svg.*?<span class="visually-hidden">Retention</span></dt><dd>keeps the last 14, up to 30 days</dd>~', $page);
        self::assertMatchesRegularExpression('~<dt title="Encryption"><svg.*?<span class="visually-hidden">Encryption</span></dt><dd>Encrypted</dd>~', $page);
        self::assertStringNotContainsString('<table', $page);
        self::assertStringNotContainsString('/profiles/blog/backup', $page, 'backing up is done from the dashboard and the page of the profile');
        self::assertStringNotContainsString('Last backup', $page, 'the state of the backups is on the dashboard');
    }

    public function testTheWayToTheProfileAndToTheFormAreTwoIconButtonsWithNames(): void
    {
        $this->create();

        $page = $this->browser->get('/profiles')->body;

        self::assertMatchesRegularExpression('~<a class="icon-button" href="/profiles/blog" title="View profile" aria-label="View profile My Blog"><svg class="icon"~', $page);
        self::assertMatchesRegularExpression('~<a class="icon-button" href="/profiles/blog/edit" title="Edit profile" aria-label="Edit profile My Blog"><svg class="icon"~', $page);
        self::assertLessThan(strpos($page, 'href="/profiles/blog/edit"'), strpos($page, 'href="/profiles/blog"'), 'the eye stands left of the pencil');
    }

    public function testALongNameIsCutAndTheTooltipHasTheWholeName(): void
    {
        $this->create();
        $long = trim(str_repeat('A long name ', 8));
        $this->store()->save($this->store()->load('blog')->with(['name' => $long]));

        $page = $this->browser->get('/profiles')->body;

        self::assertStringContainsString('<h2 title="' . $long . '">' . mb_strimwidth($long, 0, 80, '…') . '</h2>', $page);
        self::assertStringContainsString('…</h2>', $page);
    }

    public function testOptionsThatAProfileDoesNotHaveAreLeftOut(): void
    {
        $this->create();

        $page = $this->browser->get('/profiles')->body;

        self::assertStringContainsString('<dt>Site</dt>', $page);
        self::assertStringNotContainsString('class="fact option"', $page, 'no option, no icon');
    }

    public function testTheListCanBeFilteredByWebsiteType(): void
    {
        $this->create();

        $all = $this->browser->get('/profiles')->body;
        self::assertStringContainsString('<a href="/profiles" aria-current="true">All <span class="count">1</span></a>', $all);
        self::assertStringContainsString('<a href="/profiles?type=generic">Other website <span class="count">1</span></a>', $all);

        $chosen = $this->browser->get('/profiles?type=generic')->body;
        self::assertStringContainsString('<a href="/profiles?type=generic" aria-current="true">', $chosen);
        self::assertStringContainsString('<h2>My Blog</h2>', $chosen);

        $unknown = $this->browser->get('/profiles?type=nonsense')->body;
        self::assertStringContainsString('<h2>My Blog</h2>', $unknown, 'an unknown type shows everything');
    }
    public function testProfilesAreSortedByNameOnTheProfilesPageAndTheDashboard(): void
    {
        foreach ([['b', 'Zebra shop'], ['c', 'apple 10'], ['a', 'Apple 2'], ['d', 'Banana']] as [$id, $name]) {
            $this->store()->save(SiteProfile::fromArray(['id' => $id, 'name' => $name, 'type' => 'generic', 'path' => $this->base . '/my-site', 'storage' => $this->base . '/storage/' . $id]));
        }
        $names = static function (string $page): array {
            preg_match_all('~<article class="card [^"]*"><header><div class="card-title"><h2[^>]*>([^<]+)</h2>~', $page, $m);

            return $m[1];
        };
        $expected = ['Apple 2', 'apple 10', 'Banana', 'Zebra shop'];

        self::assertSame($expected, $names($this->browser->get('/profiles')->body), 'upper and lower case alike, numbers by their value');
        self::assertSame($expected, $names($this->browser->get('/')->body));
        self::assertSame(['Apple 2', 'apple 10'], array_slice($names($this->browser->get('/profiles?type=generic')->body), 0, 2), 'the filter keeps the order');
    }
    public function testTheProfilePageHasAHeadThatIsAlwaysThereAndOneMainAction(): void
    {
        $this->create();

        foreach (['/profiles/blog', '/profiles/blog?tab=retention', '/profiles/blog?tab=settings'] as $route) {
            $page = $this->browser->get($route)->body;

            self::assertStringContainsString('<div class="page-title"><h1>My Blog</h1><a class="icon-button" href="/profiles/blog/edit" title="Edit profile" aria-label="Edit profile My Blog"><svg class="icon"', $page, $route . ': the pencil stands beside the name');
            self::assertStringContainsString('<div class="page-actions"><form method="post" action="/profiles/blog/backup" class="inline">', $page, $route . ': the one main action');
            self::assertStringContainsString('<section class="card profile-summary ', $page, $route);
            self::assertStringContainsString('<span class="badge">Other website</span>', $page, $route);
            self::assertStringContainsString('<dt>Site</dt><dd><code class="path">' . str_replace('/', '/<wbr>', $this->base . '/my-site') . '</code></dd>', $page, $route);
            self::assertStringContainsString('<dt>Backups</dt>', $page, $route);
        }
    }

    public function testTheProfilePageShowsOneSectionAtATime(): void
    {
        $this->create();
        $marks = ['backups' => '<h2>Backups</h2>', 'retention' => '<h2>Retention</h2>', 'settings' => '<h2>Settings</h2>'];
        $routes = ['backups' => '/profiles/blog', 'retention' => '/profiles/blog?tab=retention', 'settings' => '/profiles/blog?tab=settings'];

        foreach ($routes as $id => $route) {
            $page = $this->browser->get($route)->body;

            self::assertStringContainsString('<nav class="tabs" aria-label="Sections of the profile">', $page, $id);
            foreach (['Backups' => '/profiles/blog', 'Retention' => '/profiles/blog?tab=retention', 'Settings' => '/profiles/blog?tab=settings'] as $name => $href) {
                self::assertStringContainsString('<a href="' . $href . '"', $page, "$id has the link to $name");
            }
            foreach ($marks as $other => $mark) {
                self::assertSame($other === $id, str_contains($page, $mark), "$id shows $other only if it is its own");
            }
        }
        self::assertStringContainsString('<a href="/profiles/blog?tab=settings" aria-current="page">Settings</a>', $this->browser->get('/profiles/blog?tab=settings')->body);
        self::assertStringContainsString('<h2>Backups</h2>', $this->browser->get('/profiles/blog?tab=nonsense')->body, 'an unknown section is the first one');
    }

    public function testTheOptionsOfABackupAreFoldedAndTheDangerousThingsAreInTheSettings(): void
    {
        $this->create();

        $backups = $this->browser->get('/profiles/blog')->body;
        self::assertStringContainsString('<details class="form-section start-options"><summary><h2>Back up with options</h2>', $backups);
        self::assertStringNotContainsString('Test the connection', $backups);
        self::assertStringNotContainsString('Remove the profile', $backups);

        $settings = $this->browser->get('/profiles/blog?tab=settings')->body;
        self::assertStringContainsString('Test the connection', $settings);
        self::assertStringContainsString('/profiles/blog/keys', $settings);
        self::assertStringContainsString('<a class="button danger" href="/profiles/blog/delete">Remove the profile</a>', $settings);
        self::assertLessThan(strpos($settings, 'Remove the profile'), strpos($settings, 'Test the connection'), 'the removal stands apart, below the rest');
        foreach (['Database', 'Filters', 'Archives', 'Encryption'] as $label) {
            self::assertStringContainsString("<dt>$label</dt>", $settings, $label);
        }
    }
    public function testTheFormHasTwoOpenCardsAndFoldedSectionsThatSayWhatTheyAreSetTo(): void
    {
        $page = $this->browser->get('/new-profile')->body;

        self::assertStringContainsString('<section class="form-section"><h2>Site</h2>', $page);
        self::assertStringContainsString('<h2>Storage</h2>', $page);
        foreach (['Database' => 'Read from the site', 'What to back up' => 'defaults of the type', 'Automation' => 'no schedule, keeps everything', 'Encryption' => 'off'] as $title => $value) {
            self::assertStringContainsString('<details class="form-section"><summary><h2>' . $title . '</h2><span class="summary-value">' . $value . '</span></summary>', $page, $title . ' is folded');
        }
        self::assertSame(4, substr_count($page, '<details class="form-section">'));
        self::assertSame(0, substr_count($page, '<details class="form-section" open>'));
        self::assertStringContainsString('<h3>Schedule</h3>', $page);
        self::assertStringContainsString('<h3>Retention</h3>', $page);
        self::assertStringContainsString('name="note"', $page, 'the note is part of the site card');
        self::assertLessThan(strpos($page, '<h2>Storage</h2>'), strpos($page, 'name="note"'));
        self::assertStringNotContainsString('<h2>Note</h2>', $page);
    }

    public function testTheFoldedSectionsOfAProfileShowItsValuesInTheLine(): void
    {
        $this->create();
        $this->store()->save($this->store()->load('blog')->with([
            'schedule' => ['every' => 'day', 'at' => '03:30'],
            'retention' => ['keep' => 14, 'maxAgeDays' => 30],
            'encryption' => ['recipients' => [AgeKeys::generate()['public'], AgeKeys::generate()['public']]],
            'excludePaths' => ['cache', '*.log'],
        ]));

        $page = $this->browser->get('/profiles/blog/edit')->body;

        self::assertStringContainsString('<span class="summary-value">every day at 03:30, keeps the last 14, up to 30 days</span>', $page);
        self::assertStringContainsString('<span class="summary-value">for 2 public keys</span>', $page);
        self::assertStringContainsString('<span class="summary-value">defaults of the type, 2 more left out</span>', $page);
        self::assertStringContainsString('<p>Id: <code>blog</code></p>', $page, 'the id is shown, not asked for');
    }

    public function testAnErrorOpensEverySectionSoThatNothingStaysFoldedThatMayHaveCausedIt(): void
    {
        $page = $this->browser->post('/profiles', $this->fields(['compression' => 'x']));

        self::assertSame(400, $page->status);
        self::assertSame(4, substr_count($page->body, '<details class="form-section" open>'));
        self::assertStringContainsString('class="flash error"', $page->body);
    }

    public function testTheButtonsStayInOneBarThatKeepsInView(): void
    {
        $page = $this->browser->get('/new-profile')->body;

        self::assertSame(1, substr_count($page, '<div class="form-bar">'));
        $bar = substr($page, (int) strpos($page, '<div class="form-bar">'));
        self::assertStringContainsString('name="action" value="save">Test and create</button>', $bar);
        self::assertStringContainsString('name="action" value="test" class="secondary">Only test</button>', $bar);
        self::assertStringContainsString('>Cancel</a>', $bar);
        self::assertStringContainsString('name="skip_test" value="1"> Save without testing', $bar);
        self::assertStringContainsString('name="action" value="detect"', $page, 'the look at the site is in the site card, not in the bar');
        self::assertStringNotContainsString('name="action" value="detect"', $bar);
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

        $page = $this->browser->get('/profiles/blog?tab=settings');

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

        $page = $this->browser->get('/profiles/blog?tab=settings');
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

    public function testAnEmptyStorageFieldMeansTheSuggestionAndTheFormSaysSo(): void
    {
        $form = $this->browser->get('/new-profile')->body;

        // the field is not required, shows the suggestion in grey, and the hint names the directory it is below
        self::assertMatchesRegularExpression('~<input id="f-storage" name="storage" type="text" value="" placeholder="' . preg_quote($this->base, '~') . '/backups/id" spellcheck="false">~', $form);
        self::assertStringContainsString('Leave it empty to use the suggestion in grey', $form);
        self::assertStringContainsString($this->base . '/backups. A directory in the home of ThreadSafe is removed with it.', $form);

        $response = $this->browser->post('/profiles', $this->fields(['storage' => '']));

        self::assertSame(303, $response->status, $response->body);
        self::assertSame($this->base . '/backups/blog', $this->store()->load('blog')->storage, 'the id of the profile is the last part');
    }

    public function testTheFormMarksItsRequiredFields(): void
    {
        $form = $this->browser->get('/new-profile')->body;

        self::assertStringContainsString('Fields marked <span class="req" aria-hidden="true">*</span>', $form);
        // the id, the site directory and (while the access is entered here) the name and the user of the database
        self::assertSame(4, substr_count($form, '<span class="req" aria-hidden="true">*</span><span class="visually-hidden"> (required)</span>'));
        foreach (['Id', 'Site directory', 'Database', 'User'] as $label) {
            self::assertMatchesRegularExpression('~<label for="f-[a-z_]+">' . $label . ' <span class="req"~', $form, $label);
        }
        self::assertStringNotContainsString('Storage directory <span class="req"', $form, 'the storage is optional');
        self::assertStringNotContainsString('Name <span class="req"', $form);
    }

    public function testTheDatabaseFieldsFollowTheChoiceOfTheAccess(): void
    {
        $form = $this->browser->get('/new-profile')->body;

        // seven fields are active only for "Enter it here"; the script switches them, and the two that must be filled in become required with it
        self::assertSame(7, substr_count($form, 'data-active-when="db_mode=manual"'));
        self::assertSame(2, substr_count($form, 'data-required-when-active="1"'));
        foreach (['db_host', 'db_port', 'db_name', 'db_user', 'db_password', 'db_prefix', 'db_driver'] as $name) {
            self::assertMatchesRegularExpression('~name="' . $name . '"[^>]*data-active-when="db_mode=manual"~', $form, $name);
        }
        self::assertStringNotContainsString('name="db_mode" disabled', $form);
    }

    public function testTheFiltersAreExplainedWithAndWithoutTheTick(): void
    {
        $form = $this->browser->get('/new-profile')->body;

        self::assertStringContainsString('With the tick these are left out; without it nothing is left out except what you list below', $form);
        self::assertStringContainsString('on top of the filters of the type when the box above is ticked, and on their own when it is not', $form);
        self::assertStringContainsString('on top of the type&apos;s own list when the box is ticked, on their own when it is not', $form);
        self::assertStringContainsString('<details class="help"><summary><span class="help-mark" aria-hidden="true">?</span> How the filters work</summary>', $form);
        self::assertStringContainsString('the type\'s paths <strong>and</strong> "Leave out also"', $form);
        self::assertStringContainsString('<td>not ticked</td><td>only "Leave out also"</td><td>only yours</td>', $form);
    }

    public function testThePublicKeysHaveAHelpThatSaysWhatToMake(): void
    {
        $new = $this->browser->get('/new-profile')->body;

        self::assertStringContainsString('<summary><span class="help-mark" aria-hidden="true">?</span> Which keys? How do I get them?</summary>', $new);
        self::assertStringContainsString('<code>age1...</code>', $new);
        self::assertStringContainsString('AGE-SECRET-KEY-1...', $new);
        self::assertStringContainsString('age-keygen', $new);
        self::assertStringContainsString('threadsafe keys generate', $new);
        self::assertStringContainsString('Save the profile without a key first', $new, 'a new profile has no page of keys yet');
        self::assertStringNotContainsString('href="/profiles/blog/keys"', $new);

        $this->create();
        $edit = $this->browser->get('/profiles/blog/edit')->body;

        self::assertStringContainsString('href="/profiles/blog/keys">Make a key pair</a> on the page <em>Keys</em>', $edit);
    }

    public function testAStorageThatWasTypedIsKept(): void
    {
        $this->create();

        self::assertSame($this->base . '/storage/blog', $this->store()->load('blog')->storage);
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
        self::assertStringContainsString('<tr class="error"><td class="level"><svg class="sym"', $rejected->body, 'a failed check is a row with the symbol of a problem');
        self::assertStringContainsString('<span>Problem</span>', $rejected->body);
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
            'schedule' => ['every' => 'day', 'at' => '03:30'], 'retention' => ['keep' => 7], 'encryption' => ['recipients' => [$key]],
        ]));

        $this->browser->post('/profiles/blog', $this->fields(['note' => 'changed in the browser', 'skip_test' => '1']));

        $profile = $this->store()->load('blog');
        self::assertSame('changed in the browser', $profile->note);
        self::assertSame('30 3 * * *', $profile->schedule?->cron());
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
        self::assertStringContainsString('stays where it is', $confirm->body);
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

    public function testTheQuestionOnlyMentionsTheBoxWhenThereIsOne(): void
    {
        // field test: "unless you tick the box" - and there was no box, because the profile had no backups
        $this->create();
        $page = $this->browser->get('/profiles/blog/delete')->body;
        self::assertStringNotContainsString('name="delete_backups"', $page, 'no backups, no box');
        self::assertStringNotContainsString('unless you tick', $page, 'it does not point at a box that is not there');
        self::assertStringContainsString('finds no backups of this profile in', $page);
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
        self::assertStringContainsString('<div class="stat"><dt>Sites</dt><dd>1</dd></div>', $dashboard->body);
        self::assertStringContainsString('href="/profiles"', $dashboard->body);
    }
}
