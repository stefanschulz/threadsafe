<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\PrestaShop9;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Database\DatabaseSettings;
use ThreadSafe\Migration\Mode;
use ThreadSafe\Migration\SslMode;
use ThreadSafe\PrestaShop9\RequestBuilder;
use ThreadSafe\Tests\Support\TempDirectory;

final class RequestBuilderTest extends TestCase
{
    use TempDirectory;

    private string $root;

    protected function setUp(): void
    {
        $this->root = (string) realpath($this->tempDirectory());
        mkdir($this->root . '/app/config', 0777, true);
        file_put_contents($this->root . '/app/config/parameters.php', '<?php return ' . var_export(['parameters' => [
            'database_host' => 'file-host', 'database_port' => '3307', 'database_name' => 'file_db',
            'database_user' => 'file_user', 'database_password' => 'file-secret', 'database_prefix' => 'fp_',
        ]], true) . ';');
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function build(array $settings, ?string $environmentPassword = null): \ThreadSafe\Migration\MigrationRequest
    {
        $builder = new RequestBuilder(static fn (string $name): string|false => $name === 'THREADSAFE_DB_PASSWORD' && $environmentPassword !== null ? $environmentPassword : false);

        return $builder->build($settings + ['root' => $this->root]);
    }

    public function testMinimalRequest(): void
    {
        $request = $this->build(['from' => 'Old.Test', 'to' => 'new.test']);

        self::assertSame($this->root, $request->root);
        self::assertCount(1, $request->domains);
        self::assertSame('old.test', $request->domains[0]->from);
        self::assertSame('new.test', $request->domains[0]->to);
        self::assertNull($request->database);
        self::assertSame(Mode::Production, $request->mode);
        self::assertNull($request->ssl);
        self::assertNull($request->physicalUri);
        self::assertFalse($request->replaceBare);
        self::assertSame(2000, $request->chunkSize);
    }

    public function testAllSettings(): void
    {
        $request = $this->build([
            'from' => 'a.test', 'to' => 'b.test', 'domains' => ['c.test=d.test', ['from' => 'e.test', 'to' => 'f.test']],
            'path' => '/shop/', 'ssl' => 'all', 'serverPath' => '/var/www/html', 'replaceBare' => true,
            'skipTables' => ['note', 'log'], 'chunkSize' => '500',
        ]);

        self::assertSame(['c.test', 'e.test', 'a.test'], array_map(static fn ($m): string => $m->from, $request->domains));
        self::assertSame('/shop/', $request->physicalUri);
        self::assertSame(SslMode::All, $request->ssl);
        self::assertSame('/var/www/html', $request->serverPath());
        self::assertTrue($request->replaceBare);
        self::assertSame(['note', 'log'], $request->skipTables);
        self::assertSame(500, $request->chunkSize);
    }

    public function testDevModeSettings(): void
    {
        $request = $this->build(['mode' => 'dev', 'mailCatcher' => 'mailpit:1025', 'devSkip' => ['tracking', 'noindex']]);

        self::assertSame(Mode::Dev, $request->mode);
        self::assertTrue($request->isDevMode());
        self::assertSame('mailpit:1025', (string) $request->mailCatcher);
        self::assertSame(['tracking', 'noindex'], $request->devSkip);
        self::assertNull($request->devRulesFile);
    }

    public function testDevModeWithoutExtras(): void
    {
        $request = $this->build(['mode' => 'dev']);

        self::assertTrue($request->isDevMode());
        self::assertNull($request->mailCatcher);
        self::assertSame([], $request->devSkip);
    }

    public function testRulesDirectoryAndModuleSkips(): void
    {
        $rules = $this->tempDirectory() . '/rules';
        mkdir($rules);
        file_put_contents($rules . '/ps_ex.json', json_encode(['module' => 'ps_ex']));

        $request = $this->build(['mode' => 'dev', 'rulesDir' => $rules, 'devSkip' => ['module:ps_ex', 'mail']]);

        self::assertSame($rules, $request->rulesDir);
        self::assertSame(['module:ps_ex', 'mail'], $request->devSkip);
    }

    public function testSkippingTheRuleOfAnUnknownModuleIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown dev rule "module:ps_ex"');

        $this->build(['mode' => 'dev', 'devSkip' => ['module:ps_ex']]);
    }

    public function testRulesDirectoryMustExist(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('rules directory');

        $this->build(['rulesDir' => $this->root . '/missing']);
    }

    public function testProductionIsNotDevMode(): void
    {
        self::assertFalse($this->build([])->isDevMode());
    }

    public function testNoDomainMeansRestoreInPlace(): void
    {
        self::assertTrue($this->build([])->isRestoreInPlace());
    }

    public function testNewDomainEqualToTheOldOneIsNotAMapping(): void
    {
        $builder = new RequestBuilder();

        $request = $builder->build(['root' => $this->root, 'from' => 'same.test', 'to' => 'SAME.test']);

        self::assertTrue($request->isRestoreInPlace());
        self::assertStringContainsString('already uses "SAME.test"', $builder->notes()[0]);
    }

