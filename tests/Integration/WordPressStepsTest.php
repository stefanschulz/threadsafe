<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use ThreadSafe\Core\Exception\GuardFailed;
use ThreadSafe\Core\NullJournal;
use ThreadSafe\Core\NullLogger;
use ThreadSafe\Core\Context;
use ThreadSafe\Generic\Steps\ReplaceContentDomains;
use ThreadSafe\Migration\MigrationRequest;
use ThreadSafe\Replace\DomainMapping;
use ThreadSafe\Tests\Support\ScratchWordPress;
use ThreadSafe\WordPress\Steps\DiscardCaches;
use ThreadSafe\WordPress\Steps\FixTablePrefix;
use ThreadSafe\WordPress\Steps\ReadWordPressState;

/**
 * The steps of the WordPress adaptation that need a database, on a scratch database (docs/PHASE9.md 3.5): the rows keyed by the table prefix,
 * the caches and the drop-ins, the replacement of the old address with a base path and without the `guid`, and the first step against a real
 * server. The whole adaptation of a real site is proven by the round trips of the stack (work package 5).
 */
#[Group('wordpress-steps')]
final class WordPressStepsTest extends IntegrationTestCase
{
    private ?ScratchWordPress $site = null;

    protected function tearDown(): void
    {
        $this->site?->destroy();
        $this->site = null;
    }

    private function site(string $prefix = 'wp_', bool $htaccess = false): ScratchWordPress
    {
        return $this->site = ScratchWordPress::create($prefix, $htaccess);
    }

    // ------------------------------------------------------------------------------------------------- the table prefix

    public function testTheRowsThatAreKeyedByThePrefixFollowItAndOnlyThose(): void
    {
        $site = $this->site('wp2_');
        $site->option('wp_user_roles', 'a:1:{s:13:"administrator";a:0:{}}');
        $site->option('blogname', 'My site');
        foreach (['wp_capabilities', 'wp_user_level', 'wp_user-settings', 'nickname', 'first_wp_capabilities', 'WP_capabilities', 'wpXcapabilities'] as $key) {
            $site->exec('INSERT INTO wp2_usermeta (user_id, meta_key, meta_value) VALUES (1, ?, ?)', [$key, 'v']);
        }
        $context = $site->context(new MigrationRequest($site->root), 'wp_');

        $plan = (new FixTablePrefix())->plan($context);
        $result = (new FixTablePrefix())->apply($context, $plan);

        self::assertSame(4, $result->applied, 'the roles option and the keys that begin with the old prefix, compared byte for byte');
        self::assertSame(['a:1:{s:13:"administrator";a:0:{}}'], array_column($site->rows("SELECT option_value FROM wp2_options WHERE option_name = 'wp2_user_roles'"), 'option_value'));
        self::assertSame('0', $site->value("SELECT COUNT(*) FROM wp2_options WHERE option_name = 'wp_user_roles'"));
        $keys = array_column($site->rows('SELECT meta_key FROM wp2_usermeta'), 'meta_key');
        sort($keys, SORT_STRING);   // the order of the server depends on its collation
        self::assertSame(
            ['WP_capabilities', 'first_wp_capabilities', 'nickname', 'wp2_capabilities', 'wp2_user-settings', 'wp2_user_level', 'wpXcapabilities'],
            $keys,
            'a key that only looks like the prefix, or has it in another place or in another case, stays',
        );
        self::assertSame(0, (new FixTablePrefix())->apply($context, (new FixTablePrefix())->plan($context))->applied, 'it is done once');
    }

