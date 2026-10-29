<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\WordPress;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Core\Context;
use ThreadSafe\Core\Exception\GuardFailed;
use ThreadSafe\Core\Exception\SelfTestFailed;
use ThreadSafe\Core\NullJournal;
use ThreadSafe\Core\NullLogger;
use ThreadSafe\Database\DatabaseSettings;
use ThreadSafe\Http\HttpClient;
use ThreadSafe\Migration\MigrationRequest;
use ThreadSafe\Replace\DomainMapping;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Tests\Support\WordPressSite;
use ThreadSafe\WordPress\Config\WordPressConfigFile;
use ThreadSafe\WordPress\HtaccessRewriter;
use ThreadSafe\WordPress\RequestBuilder;
use ThreadSafe\WordPress\Steps\EnsureConfiguration;
use ThreadSafe\WordPress\Steps\ReadWordPressState;
use ThreadSafe\WordPress\Steps\RewriteHtaccess;
use ThreadSafe\WordPress\Steps\SelfTest;
use ThreadSafe\WordPress\Steps\WriteConfiguration;

/**
 * The parts of the WordPress adaptation that need no database: the request, the guards of the first step, the making of `wp-config.php`,
 * the configuration, the `.htaccess` and the self-test (docs/PHASE9.md 3.5).
 */
