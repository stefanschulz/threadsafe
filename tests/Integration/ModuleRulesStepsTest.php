<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Integration;

use ThreadSafe\Core\Context;
use ThreadSafe\Core\Exception\GuardFailed;
use ThreadSafe\Core\FileJournal;
use ThreadSafe\Core\NoticeLevel;
use ThreadSafe\Core\NullJournal;
use ThreadSafe\Core\Plan;
use ThreadSafe\Core\Step;
use ThreadSafe\Migration\MigrationRequest;
use ThreadSafe\Migration\Mode;
use ThreadSafe\PrestaShop9\Steps\ApplyDevMode;
use ThreadSafe\PrestaShop9\Steps\DiscardCaches;
use ThreadSafe\PrestaShop9\Steps\ReadSourceState;
use ThreadSafe\PrestaShop9\Steps\ReplaceContentDomains;
use ThreadSafe\PrestaShop9\Steps\RewriteHostBoundFiles;
use ThreadSafe\Replace\DomainMapping;
use ThreadSafe\Tests\Support\ScratchShop;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * Module rules in the steps that use them (3, 5, 7, 9), against a small fake shop with an installed module "ps_ex".
 */
final class ModuleRulesStepsTest extends IntegrationTestCase
{
    use TempDirectory;

    private ScratchShop $shop;

    private string $rulesDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = ScratchShop::create('old.test');
        $this->rulesDir = $this->tempDirectory() . '/rules';
        mkdir($this->rulesDir);

        $engine = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci';
        foreach ([
            "CREATE TABLE ps_module (id_module INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(64) NOT NULL, active TINYINT(1) NOT NULL DEFAULT 0) $engine",
            "CREATE TABLE ps_module_shop (id_module INT UNSIGNED NOT NULL, id_shop INT UNSIGNED NOT NULL, PRIMARY KEY (id_module, id_shop)) $engine",
            "CREATE TABLE ps_ex_link (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, host VARCHAR(255) NOT NULL, url VARCHAR(255) NULL) $engine",
            "CREATE TABLE ps_ex_opt (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, value LONGTEXT NULL) $engine",
            "CREATE TABLE ps_ex_log (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, line TEXT NULL) $engine",
            "CREATE TABLE ps_ex_queue (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, job TEXT NULL) $engine",
            "CREATE TABLE ps_unknown_mod (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, link TEXT NULL) $engine",
        ] as $sql) {
            $this->shop->pdo()->exec($sql);
        }
        foreach (['ps_ex', 'ps_other'] as $module) {
            $this->shop->exec('INSERT INTO ps_module (name, active) VALUES (?, 1)', [$module]);
            $this->shop->exec('INSERT INTO ps_module_shop (id_module, id_shop) SELECT id_module, 1 FROM ps_module WHERE name = ?', [$module]);
        }

        $this->shop->exec("INSERT INTO ps_ex_link (host, url) VALUES ('old.test', 'http://old.test/x')");
        $this->shop->exec('INSERT INTO ps_ex_opt (value) VALUES (?)', [serialize(['url' => 'http://old.test/a', 'list' => ['http://old.test/b']])]);
        $this->shop->exec("INSERT INTO ps_ex_log (line) VALUES ('GET http://old.test/log')");
        $this->shop->exec("INSERT INTO ps_ex_queue (job) VALUES ('job for http://old.test/q')");
        $this->shop->exec("INSERT INTO ps_unknown_mod (link) VALUES ('see http://old.test/unknown')");
        $this->shop->exec("INSERT INTO ps_cms_lang (id_cms, id_lang, content) VALUES (1, 1, 'core http://old.test/page')");
        foreach ([
            'EX_URL' => 'http://old.test/url', 'EX_KEEP' => 'http://old.test/callback', 'EX_BARE' => 'old.test',
            'EX_SECRET' => 'secret-value-123456', 'EX_TOKEN_ROW' => 'token-row-value-1', 'OTHER_KEEP' => 'http://old.test/other',
        ] as $name => $value) {
            $this->shop->exec('INSERT INTO ps_configuration (name, value, date_add, date_upd) VALUES (?, ?, NOW(), NOW())', [$name, $value]);
        }