    public function testANewPrefixThatBeginsWithTheOldOneDoesNotTouchWhatHasItAlready(): void
    {
        $site = $this->site('wp_2_');
        $site->option('wp_user_roles', 'a:0:{}');
        foreach (['wp_capabilities', 'wp_2_capabilities', 'wp_2_user_level'] as $key) {
            $site->exec('INSERT INTO wp_2_usermeta (user_id, meta_key, meta_value) VALUES (1, ?, ?)', [$key, 'v']);
        }
        $context = $site->context(new MigrationRequest($site->root), 'wp_');

        (new FixTablePrefix())->apply($context, (new FixTablePrefix())->plan($context));
        $keys = array_column($site->rows('SELECT meta_key FROM wp_2_usermeta'), 'meta_key');
        sort($keys, SORT_STRING);
        self::assertSame(['wp_2_capabilities', 'wp_2_capabilities', 'wp_2_user_level'], $keys);
        self::assertSame('1', $site->value("SELECT COUNT(*) FROM wp_2_options WHERE option_name = 'wp_2_user_roles'"));
    }

    public function testAPrefixWithAnUnderscoreIsNotAWildcard(): void
    {
        $site = $this->site('x_');
        foreach (['a_b_capabilities', 'aXb_capabilities', 'aXbXcapabilities'] as $key) {
            $site->exec('INSERT INTO x_usermeta (user_id, meta_key, meta_value) VALUES (1, ?, ?)', [$key, 'v']);
        }
        $context = $site->context(new MigrationRequest($site->root), 'a_b_');

        (new FixTablePrefix())->apply($context, (new FixTablePrefix())->plan($context));

        self::assertSame(['aXb_capabilities', 'aXbXcapabilities', 'x_capabilities'], array_column($site->rows('SELECT meta_key FROM x_usermeta ORDER BY meta_key'), 'meta_key'));
    }

    public function testWithoutAChangeOfThePrefixNothingHappens(): void
    {
        $site = $this->site('wp_');
        $site->option('wp_user_roles', 'a:0:{}');
        $context = $site->context(new MigrationRequest($site->root), 'wp_');

        self::assertSame(0, (new FixTablePrefix())->apply($context, (new FixTablePrefix())->plan($context))->applied);
        self::assertSame('1', $site->value("SELECT COUNT(*) FROM wp_options WHERE option_name = 'wp_user_roles'"));
    }

    // ------------------------------------------------------------------------------------------------- caches and drop-ins

    public function testTheCachesTheTransientsAndTheDropInsAreClearedAndNothingElse(): void
    {
        $site = $this->site();
        foreach (['_transient_feed' => 'x', '_transient_timeout_feed' => '1', '_site_transient_update_core' => 'x', 'my_transient_option' => 'keep', 'blogname' => 'keep', 'rewrite_rules' => 'a:0:{}', 'siteurl' => 'http://old.example', '_transients_not' => 'keep'] as $name => $value) {
            $site->option($name, $value);
        }
        $site->writeFile('wp-content/cache/page.html', 'cached');
        $site->writeFile('wp-content/cache/supercache/a/b.html', 'cached');
        $site->writeFile('wp-content/cache/index.php', '<?php // silence');
        $site->writeFile('wp-content/upgrade/plugin.zip', 'half an update');
        $site->writeFile('wp-content/object-cache.php', '<?php throw new Exception("Redis");');
        $site->writeFile('wp-content/advanced-cache.php', '<?php // page cache');
        $site->writeFile('wp-content/db.php', '<?php // db proxy');
        $site->writeFile('wp-content/plugins/x/cache/keep.txt', 'code');
        $site->writeFile('wp-content/uploads/keep.jpg', 'image');
        $context = $site->context(new MigrationRequest($site->root));

        $plan = (new DiscardCaches())->plan($context);
        $result = (new DiscardCaches())->apply($context, $plan);

        self::assertGreaterThan(8, $result->applied);
        self::assertSame(['_transients_not', 'blogname', 'my_transient_option', 'siteurl'], array_column($site->rows('SELECT option_name FROM wp_options ORDER BY option_name'), 'option_name'), 'the transients and the rewrite rules go, the options stay');
        self::assertFileExists($site->root . '/wp-content/cache/index.php');
        self::assertFileDoesNotExist($site->root . '/wp-content/cache/page.html');
        self::assertDirectoryDoesNotExist($site->root . '/wp-content/cache/supercache');
        self::assertFileDoesNotExist($site->root . '/wp-content/upgrade/plugin.zip');
        foreach (['object-cache.php', 'advanced-cache.php', 'db.php'] as $dropIn) {
            self::assertFileDoesNotExist($site->root . '/wp-content/' . $dropIn);
            self::assertFileExists($site->root . '/wp-content/' . $dropIn . '.threadsafe-off', 'switched off, not deleted');
        }
        self::assertFileExists($site->root . '/wp-content/plugins/x/cache/keep.txt', 'a directory called cache inside a plugin is code');
        self::assertFileExists($site->root . '/wp-content/uploads/keep.jpg');
        self::assertSame(0, (new DiscardCaches())->apply($context, (new DiscardCaches())->plan($context))->applied, 'done once');
    }