final class WordPressMigrationTest extends TestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    private function root(bool $htaccess = false, ?string $configuration = null): string
    {
        $root = (string) realpath($this->tempDirectory());
        WordPressSite::build($root, $htaccess, $configuration);

        return $root;
    }

    // ------------------------------------------------------------------------------------------------- the request

    public function testTheRequestTakesTheDatabaseFromTheConfigurationAndLeavesTheGuidAlone(): void
    {
        $request = (new RequestBuilder())->build(['root' => $this->root(), 'from' => 'old.example', 'to' => 'new.example', 'database' => ['password' => 'other-password']]);

        self::assertEquals(new DatabaseSettings('db', 3306, 'test_db', 'test_user', 'other-password', 'wp_'), $request->database);
        self::assertSame(['posts.guid'], $request->skipColumns, 'the identifier of a post is not an address (decision 5)');
        self::assertSame('old.example', $request->domains[0]->from);
        self::assertNull($request->physicalUri);
        self::assertFalse($request->options['keepDropIns']);
        foreach (['woocommerce_sessions', 'actionscheduler_logs', 'wfhits', 'redirection_logs'] as $table) {
            self::assertContains($table, $request->skipTables, $table);
        }
        self::assertNotContains('posts', $request->skipTables);
        self::assertNotContains('options', $request->skipTables);
    }

    public function testTheGuidCanBeIncludedAndTheOptionsAreRead(): void
    {
        $request = (new RequestBuilder())->build(['root' => $this->root(), 'includeGuid' => true, 'keepDropIns' => true, 'skipColumns' => ['postmeta.meta_key']]);

        self::assertSame(['postmeta.meta_key'], $request->skipColumns, 'what the person names stays, the guid is not added');
        self::assertTrue($request->options['keepDropIns']);
    }

    public function testANewBasePathIsTheBasePathOfTheNewAddressInTheContent(): void
    {
        $request = (new RequestBuilder())->build(['root' => $this->root(), 'from' => 'old.example', 'to' => 'new.example', 'path' => 'sub']);

        self::assertSame('/sub/', $request->physicalUri, 'normalised to a path with a slash at both ends');
        self::assertSame('/sub', $request->domains[0]->toPath, 'and written behind the new host of every link');

        $root = (new RequestBuilder())->build(['root' => $this->root(), 'from' => 'old.example', 'to' => 'new.example', 'path' => '/']);
        self::assertSame('/', $root->physicalUri);
        self::assertSame('', $root->domains[0]->toPath);
    }

    public function testBadSettingsAreRefused(): void
    {
        $root = $this->root();
        foreach ([
            [['mode' => 'dev'], 'dev mode of WordPress is not built yet'],
            [['skipColumns' => ['nonsense']], 'skipColumns must be a list of "table.column"'],
            [['keepDropIns' => 'yes'], 'keepDropIns must be true or false'],
            [['nonsense' => 1], 'Unknown setting "nonsense"'],
        ] as [$settings, $message]) {
            try {
                (new RequestBuilder())->build(['root' => $root] + $settings);
                self::fail('this must be refused');
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    // ------------------------------------------------------------------------------------------------- the guards of the first step

    private function plan(string $root, ?string $path = null): void
    {
        $request = new MigrationRequest($root, [], null, $path);
        (new ReadWordPressState())->plan(new Context($request, new NullJournal(), new NullLogger(), true));
    }

    public function testTheFirstStepRefusesWhatIsNotAWordPressOfTheType(): void
    {
        $empty = $this->tempDirectory();
        $old = $this->root();
        file_put_contents($old . '/wp-includes/version.php', "<?php\n\$wp_version = '5.9.2';\n");
        $noConfig = $this->root(configuration: '');
        $multisite = $this->root(configuration: (string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/wordpress/wp-config-6.9.4-multisite.php'));
        $computed = $this->root(configuration: (string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/wordpress/wp-config-docker-image.php'));

        foreach ([
            [$empty, 'does not look like a WordPress installation'],
            [$old, 'Only WordPress 6.0 and later is supported, found version 5.9.2'],
            [$noConfig, 'has no wp-config.php'],
            [$multisite, 'multisite network, which ThreadSafe does not restore yet'],
            [$computed, '"DB_HOST" is computed by getenv_docker()'],
            [$this->tempDirectory() . '/missing', 'is not a directory'],
        ] as [$root, $message]) {
            try {
                $this->plan($root);
                self::fail("$root must be refused");
            } catch (GuardFailed $e) {
                self::assertStringContainsString($message, $e->getMessage());
                self::assertStringNotContainsString('test-password', $e->getMessage());
            }
        }
    }

    public function testANewBasePathIsRefusedForASiteThatDoesNotLiveAtTheRootOfItsDomain(): void
    {
        ReadWordPressState::checkBasePath(null, 'https://old.example/blog');
        ReadWordPressState::checkBasePath('/sub/', 'https://old.example');
        ReadWordPressState::checkBasePath('/sub/', 'https://old.example/');
        $this->expectException(GuardFailed::class);
        $this->expectExceptionMessage('The site lives in the directory "/blog/" of its domain');
        ReadWordPressState::checkBasePath('/sub/', 'https://old.example/blog');
    }

    // ------------------------------------------------------------------------------------------------- wp-config.php that the site did not have

    private function sampleRoot(): string
    {
        $root = $this->root(configuration: '');
        file_put_contents($root . '/wp-config-sample.php', "<?php\ndefine( 'DB_NAME', 'database_name_here' );\ndefine( 'DB_USER', 'username_here' );\ndefine( 'DB_PASSWORD', 'password_here' );\ndefine( 'DB_HOST', 'localhost' );\ndefine( 'DB_CHARSET', 'utf8mb4' );\ndefine( 'AUTH_KEY',         'put your unique phrase here' );\ndefine( 'SECURE_AUTH_KEY',  'put your unique phrase here' );\ndefine( 'LOGGED_IN_KEY',    'put your unique phrase here' );\ndefine( 'NONCE_KEY',        'put your unique phrase here' );\ndefine( 'AUTH_SALT',        'put your unique phrase here' );\ndefine( 'SECURE_AUTH_SALT', 'put your unique phrase here' );\ndefine( 'LOGGED_IN_SALT',   'put your unique phrase here' );\ndefine( 'NONCE_SALT',       'put your unique phrase here' );\n\$table_prefix = 'wp_';\nrequire_once ABSPATH . 'wp-settings.php';\n");

        return $root;
    }

    public function testAConfigurationThatLayAboveTheSiteIsMadeFromTheSampleWithNewKeys(): void
    {
        $root = $this->sampleRoot();
        $database = new DatabaseSettings('db.internal', 3307, 'new_db', 'new_user', 'new-password', 'wp2_');
        $context = new Context(new MigrationRequest($root, [], $database), new NullJournal(), new NullLogger(), false);

        $plan = (new EnsureConfiguration())->plan($context);
        $result = (new EnsureConfiguration())->apply($context, $plan);

        self::assertSame(1, $result->applied);
        $config = WordPressConfigFile::fromPath($root . '/wp-config.php');
        self::assertEquals($database, $config->database());
        self::assertSame('utf8mb4', $config->get('DB_CHARSET'), 'the rest of the sample stays');
        $salts = [];
        foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'] as $name) {
            $value = $config->get($name);
            self::assertIsString($value);
            self::assertSame(64, strlen($value), $name);
            self::assertNotSame('put your unique phrase here', $value, $name);
            $salts[$value] = true;
        }
        self::assertCount(8, $salts, 'each key and salt is its own');
        self::assertStringContainsString('has no wp-config.php', $plan->notices()[0]->message, 'the person is told that a new one was made, with new keys');
    }

    public function testASiteThatHasItsConfigurationIsLeftAloneAndOneThatCannotBeMadeIsSaid(): void
    {
        $root = $this->root();
        $before = file_get_contents($root . '/wp-config.php');
        $context = new Context(new MigrationRequest($root), new NullJournal(), new NullLogger(), false);
        self::assertSame(0, (new EnsureConfiguration())->apply($context, (new EnsureConfiguration())->plan($context))->applied);
        self::assertSame($before, file_get_contents($root . '/wp-config.php'));

        $noSample = $this->root(configuration: '');
        $noDatabase = $this->sampleRoot();
        foreach ([[$noSample, new MigrationRequest($noSample, [], new DatabaseSettings('db', 3306, 'n', 'u', 'p', 'wp_')), 'no wp-config-sample.php'], [$noDatabase, new MigrationRequest($noDatabase), 'give the database']] as [$directory, $request, $message]) {
            try {
                (new EnsureConfiguration())->plan(new Context($request, new NullJournal(), new NullLogger(), false));
                self::fail('this must be refused');
            } catch (GuardFailed $e) {
                self::assertStringContainsString($message, $e->getMessage(), $directory);
            }
        }
    }

    // ------------------------------------------------------------------------------------------------- wp-config.php that the site has

    public function testTheConfigurationFollowsTheDatabaseTheAddressAndThePaths(): void
    {
        $config = str_replace("define( 'DB_NAME'", "define( 'WP_HOME', 'http://old.example' );\ndefine( 'WP_SITEURL', 'http://old.example/' );\ndefine( 'DB_NAME'", (string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/wordpress/wp-config-6.9.4-plugin-added-constants.php'));
        $root = $this->root(configuration: $config);
        $mapping = (new DomainMapping('old.example', 'new.example', 'https'))->withPath('/sub');
        $database = new DatabaseSettings('db2', 3307, 'new_db', 'test_user', 'new-password', 'wp2_');
        $context = WordPressSite::context($root, [$mapping], $database, '/sub/', serverPath: $root);

        $plan = (new WriteConfiguration())->plan($context);
        $result = (new WriteConfiguration())->apply($context, $plan);

        $after = WordPressConfigFile::fromPath($root . '/wp-config.php');
        self::assertGreaterThan(0, $result->applied);
        self::assertEquals($database, $after->database());
        self::assertSame('db2:3307', $after->get('DB_HOST'));
        self::assertSame('https://new.example/sub', $after->get('WP_HOME'));
        self::assertSame('https://new.example/sub', $after->get('WP_SITEURL'));
        self::assertSame($root . '/wp-content/plugins/wp-super-cache/', $after->get('WPCACHEHOME'), 'the path a plugin wrote follows the directory');
        self::assertSame('utf8', $after->get('DB_CHARSET'));
        $password = array_values(array_filter($plan->changes(), static fn ($change): bool => $change->target === 'file wp-config.php DB_PASSWORD'));
        self::assertCount(1, $password);
        self::assertTrue($password[0]->secret, 'the password is never printed');
    }

    public function testNothingIsWrittenWhenTheConfigurationAlreadyFits(): void
    {
        $root = $this->root();
        $before = (string) file_get_contents($root . '/wp-config.php');
        $context = WordPressSite::context($root, serverPath: '/var/www/html');

        $result = (new WriteConfiguration())->apply($context, (new WriteConfiguration())->plan($context));

        self::assertSame(0, $result->applied);
        self::assertSame($before, file_get_contents($root . '/wp-config.php'));
    }

    public function testAnAddressThatIsNotPartOfTheMoveIsLeftAloneAndReported(): void
    {
        $request = new MigrationRequest('/x', [new DomainMapping('old.example', 'new.example')]);

        self::assertSame(['https://new.example/p', null], WriteConfiguration::moved('https://old.example/p', $request));
        [$address, $notice] = WriteConfiguration::moved('http://other.example', $request);
        self::assertSame('http://other.example', $address);
        self::assertStringContainsString('is not part of the move', (string) $notice);
        self::assertSame(['http://new.example', null], WriteConfiguration::moved('http://new.example', $request), 'already the new one');
        self::assertSame(['not an address', null], WriteConfiguration::moved('not an address', $request));
    }

    // ------------------------------------------------------------------------------------------------- .htaccess

    public function testTheBlockOfWordPressFollowsTheBasePath(): void
    {
        $result = (new HtaccessRewriter())->rewrite(WordPressSite::HTACCESS, '/sub/');

        self::assertSame(2, $result['changes']);
        self::assertSame([], $result['warnings']);
        self::assertStringContainsString("RewriteBase /sub/\n", $result['content']);
        self::assertStringContainsString("RewriteRule . /sub/index.php [L]\n", $result['content']);
        self::assertStringContainsString('RewriteRule ^index\.php$ - [L]', $result['content'], 'the rule for the real file is not a path');
        self::assertSame(str_replace(['RewriteBase /', 'RewriteRule . /index.php'], ['RewriteBase /sub/', 'RewriteRule . /sub/index.php'], WordPressSite::HTACCESS), $result['content']);
        // and back to the root, and the same base again changes nothing
        self::assertSame(WordPressSite::HTACCESS, (new HtaccessRewriter())->rewrite($result['content'], '/')['content']);
        self::assertSame(0, (new HtaccessRewriter())->rewrite(WordPressSite::HTACCESS, '/')['changes']);
    }

    public function testRulesOfOtherPluginsAroundTheBlockStayAndAreReportedWhenTheyNameTheOldPath(): void
    {
        $content = "# BEGIN WP Super Cache\nRewriteCond %{REQUEST_URI} !/old/wp-content/cache\n# END WP Super Cache\n\n" . str_replace('RewriteBase /', 'RewriteBase /old/', WordPressSite::HTACCESS) . "\n# custom\nHeader set X-Test 1\n";

        $result = (new HtaccessRewriter())->rewrite($content, '/new/');

        self::assertStringContainsString("RewriteCond %{REQUEST_URI} !/old/wp-content/cache\n", $result['content'], 'not ours');
        self::assertStringContainsString("RewriteBase /new/\n", $result['content']);
        self::assertStringEndsWith("# custom\nHeader set X-Test 1\n", $result['content']);
        self::assertStringContainsString('outside the block of WordPress name the old path /old/', $result['warnings'][0]);
    }

    public function testAFileWithoutTheBlockAndLineEndsOfWindows(): void
    {
        $none = (new HtaccessRewriter())->rewrite("Options -Indexes\n", '/sub/');
        self::assertSame(0, $none['changes']);
        self::assertStringContainsString('has no block "# BEGIN WordPress"', $none['warnings'][0]);
        self::assertSame([], (new HtaccessRewriter())->rewrite("Options -Indexes\n", '/')['warnings'], 'at the root nothing is needed');

        $crlf = (new HtaccessRewriter())->rewrite(str_replace("\n", "\r\n", WordPressSite::HTACCESS), '/sub/');
        self::assertSame(2, $crlf['changes']);
        self::assertSame(str_replace("\n", "\r\n", str_replace(['RewriteBase /', 'RewriteRule . /index.php'], ['RewriteBase /sub/', 'RewriteRule . /sub/index.php'], WordPressSite::HTACCESS)), $crlf['content']);
    }

    public function testTheStepWritesTheFileOnlyWhenABasePathIsAsked(): void
    {
        $root = $this->root(htaccess: true);
        $plain = WordPressSite::context($root);
        self::assertSame(0, (new RewriteHtaccess())->apply($plain, (new RewriteHtaccess())->plan($plain))->applied);
        self::assertSame(WordPressSite::HTACCESS, file_get_contents($root . '/.htaccess'));

        $moved = WordPressSite::context($root, path: '/sub/');
        self::assertSame(2, (new RewriteHtaccess())->apply($moved, (new RewriteHtaccess())->plan($moved))->applied);
        self::assertStringContainsString('RewriteBase /sub/', (string) file_get_contents($root . '/.htaccess'));
    }

    // ------------------------------------------------------------------------------------------------- the self-test

    private const string FRONT = '<html><head><link rel="canonical" href="http://new.example/"></head><body>Hello</body></html>';

    private const string LOGIN = '<form name="loginform" id="loginform"><input name="log" id="user_login"></form>';

    private const string REST = '{"name":"Site","url":"http:\/\/new.example","home":"http:\/\/new.example"}';

    /**
     * @param array<string, array{0: int, 1: string, 2?: array<string, string>}> $answers path (with the query) => status, body, headers
     * @param list<string>                                                      $asked
     */
    private static function client(array $answers, array &$asked): HttpClient
    {
        return new HttpClient(transport: static function (string $url, string $host) use ($answers, &$asked): array {
            $path = (string) preg_replace('~^https?://[^/]+~', '', $url);
            $asked[] = $host . ' ' . $path;
            $answer = $answers[$path] ?? [404, 'not found'];

            return [$answer[0], $answer[2] ?? ['content-type' => 'text/html'], $answer[1]];
        });
    }

    private function selfTestContext(?string $path = null): Context
    {
        return WordPressSite::context($this->root(), [(new DomainMapping('old.example', 'new.example'))->withPath($path === null ? '' : rtrim($path, '/'))], path: $path);
    }

    public function testTheSelfTestAsksForTheFrontPageTheLoginAndTheRestIndex(): void
    {
        $context = $this->selfTestContext();
        $asked = [];

        $result = (new SelfTest(self::client(['/' => [200, self::FRONT], '/wp-login.php' => [200, self::LOGIN], '/?rest_route=/' => [200, self::REST]], $asked)))->apply($context, (new SelfTest())->plan($context));

        self::assertSame(['new.example /', 'new.example /wp-login.php', 'new.example /?rest_route=/'], $asked);
        self::assertCount(3, $result->notices);
    }

    public function testTheSelfTestKnowsTheBasePath(): void
    {
        $context = $this->selfTestContext('/sub/');
        $asked = [];

        (new SelfTest(self::client(['/sub/' => [200, self::FRONT], '/sub/wp-login.php' => [200, self::LOGIN], '/sub/?rest_route=/' => [200, self::REST]], $asked)))->apply($context, (new SelfTest())->plan($context));

        self::assertSame(['new.example /sub/', 'new.example /sub/wp-login.php', 'new.example /sub/?rest_route=/'], $asked);
    }

    public function testASiteThatWasNotAdaptedAnswersWithARedirectToTheOldAddressAndFails(): void
    {
        $context = $this->selfTestContext();
        $asked = [];
        // what WordPress does with a copy on another address (docs/PHASE9.md section 8, finding 3)
        $redirect = [301, '', ['location' => 'http://old.example/']];
        $client = self::client(['/' => $redirect, '/wp-login.php' => [200, self::LOGIN], '/?rest_route=/' => [200, self::REST]], $asked);

        try {
            (new SelfTest($client))->apply($context, (new SelfTest())->plan($context));
            self::fail('a redirect to the old address must fail');
        } catch (SelfTestFailed $e) {
            self::assertStringContainsString('front page (/): redirect to another host', $e->getMessage());
        }
    }

    public function testALoginPageWithoutAFormAndARestIndexThatIsNoJsonFail(): void
    {
        $context = $this->selfTestContext();
        $asked = [];

        try {
            (new SelfTest(self::client(['/' => [200, self::FRONT], '/wp-login.php' => [200, '<html>maintenance</html>'], '/?rest_route=/' => [200, '<html>not json</html>']], $asked)))->apply($context, (new SelfTest())->plan($context));
            self::fail('this must fail');
        } catch (SelfTestFailed $e) {
            self::assertStringContainsString('the page has no login form', $e->getMessage());
            self::assertStringContainsString('the answer is not the REST index of WordPress', $e->getMessage());
        }
    }

    public function testALinkToTheOldDomainInTheAnswerFails(): void
    {
        $context = $this->selfTestContext();
        $asked = [];

        $this->expectException(SelfTestFailed::class);
        $this->expectExceptionMessage('1 link(s) in the answer still point to the old domain');
        (new SelfTest(self::client(['/' => [200, '<a href="http://old.example/x">x</a>'], '/wp-login.php' => [200, self::LOGIN], '/?rest_route=/' => [200, self::REST]], $asked)))->apply($context, (new SelfTest())->plan($context));
    }

    public function testWithoutANewDomainOrWhenSwitchedOffTheSelfTestAsksNothing(): void
    {
        $asked = [];
        $client = self::client([], $asked);
        $inPlace = WordPressSite::context($this->root());
        $off = WordPressSite::context($this->root(), [new DomainMapping('old.example', 'new.example')], selfTest: false);

        self::assertSame(0, (new SelfTest($client))->apply($inPlace, (new SelfTest())->plan($inPlace))->applied);
        self::assertSame(0, (new SelfTest($client))->apply($off, (new SelfTest())->plan($off))->applied);
        self::assertSame([], $asked);
    }
}