    /**
     * @param array<string, mixed> $settings
     */
    #[DataProvider('invalidSettings')]
    public function testRejectsInvalidSettings(array $settings, string $messagePart): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($messagePart);

        (new RequestBuilder())->build($settings + ['root' => $this->root]);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidSettings(): iterable
    {
        yield 'unknown setting' => [['colour' => 'red'], 'Unknown setting "colour"'];
        yield 'from without to' => [['from' => 'a.test'], '--from needs --to'];
        yield 'mail catcher in production' => [['mailCatcher' => 'mailpit:1025'], 'need --mode=dev'];
        yield 'dev skip in production' => [['devSkip' => ['mail']], 'need --mode=dev'];
        yield 'dev rules in production' => [['devRules' => '/x.json'], 'need --mode=dev'];
        yield 'unknown dev rule' => [['mode' => 'dev', 'devSkip' => ['teleport']], 'Unknown dev rule "teleport"'];
        yield 'dev skip not a list of ids' => [['mode' => 'dev', 'devSkip' => [1]], 'devSkip must be a list'];
        yield 'invalid mail catcher' => [['mode' => 'dev', 'mailCatcher' => 'mail:port'], 'HOST or HOST:PORT'];
        yield 'missing dev rules file' => [['mode' => 'dev', 'devRules' => '/nope/rules.json'], 'cannot be read'];
        yield 'invalid mode' => [['mode' => 'staging'], 'must be "production" or "dev"'];
        yield 'invalid ssl' => [['ssl' => 'always'], 'SSL mode'];
        yield 'invalid domain' => [['from' => 'https://a.test', 'to' => 'b.test'], 'Invalid "from" domain'];
        yield 'mapping without arrow' => [['domains' => ['a.test']], 'old=new'];
        yield 'mapping with two arrows' => [['domains' => ['a=b=c']], 'old=new'];
        yield 'invalid path' => [['path' => 'shop'], 'start and end with'];
        yield 'invalid chunk size' => [['chunkSize' => 'many'], 'chunkSize must be a number'];
        yield 'chunk size out of range' => [['chunkSize' => 0], 'between 1 and 100000'];
        yield 'skip tables not a list of names' => [['skipTables' => [1]], 'skipTables'];
        yield 'replace bare not boolean' => [['replaceBare' => 'yes'], 'replaceBare'];
        yield 'empty string' => [['path' => ''], 'non-empty string'];
        yield 'unknown database setting' => [['database' => ['socket' => 'x']], 'Unknown database setting "socket"'];
        yield 'database not an object' => [['database' => 'x'], 'must be an object'];
    }

    public function testRootMustBeAnExistingDirectory(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not a directory');

        (new RequestBuilder())->build(['root' => $this->root . '/missing']);
    }

    public function testRootIsRequired(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new RequestBuilder())->build([]);
    }

    public function testDatabaseSettingsOverrideOnlyWhatIsGiven(): void
    {
        $request = $this->build(['database' => ['host' => 'new-host', 'name' => 'new_db']]);

        self::assertEquals(new DatabaseSettings('new-host', 3307, 'new_db', 'file_user', 'file-secret', 'fp_'), $request->database);
    }

    public function testPasswordComesFromAFile(): void
    {
        $file = $this->tempDirectory() . '/pw';
        file_put_contents($file, "from-file\n");

        $request = $this->build(['database' => ['passwordFile' => $file]]);

        self::assertSame('from-file', $request->database?->password, 'The trailing newline is not part of the password.');
        self::assertNotSame('file-secret', $request->database->password, 'The password in parameters.php is not used.');
    }

    public function testPasswordComesFromTheEnvironment(): void
    {
        $request = $this->build(['database' => ['host' => 'h']], environmentPassword: 'from-env');

        self::assertSame('from-env', $request->database?->password);
    }

    public function testPasswordFileBeatsTheEnvironmentAndAPlainSetting(): void
    {
        $file = $this->tempDirectory() . '/pw';
        file_put_contents($file, 'from-file');

        $request = $this->build(['database' => ['passwordFile' => $file, 'password' => 'plain']], environmentPassword: 'from-env');

        self::assertSame('from-file', $request->database?->password);
    }

    public function testMissingPasswordFileIsAnError(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('password file cannot be read');

        $this->build(['database' => ['passwordFile' => $this->root . '/nope']]);
    }

    public function testIncompleteDatabaseWithoutParametersFileIsAnError(): void
    {
        unlink($this->root . '/app/config/parameters.php');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('database name is not given');

        $this->build(['database' => ['host' => 'h', 'user' => 'u', 'prefix' => 'p_'], ], environmentPassword: 'secret');
    }

    public function testCompleteDatabaseWorksWithoutParametersFile(): void
    {
        unlink($this->root . '/app/config/parameters.php');

        $request = $this->build(['database' => ['host' => 'h', 'port' => 3306, 'name' => 'n', 'user' => 'u', 'prefix' => 'p_', 'password' => 'secret']]);

        self::assertEquals(new DatabaseSettings('h', 3306, 'n', 'u', 'secret', 'p_'), $request->database);
    }
}