    public function testDropInsThatTheRequestKeepsStayAndAreReported(): void
    {
        $site = $this->site();
        $site->writeFile('wp-content/object-cache.php', '<?php // redis that this host has');
        $context = $site->context(new MigrationRequest($site->root, options: ['keepDropIns' => true]));

        $plan = (new DiscardCaches())->plan($context);
        (new DiscardCaches())->apply($context, $plan);

        self::assertFileExists($site->root . '/wp-content/object-cache.php');
        self::assertFileDoesNotExist($site->root . '/wp-content/object-cache.php.threadsafe-off');
        self::assertStringContainsString('The drop-in wp-content/object-cache.php is kept', $plan->notices()[0]->message);
    }

    // ------------------------------------------------------------------------------------------------- the address in the content

    public function testTheOldAddressIsReplacedWithTheBasePathInEveryShapeAndTheGuidStaysAlone(): void
    {
        $site = $this->site();
        $site->option('siteurl', 'http://old.example');
        $site->option('home', 'http://old.example');
        $site->option('widget_text', serialize(['a' => ['text' => '<a href="http://old.example/about/">x</a>'], 'b' => 'https://other.example/old.example']));
        $site->exec('INSERT INTO wp_posts (post_title, post_content, guid) VALUES (?, ?, ?)', [
            'Hello',
            '<img src="http://old.example/wp-content/uploads/a.jpg"> <!-- wp:image {"url":"http:\/\/old.example\/wp-content\/uploads\/a.jpg"} --> mail admin@old.example https://other.example/p',
            'http://old.example/?p=1',
        ]);
        $site->exec('INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (1, ?, ?)', ['_elementor_data', '[{"url":"http:\/\/old.example\/contact\/"}]']);
        $site->exec('INSERT INTO wp_woocommerce_sessions (session_key, session_value) VALUES (?, ?)', ['k', 'http://old.example/session']);
        $mapping = (new DomainMapping('old.example', 'a-much-longer-new-host.example.org'))->withPath('/sub');
        $request = new MigrationRequest($site->root, [$mapping], skipTables: ['woocommerce_sessions'], skipColumns: ['posts.guid']);
        $context = $site->context($request);

        $result = (new ReplaceContentDomains())->apply($context, (new ReplaceContentDomains())->plan($context));

        self::assertGreaterThan(0, $result->applied);
        self::assertSame('http://a-much-longer-new-host.example.org/sub', $site->value("SELECT option_value FROM wp_options WHERE option_name = 'siteurl'"));
        $content = $site->value('SELECT post_content FROM wp_posts WHERE ID = 1');
        self::assertStringContainsString('<img src="http://a-much-longer-new-host.example.org/sub/wp-content/uploads/a.jpg">', $content);
        self::assertStringContainsString('{"url":"http:\/\/a-much-longer-new-host.example.org\/sub\/wp-content\/uploads\/a.jpg"}', $content, 'the escaped slashes of the block comment stay escaped');
        self::assertStringContainsString('mail admin@old.example', $content, 'an e-mail address is never touched');
        self::assertStringContainsString('https://other.example/p', $content);
        self::assertSame('[{"url":"http:\/\/a-much-longer-new-host.example.org\/sub\/contact\/"}]', $site->value("SELECT meta_value FROM wp_postmeta WHERE meta_key = '_elementor_data'"));
        self::assertSame('http://old.example/?p=1', $site->value('SELECT guid FROM wp_posts WHERE ID = 1'), 'the identifier of a post is not an address');
        self::assertSame('http://old.example/session', $site->value('SELECT session_value FROM wp_woocommerce_sessions'), 'the table of sessions is skipped');
        $widget = unserialize($site->value("SELECT option_value FROM wp_options WHERE option_name = 'widget_text'"));
        self::assertSame(['a' => ['text' => '<a href="http://a-much-longer-new-host.example.org/sub/about/">x</a>'], 'b' => 'https://other.example/old.example'], $widget, 'serialized data stays valid with a longer address');
    }

