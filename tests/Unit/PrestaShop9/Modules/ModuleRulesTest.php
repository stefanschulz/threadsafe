<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\PrestaShop9\Modules;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThreadSafe\PrestaShop9\Modules\FileRule;
use ThreadSafe\PrestaShop9\Modules\ModuleRules;
use ThreadSafe\Tests\Support\TempDirectory;

final class ModuleRulesTest extends TestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function write(string $directory, string $module, array $rule): void
    {
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        file_put_contents($directory . '/' . $module . '.json', json_encode(['module' => $module] + $rule, JSON_THROW_ON_ERROR));
    }

    /** @return array{string, string} an empty shipped directory and the user's directory */
    private function directories(): array
    {
        $base = $this->tempDirectory();

        return [$base . '/shipped', $base . '/user'];
    }

    public function testNoRulesAtAll(): void
    {
        [$shipped, $user] = $this->directories();
        mkdir($shipped);
        mkdir($user);

        $rules = ModuleRules::load($user, $shipped);

        self::assertTrue($rules->isEmpty());
        self::assertSame([], $rules->skipTables());
        self::assertSame([], $rules->devRules());
    }

    public function testShippedSetAndDefaultDirectoryLoad(): void
    {
        // The shipped set and, without --rules-dir, an absent data/rules directory must both be fine.
        self::assertInstanceOf(ModuleRules::class, ModuleRules::load());
    }

    public function testUserRulesReplaceShippedRulesOfTheSameModule(): void
    {
        [$shipped, $user] = $this->directories();
        $this->write($shipped, 'ps_a', ['skipTables' => ['a_log']]);
        $this->write($shipped, 'ps_b', ['skipTables' => ['b_log']]);
        $this->write($user, 'ps_b', ['skipTables' => ['own_log']]);
        $this->write($user, 'ps_c', []);

        $rules = ModuleRules::load($user, $shipped);

        self::assertSame(['ps_a', 'ps_b', 'ps_c'], $rules->names());
        self::assertEqualsCanonicalizing(['a_log', 'own_log'], $rules->skipTables(), 'A user rule replaces the shipped rule as a whole.');
    }

    public function testMissingUserDirectoryIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist');

        ModuleRules::load('/nope/rules');
    }

    public function testAggregatesWhatTheRulesSay(): void
    {
        [$shipped, $user] = $this->directories();
        mkdir($shipped);
        $this->write($user, 'ps_example', [
            'description' => 'Example',
            'domainColumns' => [
                ['table' => 'example_option', 'column' => 'value', 'form' => 'serialized'],
                ['table' => 'example_link', 'column' => 'url', 'bare' => true],
            ],
            'configuration' => [
                ['key' => 'EXAMPLE_CALLBACK', 'migrate' => 'keep', 'dev' => 'clear'],
                ['key' => 'EXAMPLE_HOST', 'migrate' => 'replace-bare'],
                ['key' => 'EXAMPLE_TOKEN', 'dev' => 'remove'],
                ['key' => 'EXAMPLE_PLAIN'],
            ],
            'skipTables' => ['example_log'],
            'files' => [['glob' => 'modules/ps_example/cache/*', 'action' => 'delete'], ['glob' => 'modules/ps_example/*.xml', 'action' => 'replace']],
            'dev' => ['deactivate' => true, 'emptyTables' => ['example_queue']],
        ]);

        $rules = ModuleRules::load($user, $shipped);

        $columns = $rules->columnsOf('example_option');
        self::assertSame('serialized', $columns['value']->form);
        self::assertTrue($rules->columnsOf('example_link')['url']->bare);
        self::assertSame([], $rules->columnsOf('other'));
        self::assertEqualsCanonicalizing(['example_option', 'example_link', 'example_log'], $rules->knownTables());
        self::assertSame(['EXAMPLE_CALLBACK'], $rules->configurationKeys('keep'));
        self::assertSame(['EXAMPLE_HOST'], $rules->configurationKeys('replace-bare'));
        self::assertSame(['EXAMPLE_TOKEN', 'EXAMPLE_PLAIN'], $rules->configurationKeys('replace'));
        self::assertSame(['modules/ps_example/cache/*'], array_map(static fn (FileRule $f): string => $f->glob, $rules->files(FileRule::DELETE)));
        self::assertSame(['modules/ps_example/*.xml'], array_map(static fn (FileRule $f): string => $f->glob, $rules->files(FileRule::REPLACE)));

        $dev = $rules->devRules();
        self::assertCount(1, $dev);
        self::assertSame('module:ps_example', $dev[0]->id);
        self::assertSame(['EXAMPLE_CALLBACK'], $dev[0]->clear);
        self::assertSame(['EXAMPLE\_TOKEN'], $dev[0]->remove, 'A removed key is matched literally (LIKE wildcards escaped).');
        self::assertSame(['example_queue'], $dev[0]->emptyTables);
        self::assertSame(['ps_example'], $dev[0]->deactivateModules);
    }

    public function testRuleWithoutDevActionsHasNoDevRule(): void
    {
        [$shipped, $user] = $this->directories();
        mkdir($shipped);
        $this->write($user, 'ps_quiet', ['skipTables' => ['quiet_log']]);

        self::assertSame([], ModuleRules::load($user, $shipped)->devRules());
    }

    /**
     * @param array<string, mixed> $rule
     */
    #[DataProvider('invalidRules')]
    public function testInvalidRulesAreRefused(array $rule, string $messagePart): void
    {
        [$shipped, $user] = $this->directories();
        mkdir($shipped);
        $this->write($user, 'ps_bad', $rule);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($messagePart);

        ModuleRules::load($user, $shipped);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidRules(): iterable
    {
        yield 'unknown key' => [['colour' => 'red'], 'unknown key "colour"'];
        yield 'handler' => [['handler' => 'Evil\\Code'], 'handlers (PHP code) are not supported'];
        yield 'bad form' => [['domainColumns' => [['table' => 't', 'column' => 'c', 'form' => 'xml']]], '"form" must be'];
        yield 'bare is not a boolean' => [['domainColumns' => [['table' => 't', 'column' => 'c', 'bare' => 'yes']]], '"bare" must be'];
        yield 'table with a prefix placeholder' => [['domainColumns' => [['table' => 'ps-t', 'column' => 'c']]], '"table" must be a name'];
        yield 'domain column without column' => [['domainColumns' => [['table' => 't']]], '"column" must be a name'];
        yield 'domain column with unknown key' => [['domainColumns' => [['table' => 't', 'column' => 'c', 'x' => 1]]], 'unknown key "x"'];
        yield 'domain columns not a list' => [['domainColumns' => ['table' => 't']], '"domainColumns" must be a list'];
        yield 'bad migrate' => [['configuration' => [['key' => 'K', 'migrate' => 'delete']]], 'configuration "migrate"'];
        yield 'bad dev' => [['configuration' => [['key' => 'K', 'dev' => 'explode']]], 'configuration "migrate"'];
        yield 'configuration key with a wildcard' => [['configuration' => [['key' => 'K%']]], '"key" must be a name'];
        yield 'skip table with a quote' => [['skipTables' => ["a'b"]]  , '"skipTables" must be'];
        yield 'file without action' => [['files' => [['glob' => 'modules/ps_bad/x']]], 'needs "glob" and an "action"'];
        yield 'file with unknown action' => [['files' => [['glob' => 'modules/ps_bad/x', 'action' => 'run']]], 'needs "glob" and an "action"'];
        yield 'absolute glob' => [['files' => [['glob' => '/etc/*', 'action' => 'replace']]], 'must be relative to the shop root'];
        yield 'glob leaving the shop' => [['files' => [['glob' => 'modules/../../etc/passwd', 'action' => 'replace']]], 'must be relative to the shop root'];
        yield 'glob with a brace' => [['files' => [['glob' => 'modules/{a,b}', 'action' => 'replace']]], 'must be relative to the shop root'];
        yield 'delete in the shop root' => [['files' => [['glob' => '*.php', 'action' => 'delete']]], 'may only be deleted below'];
        yield 'delete another module' => [['files' => [['glob' => 'modules/ps_other/*', 'action' => 'delete']]], 'may only be deleted below modules/ps_bad/'];
        yield 'delete the module directory itself' => [['files' => [['glob' => 'modules/ps_bad/', 'action' => 'delete']]], 'may only be deleted below'];
        yield 'delete uploads' => [['files' => [['glob' => 'upload/*', 'action' => 'delete']]], 'may only be deleted below'];
        yield 'dev is not an object' => [['dev' => true], '"dev" must be an object'];
        yield 'dev unknown key' => [['dev' => ['format' => 'c']], 'unknown key "format"'];
        yield 'dev deactivate not a boolean' => [['dev' => ['deactivate' => 1]], '"dev.deactivate"'];
        yield 'dev empty table invalid' => [['dev' => ['emptyTables' => ['a b']]], '"dev.emptyTables"'];
    }

    public function testDeleteIsAllowedWhereThingsAreRebuilt(): void
    {
        [$shipped, $user] = $this->directories();
        mkdir($shipped);
        $this->write($user, 'ps_ok', ['files' => [
            ['glob' => 'modules/ps_ok/cache/*', 'action' => 'delete'],
            ['glob' => 'var/cache/ps_ok_*', 'action' => 'delete'],
            ['glob' => 'var/logs/ps_ok*.log', 'action' => 'delete'],
            ['glob' => 'cache/ps_ok/*', 'action' => 'delete'],
            ['glob' => 'img/tmp/ps_ok_*', 'action' => 'delete'],
        ]]);

        self::assertCount(5, ModuleRules::load($user, $shipped)->files(FileRule::DELETE));
    }

    public function testFileNameMustMatchTheModule(): void
    {
        [$shipped, $user] = $this->directories();
        mkdir($shipped);
        mkdir($user);
        file_put_contents($user . '/ps_one.json', json_encode(['module' => 'ps_two']));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be named "ps_two.json"');

        ModuleRules::load($user, $shipped);
    }

    public function testBrokenJsonAndWrongShapeAreRefused(): void
    {
        [$shipped, $user] = $this->directories();
        mkdir($shipped);
        mkdir($user);
        file_put_contents($user . '/ps_x.json', '{nope');
        try {
            ModuleRules::load($user, $shipped);
            self::fail('Broken JSON must be refused.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('not valid JSON', $e->getMessage());
        }

        file_put_contents($user . '/ps_x.json', '[1,2]');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a JSON object');

        ModuleRules::load($user, $shipped);
    }

    public function testTheSchemaDescribesTheKeysTheLoaderAccepts(): void
    {
        $schema = json_decode((string) file_get_contents(dirname(__DIR__, 4) . '/resources/prestashop9/module-rule.schema.json'), true, 16, JSON_THROW_ON_ERROR);

        self::assertIsArray($schema);
        self::assertEqualsCanonicalizing(ModuleRules::KEYS, array_keys($schema['properties']));
        self::assertFalse($schema['additionalProperties']);
    }

    public function testTheFixtureRuleOfTheIntegrationTestsIsValid(): void
    {
        [$shipped] = $this->directories();
        mkdir($shipped);

        $rules = ModuleRules::load(dirname(__DIR__, 3) . '/fixtures/rules', $shipped);

        self::assertSame(['threadsafe_fixture'], $rules->names());
        self::assertSame(['THREADSAFE_FIXTURE_WEBHOOK'], $rules->configurationKeys('keep'));
    }
}