        $this->shop->writeFile('modules/ps_ex/cache/one.tmp', 'x');
        $this->shop->writeFile('modules/ps_ex/cache/sub/two.tmp', 'x');
        $this->shop->writeFile('modules/ps_ex/cache/index.php', '<?php // protection');
        $this->shop->writeFile('modules/ps_ex/keep.php', '<?php // stays');
        $this->shop->writeFile('modules/ps_ex/feed.xml', '<loc>http://old.test/feed</loc>');
        $this->shop->writeFile('modules/ps_ex/other.txt', 'http://old.test/not-matched');
    }

    protected function tearDown(): void
    {
        $this->shop->destroy();
        $this->cleanUpTempDirectories();
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function rule(string $module, array $rule): void
    {
        file_put_contents($this->rulesDir . '/' . $module . '.json', json_encode(['module' => $module] + $rule, JSON_THROW_ON_ERROR));
    }

    private function exampleRule(): void
    {
        $this->rule('ps_ex', [
            'domainColumns' => [
                ['table' => 'ex_link', 'column' => 'host', 'bare' => true],
                ['table' => 'ex_opt', 'column' => 'value', 'form' => 'serialized'],
            ],
            'configuration' => [
                ['key' => 'EX_KEEP', 'migrate' => 'keep', 'dev' => 'clear'],
                ['key' => 'EX_BARE', 'migrate' => 'replace-bare'],
                ['key' => 'EX_SECRET', 'dev' => 'clear'],
                ['key' => 'EX_TOKEN_ROW', 'dev' => 'remove'],
            ],
            'skipTables' => ['ex_log'],
            'files' => [
                ['glob' => 'modules/ps_ex/cache/*', 'action' => 'delete'],
                ['glob' => 'modules/ps_ex/*.xml', 'action' => 'replace'],
            ],
            'dev' => ['deactivate' => true, 'emptyTables' => ['ex_queue']],
        ]);
        // A rule of a module that is not installed must change nothing.
        $this->rule('ps_absent', ['skipTables' => ['unknown_mod'], 'configuration' => [['key' => 'OTHER_KEEP', 'migrate' => 'keep']], 'dev' => ['deactivate' => true]]);
    }

    private function request(Mode $mode = Mode::Production, bool $withRules = true, string ...$skip): MigrationRequest
    {
        return new MigrationRequest(
            $this->shop->root,
            [new DomainMapping('old.test', 'new.test')],
            mode: $mode,
            devSkip: array_values($skip),
            rulesDir: $withRules ? $this->rulesDir : null,
        );
    }

    private function context(MigrationRequest $request, ?FileJournal $journal = null): Context
    {
        $context = $this->shop->context($request, $journal ?? new NullJournal(), $journal === null);
        (new ReadSourceState())->plan($context);

        return $context;
    }

    private function apply(Step $step, MigrationRequest $request): int
    {
        $context = $this->context($request, new FileJournal($this->tempDirectory()));

        return $step->apply($context, $step->plan($context))->applied;
    }

    /** @return list<string> */
    private static function warnings(Plan $plan): array
    {
        return array_values(array_map(static fn ($n): string => $n->message, array_filter($plan->notices(), static fn ($n): bool => $n->level === NoticeLevel::Warning)));
    }

    private function value(string $sql): string
    {
        return $this->shop->value($sql);
    }

    public function testReadSourceStateNamesTheRulesInUse(): void
    {
        $this->exampleRule();

        $plan = (new ReadSourceState())->plan($this->shop->context($this->request(), new NullJournal(), true));

        $messages = array_map(static fn ($n): string => $n->message, $plan->notices());
        self::assertContains('Module rules in use: ps_ex.', $messages, 'Only the rule of the installed module is in use.');
    }

    public function testAnInvalidRuleStopsTheRunBeforeAnythingIsPlanned(): void
    {
        file_put_contents($this->rulesDir . '/ps_ex.json', json_encode(['module' => 'ps_ex', 'files' => [['glob' => 'index.php', 'action' => 'delete']]]));

        $this->expectException(GuardFailed::class);
        $this->expectExceptionMessage('may only be deleted below');

        (new ReadSourceState())->plan($this->shop->context($this->request(), new NullJournal(), true));
    }

    public function testNoRulesMeansTheGenericEngineAsBefore(): void
    {
        $this->apply(new ReplaceContentDomains(), $this->request(withRules: false));

        self::assertSame('old.test', $this->value('SELECT host FROM ps_ex_link'), 'A bare name is not replaced without a rule.');
        self::assertSame('http://new.test/callback', $this->value("SELECT value FROM ps_configuration WHERE name = 'EX_KEEP'"));
        self::assertStringContainsString('new.test', $this->value('SELECT line FROM ps_ex_log'));
    }

    public function testRulesRefineTheContentReplacement(): void
    {
        $this->exampleRule();

        $this->apply(new ReplaceContentDomains(), $this->request());

        self::assertSame('new.test', $this->value('SELECT host FROM ps_ex_link'), 'bare: true replaces the bare host name.');
        self::assertSame('http://new.test/x', $this->value('SELECT url FROM ps_ex_link'), 'A column without a rule is handled as usual.');
        $option = unserialize($this->value('SELECT value FROM ps_ex_opt'));
        self::assertSame(['url' => 'http://new.test/a', 'list' => ['http://new.test/b']], $option, 'form: serialized keeps the value valid.');
        self::assertSame('http://old.test/callback', $this->value("SELECT value FROM ps_configuration WHERE name = 'EX_KEEP'"), 'migrate: keep.');
        self::assertSame('new.test', $this->value("SELECT value FROM ps_configuration WHERE name = 'EX_BARE'"), 'migrate: replace-bare.');
        self::assertSame('http://new.test/url', $this->value("SELECT value FROM ps_configuration WHERE name = 'EX_URL'"), 'A key without a rule is replaced.');
        self::assertSame('GET http://old.test/log', $this->value('SELECT line FROM ps_ex_log'), 'skipTables.');
        self::assertSame('see http://new.test/unknown', $this->value('SELECT link FROM ps_unknown_mod'), 'The rule of an absent module skips nothing.');
        self::assertSame('http://new.test/other', $this->value("SELECT value FROM ps_configuration WHERE name = 'OTHER_KEEP'"), 'The rule of an absent module keeps nothing.');
    }

    public function testPlanChangesNothingAndASecondRunFindsNothing(): void
    {
        $this->exampleRule();
        $plan = (new ReplaceContentDomains())->plan($this->context($this->request()));
        self::assertTrue($plan->hasChanges());
        self::assertSame('old.test', $this->value('SELECT host FROM ps_ex_link'));

        $this->apply(new ReplaceContentDomains(), $this->request());

        self::assertFalse((new ReplaceContentDomains())->plan($this->context($this->request()))->hasChanges());
    }

    public function testTablesNoRuleKnowsAreReported(): void
    {
        $this->exampleRule();

        $warnings = self::warnings((new ReplaceContentDomains())->plan($this->context($this->request())));

        $text = implode("\n", $warnings);
        self::assertStringContainsString('No module rule for table "unknown_mod"', $text);
        self::assertStringNotContainsString('"cms_lang"', $text, 'A table of a clean installation needs no rule.');
        self::assertStringNotContainsString('"ex_link"', $text, 'A table a rule describes is known.');
        self::assertStringNotContainsString('"ex_opt"', $text);
    }

    public function testModuleFilesAreDeletedAndReplaced(): void
    {
        $this->exampleRule();
        $plan = (new DiscardCaches())->plan($this->context($this->request()));
        $targets = array_map(static fn ($c): string => $c->target, $plan->changes());
        self::assertContains('files modules/ps_ex/cache/*', $targets);
        self::assertFileExists($this->shop->root . '/modules/ps_ex/cache/one.tmp', 'A plan changes nothing.');

        $this->apply(new DiscardCaches(), $this->request());

        self::assertFileDoesNotExist($this->shop->root . '/modules/ps_ex/cache/one.tmp');
        self::assertFileDoesNotExist($this->shop->root . '/modules/ps_ex/cache/sub/two.tmp');
        self::assertFileExists($this->shop->root . '/modules/ps_ex/cache/index.php', 'Protection files stay.');
        self::assertFileExists($this->shop->root . '/modules/ps_ex/keep.php', 'Only what the glob names is removed.');

        $this->apply(new RewriteHostBoundFiles(), $this->request());

        self::assertSame('<loc>http://new.test/feed</loc>', file_get_contents($this->shop->root . '/modules/ps_ex/feed.xml'));
        self::assertSame('http://old.test/not-matched', file_get_contents($this->shop->root . '/modules/ps_ex/other.txt'), 'Only the globbed files are rewritten.');
    }

    public function testRuleWithoutInstalledModuleDeletesNothing(): void
    {
        $this->rule('ps_gone', ['files' => [['glob' => 'modules/ps_gone/cache/*', 'action' => 'delete']]]);
        $this->shop->writeFile('modules/ps_gone/cache/x.tmp', 'x');

        $this->apply(new DiscardCaches(), $this->request());

        self::assertFileExists($this->shop->root . '/modules/ps_gone/cache/x.tmp');
    }

    public function testDevModeAppliesTheModuleRules(): void
    {
        $this->exampleRule();
        $this->shop->exec("INSERT INTO ps_ex_queue (job) VALUES ('second')");

        $this->apply(new ApplyDevMode(), $this->request(Mode::Dev));

        self::assertSame('', $this->value("SELECT value FROM ps_configuration WHERE name = 'EX_KEEP'"));
        self::assertSame('', $this->value("SELECT value FROM ps_configuration WHERE name = 'EX_SECRET'"));
        self::assertSame('0', $this->value("SELECT COUNT(*) FROM ps_configuration WHERE name = 'EX_TOKEN_ROW'"));
        self::assertSame('0', $this->value('SELECT COUNT(*) FROM ps_ex_queue'));
        self::assertSame('0', $this->value("SELECT active FROM ps_module WHERE name = 'ps_ex'"));
        self::assertSame('0', $this->value("SELECT COUNT(*) FROM ps_module_shop ms JOIN ps_module m USING (id_module) WHERE m.name = 'ps_ex'"));
        self::assertSame('1', $this->value("SELECT active FROM ps_module WHERE name = 'ps_other'"), 'The rule of another, absent module does not reach it.');
        self::assertSame('http://old.test/other', $this->value("SELECT value FROM ps_configuration WHERE name = 'OTHER_KEEP'"));
    }

    public function testModuleDevRuleCanBeSkippedByName(): void
    {
        $this->exampleRule();

        $this->apply(new ApplyDevMode(), $this->request(Mode::Dev, true, 'module:ps_ex'));

        self::assertSame('1', $this->value("SELECT active FROM ps_module WHERE name = 'ps_ex'"));
        self::assertSame('secret-value-123456', $this->value("SELECT value FROM ps_configuration WHERE name = 'EX_SECRET'"));
    }

    public function testModuleRulesDoNothingInProductionMode(): void
    {
        $this->exampleRule();

        self::assertFalse((new ApplyDevMode())->plan($this->context($this->request()))->hasChanges());
    }

    public function testUnknownModuleSkipIsRefused(): void
    {
        $this->exampleRule();

        $this->expectException(GuardFailed::class);
        $this->expectExceptionMessage('Unknown dev rule "module:ps_nothing"');

        (new ApplyDevMode())->plan($this->context($this->request(Mode::Dev, true, 'module:ps_nothing')));
    }
}