    public function testTheGuidIsReplacedWhenItIsNotSkipped(): void
    {
        $site = $this->site();
        $site->exec('INSERT INTO wp_posts (post_title, post_content, guid) VALUES (?, ?, ?)', ['Hello', 'text', 'http://old.example/?p=1']);
        $context = $site->context(new MigrationRequest($site->root, [new DomainMapping('old.example', 'new.example')]));

        (new ReplaceContentDomains())->apply($context, (new ReplaceContentDomains())->plan($context));

        self::assertSame('http://new.example/?p=1', $site->value('SELECT guid FROM wp_posts WHERE ID = 1'), 'with --include-guid');
    }

    // ------------------------------------------------------------------------------------------------- the first step against a server

    private function readState(ScratchWordPress $site, ?MigrationRequest $request = null): void
    {
        $request ??= new MigrationRequest($site->root, [], $site->settings);
        (new ReadWordPressState())->plan(new Context($request, new NullJournal(), new NullLogger(), true));
    }

    public function testTheFirstStepReadsTheDatabaseAndThePrefixMustFit(): void
    {
        $site = $this->site();
        $site->option('siteurl', 'http://old.example');
        $site->option('home', 'http://old.example');
        $context = new Context(new MigrationRequest($site->root, [], $site->settings), new NullJournal(), new NullLogger(), true);

        $plan = (new ReadWordPressState())->plan($context);

        self::assertStringContainsString('WordPress 6.9.4, database "' . $site->settings->name . '" (prefix "wp_")', $plan->notices()[0]->message);
        self::assertStringContainsString('the address is http://old.example', $plan->notices()[0]->message);

        $wrong = new MigrationRequest($site->root, [], new \ThreadSafe\Database\DatabaseSettings('db', 3306, $site->settings->name, 'root', $site->settings->password, 'zz_'));
        try {
            $this->readState($site, $wrong);
            self::fail('a prefix that does not fit must be refused');
        } catch (GuardFailed $e) {
            self::assertStringContainsString('Table "zz_options" does not exist: wrong table prefix', $e->getMessage());
        }
    }

    public function testTheFirstStepRefusesADatabaseThatHoldsANetworkAndABasePathForASiteInADirectory(): void
    {
        $site = $this->site();
        $site->option('siteurl', 'http://old.example/blog');
        $site->option('home', 'http://old.example/blog');
        try {
            $this->readState($site, new MigrationRequest($site->root, [], $site->settings, '/sub/'));
            self::fail('a site in a directory cannot be given a base path');
        } catch (GuardFailed $e) {
            self::assertStringContainsString('The site lives in the directory "/blog/"', $e->getMessage());
        }

        $site->exec('CREATE TABLE wp_blogs (blog_id BIGINT PRIMARY KEY)');
        $this->expectException(GuardFailed::class);
        $this->expectExceptionMessage('multisite network');
        $this->readState($site);
    }
}
