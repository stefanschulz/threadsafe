<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Integration;

use ThreadSafe\Core\Context;
use ThreadSafe\Core\Exception\SelfTestFailed;
use ThreadSafe\Core\NoticeLevel;
use ThreadSafe\Core\NullJournal;
use ThreadSafe\Http\HttpClient;
use ThreadSafe\Migration\MigrationRequest;
use ThreadSafe\Migration\Mode;
use ThreadSafe\Migration\SslMode;
use ThreadSafe\PrestaShop9\Steps\Finalize;
use ThreadSafe\PrestaShop9\Steps\ReadSourceState;
use ThreadSafe\PrestaShop9\Steps\SelfTest;
use ThreadSafe\Replace\DomainMapping;
use ThreadSafe\Tests\Support\ScratchShop;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * The self-test and the finalize step against a small fake shop; the web server is replaced by canned answers.
 */
final class SelfTestAndFinalizeTest extends IntegrationTestCase
{
    use TempDirectory;

    private ScratchShop $shop;

    /** @var list<string> "url|host" of every request the fake web server received */
    private array $requests = [];

    /** @var array<string, array{int, array<string, string>, string}> answers by path-and-query (after the origin) */
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = ScratchShop::create('old.test');

        $engine = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci';
        foreach ([
            "CREATE TABLE ps_product (id_product INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, active TINYINT(1) NOT NULL) $engine",
            "CREATE TABLE ps_product_lang (id_product INT UNSIGNED NOT NULL, id_lang INT UNSIGNED NOT NULL, link_rewrite VARCHAR(128) NOT NULL, PRIMARY KEY (id_product, id_lang)) $engine",
            "CREATE TABLE ps_image (id_image INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, id_product INT UNSIGNED NOT NULL, position SMALLINT NOT NULL DEFAULT 1) $engine",
            "CREATE TABLE ps_image_type (id_image_type INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(64) NOT NULL, products TINYINT(1) NOT NULL) $engine",
        ] as $sql) {
            $this->shop->pdo()->exec($sql);
        }
        $this->shop->exec("INSERT INTO ps_configuration (name, value, date_add, date_upd) VALUES ('PS_HOME_CATEGORY', '2', NOW(), NOW())");
        $this->shop->exec('INSERT INTO ps_product (active) VALUES (1)');
        $this->shop->exec("INSERT INTO ps_product_lang (id_product, id_lang, link_rewrite) VALUES (1, 1, 'mug')");
        $this->shop->exec('INSERT INTO ps_image (id_product) VALUES (1)');
        $this->shop->exec("INSERT INTO ps_image_type (name, products) VALUES ('small_default', 1)");
        $this->shop->writeFile('.htaccess', "# ~~start~~\n# ~~end~~\n");

