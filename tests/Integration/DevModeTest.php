<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Integration;

use ThreadSafe\Core\Context;
use ThreadSafe\Core\FileJournal;
use ThreadSafe\Core\NoticeLevel;
use ThreadSafe\Core\NullJournal;
use ThreadSafe\Core\Plan;
use ThreadSafe\Migration\MailCatcher;
use ThreadSafe\Migration\MigrationRequest;
use ThreadSafe\Migration\Mode;
use ThreadSafe\PrestaShop9\Steps\ApplyDevMode;
use ThreadSafe\PrestaShop9\Steps\BlockSearchEngines;
use ThreadSafe\PrestaShop9\Steps\ReadSourceState;
use ThreadSafe\Tests\Support\ScratchShop;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * The two dev mode steps against a small fake shop: a scratch database with the configuration, module and cloud
 * tables the rules look at, and the files robots.txt and .htaccess.
 */
final class DevModeTest extends IntegrationTestCase
{
    use TempDirectory;

    private const string LIVE_SECRET = 'live-secret-value-123456';

    private ScratchShop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = ScratchShop::create('old.test');

        $engine = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci';
        foreach ([
            "CREATE TABLE ps_module (id_module INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(64) NOT NULL, active TINYINT(1) NOT NULL DEFAULT 0) $engine",
            "CREATE TABLE ps_module_shop (id_module INT UNSIGNED NOT NULL, id_shop INT UNSIGNED NOT NULL, PRIMARY KEY (id_module, id_shop)) $engine",
            "CREATE TABLE ps_customer (id_customer INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, email VARCHAR(255) NOT NULL) $engine",
            "CREATE TABLE ps_paypal_webhook (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, payload TEXT NULL) $engine",
            "CREATE TABLE ps_eventbus_incremental_sync (type VARCHAR(50) NOT NULL PRIMARY KEY, last_id INT NOT NULL) $engine",
            "CREATE TABLE ps_memcached_servers (id_memcached_server INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, ip VARCHAR(254) NOT NULL) $engine",
        ] as $sql) {
            $this->shop->pdo()->exec($sql);
        }

        foreach ([
            'PS_MAIL_METHOD' => '1', 'PS_MAIL_SERVER' => 'smtp.live.example', 'PS_MAIL_SMTP_PORT' => '587', 'PS_MAIL_SMTP_ENCRYPTION' => 'tls',
            'PS_MAIL_USER' => 'shop@live.example', 'PS_MAIL_PASSWD' => self::LIVE_SECRET, 'PS_MAIL_DKIM_ENABLE' => '1', 'PS_MAIL_DKIM_KEY' => 'dkim-private',
            'MA_MERCHANT_MAILS' => 'owner@live.example',
            'PAYPAL_SANDBOX' => '0', 'PAYPAL_LIVE_CLIENTID' => 'live-client', 'PAYPAL_LIVE_SECRET' => self::LIVE_SECRET, 'PAYPAL_WEBHOOK_ID' => 'WH-LIVE-1',
            'PAYPAL_ENABLE_WEBHOOK' => '1', 'PS_CHECKOUT_WEBHOOK_SECRET' => self::LIVE_SECRET,
            'PS_ACCOUNTS_RSA_PRIVATE_KEY' => self::LIVE_SECRET, 'PS_ACCOUNTS_FIREBASE_REFRESH_TOKEN' => self::LIVE_SECRET, 'PS_PSX_FIREBASE_ID_TOKEN' => self::LIVE_SECRET,
            'PS_FACEBOOK_ACCESS_TOKEN' => self::LIVE_SECRET,
            'SOME_SERVICE_API_KEY' => 'a-key-no-rule-handles', 'PS_SECURITY_TOKEN' => 'a-security-token-that-is-long',
            'UNRELATED' => 'keep me',
        ] as $name => $value) {
            $this->config($name, $value);
        }
        $this->shop->exec("INSERT INTO ps_configuration (id_shop, name, value, date_add, date_upd) VALUES (2, 'PS_MAIL_PASSWD', 'second-shop-secret', NOW(), NOW())");

        foreach (['ps_accounts', 'ps_eventbus', 'ps_checkout', 'ps_facebook', 'ps_googleanalytics', 'psxmarketingwithgoogle', 'ps_wirepayment', 'ps_mbo'] as $module) {
            $this->shop->exec('INSERT INTO ps_module (name, active) VALUES (?, 1)', [$module]);
            $this->shop->exec('INSERT INTO ps_module_shop (id_module, id_shop) SELECT id_module, 1 FROM ps_module WHERE name = ?', [$module]);
        }
        // ps_mbo is not on any list; a module installed but already off (no shop row) needs nothing.
        $this->shop->exec("INSERT INTO ps_module (name, active) VALUES ('ps_emailalerts', 0)");