        $page = [200, ['content-type' => 'text/html'], '<html><body>shop</body></html>'];
        $this->answers = [
            '/' => $page,
            '/index.php?id_category=2&controller=category' => $page,
            '/index.php?id_product=1&controller=product' => $page,
            '/1-small_default/mug.jpg' => [200, ['content-type' => 'image/jpeg'], 'JPEG'],
            '/index.php?controller=contact' => $page,
            '/index.php?controller=authentication' => $page,
            '/admin-x/' => $page,
        ];
    }

    protected function tearDown(): void
    {
        $this->shop->destroy();
        $this->cleanUpTempDirectories();
    }

    private function client(): HttpClient
    {
        return new HttpClient(false, 5, 10, function (string $url, string $host): array {
            $this->requests[] = "$url|$host";
            $path = (string) preg_replace('~^https?://[^/]+~', '', $url);

            return $this->answers[$path] ?? [404, [], 'not found'];
        });
    }

    private function request(?string $physicalUri = null, ?string $selfTestUrl = null, bool $selfTest = true, ?SslMode $ssl = null, bool $finalize = true, Mode $mode = Mode::Production): MigrationRequest
    {
        return new MigrationRequest(
            $this->shop->root,
            [new DomainMapping('old.test', 'new.test')],
            physicalUri: $physicalUri,
            ssl: $ssl,
            mode: $mode,
            selfTest: $selfTest,
            selfTestUrl: $selfTestUrl,
            finalize: $finalize,
        );
    }

    private function context(MigrationRequest $request): Context
    {
        $context = $this->shop->context($request, new NullJournal(), false);
        (new ReadSourceState())->plan($context);

        return $context;
    }

    public function testEveryCheckPasses(): void
    {
        $context = $this->context($this->request());
        $step = new SelfTest($this->client());

        $result = $step->apply($context, $step->plan($context));

        $messages = array_map(static fn ($n): string => $n->message, $result->notices);
        self::assertCount(7, $result->notices);
        self::assertSame('ok: home page (/) - HTTP 200', $messages[0]);
        self::assertContains('ok: product image (/1-small_default/mug.jpg) - HTTP 200', $messages);
        self::assertContains('ok: back office login (/admin-x/) - HTTP 200', $messages);
        self::assertSame('http://new.test/|new.test', $this->requests[0], 'The shop is asked under its new domain, also before the database says so.');
    }

    public function testFailedChecksStopTheRunWithAllTheirReasons(): void
    {
        $this->answers['/index.php?id_product=1&controller=product'] = [500, [], 'oops'];
        $this->answers['/'] = [200, [], '<html><a href="http://old.test/en/">x</a> <a href="http://old.test/img/a.png">y</a></html>'];
        $this->answers['/1-small_default/mug.jpg'] = [200, ['content-type' => 'text/html'], '<html>login</html>'];
        $context = $this->context($this->request());
        $step = new SelfTest($this->client());

        try {
            $step->apply($context, $step->plan($context));
            self::fail('The self-test must fail.');
        } catch (SelfTestFailed $e) {
            self::assertStringContainsString('3 check(s) failed', $e->getMessage());
            self::assertStringContainsString('home page (/): 2 link(s) on the page still point to the old domain', $e->getMessage());
            self::assertStringContainsString('product page (/index.php?id_product=1&controller=product): HTTP 500', $e->getMessage());
            self::assertStringContainsString('product image', $e->getMessage());
            self::assertStringContainsString('not with an image', $e->getMessage());
        }
    }

    public function testIncompleteHomePageAndRedirectLoopsFail(): void
    {
        $this->answers['/'] = [200, [], '<html><body>Fatal error: ...'];
        $this->answers['/index.php?controller=contact'] = [302, ['location' => '/index.php?controller=authentication'], ''];
        $this->answers['/index.php?controller=authentication'] = [302, ['location' => '/index.php?controller=contact'], ''];
        $context = $this->context($this->request());
        $step = new SelfTest($this->client());

        try {
            $step->apply($context, $step->plan($context));
            self::fail('The self-test must fail.');
        } catch (SelfTestFailed $e) {
            self::assertStringContainsString('no closing </html>', $e->getMessage());
            self::assertStringContainsString('redirect loop', $e->getMessage());
        }
    }

    public function testRedirectToTheOldDomainFails(): void
    {
        $this->answers['/index.php?controller=contact'] = [301, ['location' => 'http://old.test/contact'], ''];
        $context = $this->context($this->request());
        $step = new SelfTest($this->client());

        $this->expectException(SelfTestFailed::class);
        $this->expectExceptionMessage('redirect to another host: http://old.test/contact');

        $step->apply($context, $step->plan($context));
    }

    public function testWhatTheShopDoesNotHaveIsSkippedWithAWarning(): void
    {
        $this->shop->pdo()->exec('DELETE FROM ps_product');
        unlink($this->shop->root . '/.htaccess');
        $context = $this->context($this->request());
        $step = new SelfTest($this->client());

        $result = $step->apply($context, $step->plan($context));

        $warnings = array_map(static fn ($n): string => ($n->target ?? '') . ': ' . $n->message, array_filter($result->notices, static fn ($n): bool => $n->level === NoticeLevel::Warning));
        self::assertContains('product page: Not checked - the shop has no active product', $warnings);
        self::assertContains('product image: Not checked - the shop has no product image', $warnings);
    }

    public function testNoHtaccessSkipsOnlyTheImageCheck(): void
    {
        unlink($this->shop->root . '/.htaccess');
        $context = $this->context($this->request());
        $step = new SelfTest($this->client());

        $result = $step->apply($context, $step->plan($context));

        $warnings = array_values(array_map(static fn ($n): string => $n->message, array_filter($result->notices, static fn ($n): bool => $n->level === NoticeLevel::Warning)));
        self::assertCount(1, $warnings);
        self::assertStringContainsString('there is no .htaccess', $warnings[0]);
    }

    public function testPathHttpsAndAnExplicitAddress(): void
    {
        $this->shop->exec("INSERT INTO ps_configuration (name, value, date_add, date_upd) VALUES ('PS_SSL_ENABLED_EVERYWHERE', '1', NOW(), NOW())");
        $this->answers = array_combine(
            array_map(static fn (string $path): string => '/shop' . $path, array_keys($this->answers)),
            array_values($this->answers),
        );
        $context = $this->context($this->request(physicalUri: '/shop/'));
        $step = new SelfTest($this->client());

        $step->apply($context, $step->plan($context));
        self::assertSame('https://new.test/shop/|new.test', $this->requests[0], 'https because the shop uses it everywhere; the path is added.');

        $this->requests = [];
        $context = $this->context($this->request(physicalUri: '/shop/', selfTestUrl: 'http://web:8080/'));
        $step->apply($context, $step->plan($context));
        self::assertSame(['http://web:8080/shop/|new.test'], array_slice($this->requests, 0, 1), 'The address is where to connect, the shop domain goes in the Host header.');
    }

    public function testDryRunPlanSaysWhatWillBeChecked(): void
    {
        $plan = (new SelfTest($this->client()))->plan($this->context($this->request(selfTestUrl: 'http://web')));

        self::assertFalse($plan->hasChanges());
        self::assertSame('After the migration the shop is requested at http://web/ (Host: new.test) and must answer.', $plan->notices()[0]->message);
        self::assertSame([], $this->requests, 'A dry run requests nothing.');
    }

    public function testSwitchedOffSelfTestWarnsAndRequestsNothing(): void
    {
        $context = $this->context($this->request(selfTest: false));
        $step = new SelfTest($this->client());

        $plan = $step->plan($context);
        $result = $step->apply($context, $plan);

        self::assertSame(NoticeLevel::Warning, $plan->notices()[0]->level);
        self::assertSame(0, $result->applied);
        self::assertSame([], $this->requests);
    }

    // ---- Finalize ---------------------------------------------------------------------------------------------

    /**
     * @param array{int, string, string} $outcome
     *
     * @return array{Finalize, \ArrayObject<int, array{list<string>, string}>} the step and the commands it ran
     */
    private function finalizer(array $outcome): array
    {
        /** @var \ArrayObject<int, array{list<string>, string}> $calls */
        $calls = new \ArrayObject();
        $step = new Finalize(static function (array $command, string $directory) use ($calls, $outcome): array {
            $calls[] = [$command, $directory];

            return $outcome;
        });

        return [$step, $calls];
    }

    /**
     * @param array{list<string>, string} $call
     *
     * @return array<string, mixed>
     */
    private static function optionsOf(array $call): array
    {
        $options = json_decode($call[0][2], true);
        self::assertIsArray($options);

        return $options;
    }

    public function testFinalizeStartsPrestaShopInAChildProcess(): void
    {
        [$step, $calls] = $this->finalizer([0, "{\"done\":[\"cache cleared\"]}\n", '']);
        $context = $this->context($this->request());

        $result = $step->apply($context, $step->plan($context));

        self::assertCount(1, $calls);
        $call = $calls[0] ?? self::fail('PrestaShop was not started.');
        self::assertSame(PHP_BINARY, $call[0][0]);
        self::assertStringEndsWith('/plugins/prestashop9/resources/finalize.php', $call[0][1]);
        self::assertFileExists($call[0][1]);
        self::assertSame($this->shop->root, $call[1]);
        $options = self::optionsOf($call);
        self::assertSame($this->shop->root, $options['root']);
        self::assertSame('new.test', $options['host']);
        self::assertFalse($options['regenerate'], '.htaccess and robots.txt are only regenerated after a change of the base path.');
        self::assertSame('PrestaShop finished: cache cleared.', $result->notices[0]->message);
    }

    public function testFinalizeRegeneratesFilesAfterAPathChangeExceptRobotsInDevMode(): void
    {
        [$step, $calls] = $this->finalizer([0, '{"done":[]}', '']);

        $step->apply($context = $this->context($this->request(physicalUri: '/shop/')), $step->plan($context));
        $step->apply($context = $this->context($this->request(physicalUri: '/shop/', mode: Mode::Dev)), $step->plan($context));

        $options = array_map(self::optionsOf(...), $calls->getArrayCopy());
        self::assertTrue($options[0]['regenerate']);
        self::assertTrue($options[0]['robots']);
        self::assertTrue($options[1]['regenerate']);
        self::assertFalse($options[1]['robots'], 'In dev mode robots.txt belongs to the search engine block.');
    }

    public function testFinalizeFailureIsOnlyAWarning(): void
    {
        [$step, $calls] = $this->finalizer([1, '', "PHP Fatal error: Class 'Locale' not found\nin /x.php"]);
        $context = $this->context($this->request());

        $result = $step->apply($context, $step->plan($context));

        self::assertCount(1, $calls);
        self::assertSame(NoticeLevel::Warning, $result->notices[0]->level);
        self::assertStringContainsString("PHP Fatal error: Class 'Locale' not found", $result->notices[0]->message);
        self::assertStringContainsString('The migration stays valid', $result->notices[0]->message);
        self::assertStringNotContainsString('in /x.php', $result->notices[0]->message, 'Only the first line is shown.');
    }

    public function testSwitchedOffFinalizeDoesNotStartPrestaShop(): void
    {
        [$step, $calls] = $this->finalizer([0, '', '']);
        $context = $this->context($this->request(finalize: false));

        $step->apply($context, $step->plan($context));

        self::assertCount(0, $calls);
    }
}