        $this->shop->exec("INSERT INTO ps_customer (email) VALUES ('a@customer.example'), ('b@customer.example')");
        $this->shop->exec("INSERT INTO ps_paypal_webhook (payload) VALUES ('x'), ('y'), ('z')");
        $this->shop->exec("INSERT INTO ps_eventbus_incremental_sync (type, last_id) VALUES ('products', 4)");
    }

    protected function tearDown(): void
    {
        $this->shop->destroy();
        $this->cleanUpTempDirectories();
    }

    private function config(string $name, string $value): void
    {
        $this->shop->exec('INSERT INTO ps_configuration (name, value, date_add, date_upd) VALUES (?, ?, NOW(), NOW())', [$name, $value]);
    }

    private function value(string $name): ?string
    {
        $rows = $this->shop->rows('SELECT value FROM ps_configuration WHERE name = ? AND id_shop IS NULL', [$name]);

        return $rows === [] ? null : (string) $rows[0]['value'];
    }

    /**
     * @param list<string> $skip
     */
    private function request(Mode $mode = Mode::Dev, ?string $catcher = 'mailpit:1025', array $skip = []): MigrationRequest
    {
        return new MigrationRequest(
            $this->shop->root,
            mode: $mode,
            mailCatcher: $catcher === null ? null : MailCatcher::parse($catcher),
            devSkip: $skip,
        );
    }

    private function context(MigrationRequest $request, ?FileJournal $journal = null): Context
    {
        $context = $this->shop->context($request, $journal ?? new NullJournal(), $journal === null);
        (new ReadSourceState())->plan($context);

        return $context;
    }

    private function applyDatabase(MigrationRequest $request, ?FileJournal $journal = null): int
    {
        $context = $this->context($request, $journal ?? new FileJournal($this->tempDirectory()));
        $step = new ApplyDevMode();

        return $step->apply($context, $step->plan($context))->applied;
    }

    /** @return list<string> */
    private static function targets(Plan $plan): array
    {
        return array_map(static fn ($change): string => $change->target, $plan->changes());
    }

    public function testProductionModeDoesNothing(): void
    {
        $plan = (new ApplyDevMode())->plan($this->context($this->request(Mode::Production, null)));

        self::assertSame([], $plan->changes());
        self::assertSame([], $plan->notices());
    }

    public function testPlanListsTheDevChangesAndChangesNothing(): void
    {
        $plan = (new ApplyDevMode())->plan($this->context($this->request()));

        $targets = self::targets($plan);
        foreach (['db ps_configuration PS_MAIL_SERVER', 'db ps_configuration PS_MAIL_PASSWD', 'db ps_configuration PAYPAL_LIVE_SECRET', 'db ps_configuration PS_ACCOUNTS_RSA_PRIVATE_KEY', 'db ps_paypal_webhook', 'module ps_accounts', 'module ps_facebook'] as $expected) {
            self::assertContains($expected, $targets);
        }
        self::assertNotContains('module ps_wirepayment', $targets);
        self::assertNotContains('module ps_emailalerts', $targets, 'A module that is already off needs nothing.');
        self::assertSame('smtp.live.example', $this->value('PS_MAIL_SERVER'));
        self::assertSame('1', $this->shop->value("SELECT active FROM ps_module WHERE name = 'ps_accounts'"));
        self::assertSame('3', $this->shop->value('SELECT COUNT(*) FROM ps_paypal_webhook'));
    }

    public function testSecretsAreMaskedInThePlan(): void
    {
        $plan = (new ApplyDevMode())->plan($this->context($this->request()));

        $json = json_encode($plan->toArray(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(self::LIVE_SECRET, $json);
        self::assertStringNotContainsString('dkim-private', $json);
        self::assertStringNotContainsString('second-shop-secret', $json);
    }

    public function testApplyCutsTheLiveConnections(): void
    {
        $applied = $this->applyDatabase($this->request());

        self::assertGreaterThan(15, $applied);

        // mail: through the catcher, no credentials left, in every shop
        self::assertSame('2', $this->value('PS_MAIL_METHOD'), 'The missing setting is created.');
        self::assertSame('mailpit', $this->value('PS_MAIL_SERVER'));
        self::assertSame('1025', $this->value('PS_MAIL_SMTP_PORT'));
        self::assertSame('off', $this->value('PS_MAIL_SMTP_ENCRYPTION'));
        self::assertSame('', $this->value('PS_MAIL_USER'));
        self::assertSame('', $this->value('PS_MAIL_PASSWD'));
        self::assertSame('', $this->shop->value("SELECT value FROM ps_configuration WHERE name = 'PS_MAIL_PASSWD' AND id_shop = 2"));
        self::assertSame('0', $this->value('PS_MAIL_DKIM_ENABLE'));
        self::assertSame('', $this->value('PS_MAIL_DKIM_KEY'));
        self::assertSame('owner@live.example', $this->value('MA_MERCHANT_MAILS'), 'Recipients stay: the mail goes to the catcher anyway.');

        // payment
        self::assertSame('1', $this->value('PAYPAL_SANDBOX'));
        self::assertSame('0', $this->value('PAYPAL_ENABLE_WEBHOOK'));
        self::assertSame('', $this->value('PAYPAL_WEBHOOK_ID'));
        self::assertNull($this->value('PAYPAL_LIVE_CLIENTID'));
        self::assertNull($this->value('PAYPAL_LIVE_SECRET'));
        self::assertNull($this->value('PS_CHECKOUT_WEBHOOK_SECRET'));
        self::assertSame('0', $this->shop->value('SELECT COUNT(*) FROM ps_paypal_webhook'));

        // cloud account and tracking
        self::assertSame('0', $this->shop->value("SELECT COUNT(*) FROM ps_configuration WHERE name LIKE 'PS\\_ACCOUNTS\\_%' OR name LIKE 'PS\\_PSX\\_FIREBASE\\_%' OR name LIKE 'PS\\_FACEBOOK\\_%'"));
        self::assertSame('0', $this->shop->value('SELECT COUNT(*) FROM ps_eventbus_incremental_sync'));
        foreach (['ps_accounts', 'ps_eventbus', 'ps_checkout', 'ps_facebook', 'ps_googleanalytics', 'psxmarketingwithgoogle'] as $module) {
            self::assertSame('0', $this->shop->value('SELECT active FROM ps_module WHERE name = ?', [$module]), $module);
            self::assertSame('0', $this->shop->value('SELECT COUNT(*) FROM ps_module_shop ms JOIN ps_module m USING (id_module) WHERE m.name = ?', [$module]), $module);
        }

        // everything else stays
        self::assertSame('1', $this->shop->value("SELECT active FROM ps_module WHERE name = 'ps_wirepayment'"));
        self::assertSame('1', $this->shop->value("SELECT COUNT(*) FROM ps_module_shop ms JOIN ps_module m USING (id_module) WHERE m.name = 'ps_mbo'"));
        self::assertSame('keep me', $this->value('UNRELATED'));
        self::assertSame('a-key-no-rule-handles', $this->value('SOME_SERVICE_API_KEY'));
        self::assertSame('2', $this->shop->value('SELECT COUNT(*) FROM ps_customer'), 'Customers are not touched.');
    }

    public function testWithoutACatcherTheShopSendsNoMail(): void
    {
        $this->applyDatabase($this->request(catcher: null));

        self::assertSame('3', $this->value('PS_MAIL_METHOD'));
        self::assertSame('smtp.live.example', $this->value('PS_MAIL_SERVER'), 'Without a catcher there is nothing to point the server at.');
        self::assertSame('', $this->value('PS_MAIL_PASSWD'));
    }

    public function testASecondRunFindsNothingToDo(): void
    {
        $this->applyDatabase($this->request());

        $step = new ApplyDevMode();
        $plan = $step->plan($this->context($this->request()));

        self::assertFalse($plan->hasChanges(), implode(', ', self::targets($plan)));
        self::assertSame(0, $this->applyDatabase($this->request()));
    }

    public function testSkippedRulesStayAsTheyAre(): void
    {
        $this->applyDatabase($this->request(skip: ['payment', 'tracking']));

        self::assertSame('mailpit', $this->value('PS_MAIL_SERVER'));
        self::assertSame('0', $this->value('PAYPAL_SANDBOX'));
        self::assertSame(self::LIVE_SECRET, $this->value('PAYPAL_LIVE_SECRET'));
        self::assertSame('1', $this->shop->value("SELECT active FROM ps_module WHERE name = 'ps_facebook'"));
        self::assertSame('0', $this->shop->value("SELECT active FROM ps_module WHERE name = 'ps_accounts'"));
        $plan = (new ApplyDevMode())->plan($this->context($this->request(skip: ['payment', 'tracking'])));
        $messages = array_map(static fn ($n): string => $n->message, $plan->notices());
        self::assertContains('Dev rules switched off: payment, tracking. Their live connections stay active.', $messages);
    }

    public function testNoticesWarnAboutCustomersCacheAndUnhandledCredentials(): void
    {
        $this->shop->writeFile('app/config/parameters.php', '<?php return ' . var_export(['parameters' => [
            'database_host' => $this->shop->settings->host, 'database_port' => '3306', 'database_name' => $this->shop->settings->name,
            'database_user' => $this->shop->settings->user, 'database_password' => $this->shop->settings->password, 'database_prefix' => 'ps_',
            'ps_cache_enable' => true, 'ps_caching' => 'CacheMemcache',
        ]], true) . ';');
        $this->shop->exec("INSERT INTO ps_memcached_servers (ip) VALUES ('cache.live.example')");

        $plan = (new ApplyDevMode())->plan($this->context($this->request()));

        $warnings = array_values(array_map(static fn ($n): string => $n->message, array_filter($plan->notices(), static fn ($n): bool => $n->level === NoticeLevel::Warning)));
        $text = implode("\n", $warnings);
        self::assertStringContainsString('holds 2 customer account(s)', $text);
        self::assertStringContainsString('The cache is enabled (ps_caching = CacheMemcache, 1 server(s) in ps_memcached_servers)', $text);
        self::assertStringContainsString('SOME_SERVICE_API_KEY', $text);
        self::assertStringNotContainsString('PS_SECURITY_TOKEN', $text, 'Harmless core settings are not reported.');
        self::assertStringNotContainsString('PAYPAL_LIVE_SECRET', $text, 'Settings a rule handles are not reported.');
        self::assertStringNotContainsString('a-key-no-rule-handles', $text, 'Values are never shown.');
        $infos = array_map(static fn ($n): string => $n->message, array_filter($plan->notices(), static fn ($n): bool => $n->level === NoticeLevel::Info));
        self::assertContains('All mail goes to the mail catcher mailpit:1025.', $infos);
    }

    public function testJournalKeepsTheOldValuesForARevertByHand(): void
    {
        $directory = $this->tempDirectory();
        $journal = new FileJournal($directory);

        $this->applyDatabase($this->request(), $journal);

        $text = (string) file_get_contents((string) $journal->path());
        self::assertStringContainsString(self::LIVE_SECRET, $text, 'The journal is the one place that keeps the removed values.');
        self::assertStringContainsString('PAYPAL_LIVE_CLIENTID', $text, 'A removed row is identified by its name.');
        self::assertStringContainsString('"ps_accounts"', $text);
        self::assertStringContainsString('Emptied table ps_paypal_webhook', $text);
        self::assertSame(0600, fileperms((string) $journal->path()) & 0777);
    }

    // ---- file part -------------------------------------------------------------------------------------------

    private const string PRESTASHOP_BLOCK = "# ~~start~~ Do not remove this comment\nRewriteEngine on\nRewriteCond %{HTTP_HOST} ^old\\.test\$\n# ~~end~~ Do not remove this comment";

    private function applyFiles(MigrationRequest $request, ?FileJournal $journal = null): int
    {
        $context = $this->context($request, $journal ?? new FileJournal($this->tempDirectory()));
        $step = new BlockSearchEngines();

        return $step->apply($context, $step->plan($context))->applied;
    }

    public function testRobotsTxtAndHeaderKeepSearchEnginesOut(): void
    {
        $this->shop->writeFile('robots.txt', "User-agent: *\nDisallow: /classes/\nSitemap: http://old.test/sitemap.xml\n");
        $this->shop->writeFile('.htaccess', "# my own rule\nOptions -Indexes\n\n" . self::PRESTASHOP_BLOCK . "\n");

        $plan = (new BlockSearchEngines())->plan($this->context($this->request()));
        self::assertSame(['file robots.txt', 'file .htaccess'], self::targets($plan));
        self::assertStringContainsString('Disallow: /classes/', (string) file_get_contents($this->shop->root . '/robots.txt'), 'A plan changes nothing.');

        self::assertSame(2, $this->applyFiles($this->request()));

        self::assertSame("User-agent: *\nDisallow: /\n", file_get_contents($this->shop->root . '/robots.txt'));
        $htaccess = (string) file_get_contents($this->shop->root . '/.htaccess');
        self::assertStringStartsWith('# ~~threadsafe-dev-start~~', $htaccess);
        self::assertStringContainsString('Header set X-Robots-Tag "noindex, nofollow"', $htaccess);
        self::assertStringContainsString('<IfModule mod_headers.c>', $htaccess, 'Without mod_headers the block must not break the site.');
        self::assertStringContainsString("# my own rule\nOptions -Indexes\n\n" . self::PRESTASHOP_BLOCK . "\n", $htaccess, 'Everything else is untouched.');
        self::assertLessThan(strpos($htaccess, '# ~~start~~'), strpos($htaccess, 'X-Robots-Tag'), 'The block sits outside, before PrestaShop\'s markers.');
    }

    public function testSecondRunChangesNothing(): void
    {
        $this->shop->writeFile('.htaccess', self::PRESTASHOP_BLOCK . "\n");
        $this->applyFiles($this->request());
        $once = (string) file_get_contents($this->shop->root . '/.htaccess');

        self::assertSame(0, $this->applyFiles($this->request()));
        self::assertFalse((new BlockSearchEngines())->plan($this->context($this->request()))->hasChanges());
        self::assertSame($once, file_get_contents($this->shop->root . '/.htaccess'));
        self::assertSame(1, substr_count($once, '# ~~threadsafe-dev-start~~'));
    }

    public function testChangedHeaderTextReplacesTheOldBlock(): void
    {
        $rules = $this->tempDirectory() . '/rules.json';
        file_put_contents($rules, json_encode(['rules' => [['id' => 'noindex', 'noindex' => ['robots' => "User-agent: *\nDisallow: /\n", 'header' => 'noindex']]]]));
        $this->shop->writeFile('.htaccess', self::PRESTASHOP_BLOCK . "\n");
        $this->applyFiles($this->request());

        $custom = new MigrationRequest($this->shop->root, mode: Mode::Dev, devRulesFile: $rules);
        self::assertSame(1, $this->applyFiles($custom));

        $htaccess = (string) file_get_contents($this->shop->root . '/.htaccess');
        self::assertStringContainsString('Header set X-Robots-Tag "noindex"', $htaccess);
        self::assertStringNotContainsString('nofollow', $htaccess);
        self::assertSame(1, substr_count($htaccess, '# ~~threadsafe-dev-start~~'));
    }

    public function testRobotsTxtIsCreatedWhenMissingAndTheOriginalIsJournalled(): void
    {
        $directory = $this->tempDirectory();
        $journal = new FileJournal($directory);
        $this->shop->writeFile('.htaccess', self::PRESTASHOP_BLOCK . "\n");

        $this->applyFiles($this->request(), $journal);

        self::assertSame("User-agent: *\nDisallow: /\n", file_get_contents($this->shop->root . '/robots.txt'));
        $text = (string) file_get_contents((string) $journal->path());
        self::assertStringContainsString('"action":"create"', $text);
        self::assertStringContainsString('"action":"update"', $text);
        $copies = glob($directory . '/files/*') ?: [];
        self::assertNotSame([], $copies, 'The original .htaccess is kept in the journal.');
    }

    public function testWithoutHtaccessOnlyRobotsTxtIsWrittenAndTheHeaderIsExplained(): void
    {
        $plan = (new BlockSearchEngines())->plan($this->context($this->request()));

        self::assertSame(['file robots.txt'], self::targets($plan));
        self::assertStringContainsString('X-Robots-Tag: noindex, nofollow', $plan->notices()[0]->message);
        self::assertSame(1, $this->applyFiles($this->request()));
        self::assertFileDoesNotExist($this->shop->root . '/.htaccess');
    }

    public function testNothingHappensInProductionOrWhenTheRuleIsSkipped(): void
    {
        $this->shop->writeFile('robots.txt', "Disallow: /classes/\n");

        self::assertFalse((new BlockSearchEngines())->plan($this->context($this->request(Mode::Production, null)))->hasChanges());
        self::assertFalse((new BlockSearchEngines())->plan($this->context($this->request(skip: ['noindex'])))->hasChanges());
        self::assertSame(0, $this->applyFiles($this->request(skip: ['noindex'])));
        self::assertSame("Disallow: /classes/\n", file_get_contents($this->shop->root . '/robots.txt'));
    }
}
