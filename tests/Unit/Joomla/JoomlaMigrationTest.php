<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Joomla;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Core\Context;
use ThreadSafe\Core\Exception\GuardFailed;
use ThreadSafe\Core\Exception\SelfTestFailed;
use ThreadSafe\Core\NullJournal;
use ThreadSafe\Core\NullLogger;
use ThreadSafe\Database\DatabaseSettings;
use ThreadSafe\Database\ServerInfo;
use ThreadSafe\Http\HttpClient;
use ThreadSafe\Joomla\Installation;
use ThreadSafe\Joomla\RequestBuilder;
use ThreadSafe\Joomla\Steps\ReadJoomlaState;
use ThreadSafe\Joomla\Steps\RewriteHtaccess;
use ThreadSafe\Joomla\Steps\SelfTest;
use ThreadSafe\Migration\MigrationRequest;
use ThreadSafe\Replace\DomainMapping;
use ThreadSafe\Tests\Support\JoomlaSite;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * The parts of the Joomla adaptation that need no database: the request, the guards of the first step, the `.htaccess`, the self-test.
 */
final class JoomlaMigrationTest extends TestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    private function root(bool $htaccess = false): string
    {
        $root = (string) realpath($this->tempDirectory());
        JoomlaSite::build($root, $htaccess);

        return $root;
    }

    // ------------------------------------------------------------------------------------------------- the request

    public function testTheRequestTakesTheBasePathTheDomainsAndTheDatabaseFromTheConfiguration(): void
    {
        $root = $this->root();

        $request = (new RequestBuilder())->build(['root' => $root, 'from' => 'old.example.org', 'to' => 'new.example.org', 'path' => '/sub/', 'database' => ['password' => 'other-password']]);

        self::assertSame('/sub/', $request->physicalUri);
        self::assertCount(1, $request->domains);
        self::assertSame('old.example.org', $request->domains[0]->from);
        self::assertEquals(new DatabaseSettings('db', 3306, 'joomla_test', 'joomla', 'other-password', 'joom_'), $request->database);
    }

    public function testTheTablesThatAreRebuiltAreSkippedByTheContentReplacementAndTheUsersOwnAreKept(): void
    {
        $request = (new RequestBuilder())->build(['root' => $this->root(), 'skipTables' => ['my_cache']]);

        self::assertContains('my_cache', $request->skipTables);
        foreach (['session', 'user_keys', 'updates', 'finder_links', 'finder_terms', 'finder_tokens_aggregate'] as $table) {
            self::assertContains($table, $request->skipTables, $table);
        }
        self::assertNotContains('content', $request->skipTables);
        self::assertNotContains('finder_taxonomy', $request->skipTables);
    }

    public function testANewDomainWithoutAnOldOneIsRefusedBecauseJoomlaDoesNotKnowItsOwnAddress(): void
    {
        $this->expectException(GuardFailed::class);
        $this->expectExceptionMessage('give the old domain with --from');

        (new RequestBuilder())->build(['root' => $this->root(), 'to' => 'new.example.org']);
    }

    public function testABasePathMustStartAndEndWithASlash(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new RequestBuilder())->build(['root' => $this->root(), 'path' => 'sub']);
    }

    // ------------------------------------------------------------------------------------------------- the first step

    private function planOf(string $root): void
    {
        $context = new Context(new MigrationRequest($root), new NullJournal(), new NullLogger(), true);
        (new ReadJoomlaState())->plan($context);
    }

    public function testADirectoryThatIsNotJoomlaStopsTheRunBeforeAnythingElse(): void
    {
        $this->expectException(GuardFailed::class);
        $this->expectExceptionMessage('does not look like a Joomla 5 or 6 installation');

        $this->planOf((string) realpath($this->tempDirectory()));
    }

    public function testAJoomla4StopsTheRun(): void
    {
        $root = $this->root();
        file_put_contents($root . '/libraries/src/Version.php', str_replace('MAJOR_VERSION = 6', 'MAJOR_VERSION = 4', (string) file_get_contents($root . '/libraries/src/Version.php')));

        $this->expectException(GuardFailed::class);
        $this->expectExceptionMessage('Only Joomla 5 and 6 are supported, found version 4.2.0');

        $this->planOf($root);
    }

    public function testAnUninstalledJoomlaStopsTheRun(): void
    {
        $root = $this->root();
        unlink($root . '/configuration.php');

        $this->expectException(GuardFailed::class);
        $this->expectExceptionMessage('has no configuration.php');

        $this->planOf($root);
    }

    public function testADatabaseThatCannotBeUsedStopsTheRunWithoutItsPassword(): void
    {
        $root = $this->root();
        file_put_contents($root . '/configuration.php', str_replace("'mysqli'", "'pgsql'", (string) file_get_contents($root . '/configuration.php')));

        try {
            $this->planOf($root);
            self::fail('PostgreSQL is not supported');
        } catch (GuardFailed $e) {
            self::assertStringContainsString('pgsql', $e->getMessage());
            self::assertStringNotContainsString('test-db-password', $e->getMessage());
        }
    }

    public function testJoomla6WantsADatabaseServerThatIsNewEnough(): void
    {
        self::assertNull(Installation::databaseProblem(6, new ServerInfo(ServerInfo::MARIADB, '10.11.8', '10.11.8-MariaDB')));
        self::assertNull(Installation::databaseProblem(6, new ServerInfo(ServerInfo::MYSQL, '8.4.2', '8.4.2')));
        self::assertNull(Installation::databaseProblem(5, new ServerInfo(ServerInfo::MARIADB, '10.2.0', '10.2.0-MariaDB')), 'Joomla 5 is content with what ThreadSafe needs');
        self::assertStringContainsString('MariaDB 10.4.0', (string) Installation::databaseProblem(6, new ServerInfo(ServerInfo::MARIADB, '10.3.39', '10.3.39-MariaDB')));
        self::assertStringContainsString('MySQL 8.0.13', (string) Installation::databaseProblem(6, new ServerInfo(ServerInfo::MYSQL, '5.7.44', '5.7.44')));
        self::assertNull(Installation::databaseProblem(6, new ServerInfo(ServerInfo::UNKNOWN, '', 'something')));
    }

    // ------------------------------------------------------------------------------------------------- .htaccess

    public function testTheHtaccessFollowsTheBasePathOfTheRequest(): void
    {
        $root = $this->root(true);
        $original = (string) file_get_contents($root . '/.htaccess');
        $context = JoomlaSite::context($root, path: '/sub/');
        $step = new RewriteHtaccess();

        $plan = $step->plan($context);
        $result = $step->apply($context, $plan);

        self::assertSame(4, $plan->changeCount());
        self::assertSame(4, $result->applied);
        self::assertStringContainsString("\tRewriteBase /sub/\n", (string) file_get_contents($root . '/.htaccess'));
        self::assertNotSame($original, (string) file_get_contents($root . '/.htaccess'));
        self::assertFalse($step->plan($context)->hasChanges(), 'idempotent');
    }

    public function testWithoutABasePathOrWithoutTheFileThereIsNothingToAdapt(): void
    {
        $withFile = $this->root(true);
        $before = (string) file_get_contents($withFile . '/.htaccess');
        $plan = (new RewriteHtaccess())->plan(JoomlaSite::context($withFile));
        self::assertFalse($plan->hasChanges());
        self::assertSame($before, (string) file_get_contents($withFile . '/.htaccess'));

        $without = $this->root();
        $plan = (new RewriteHtaccess())->plan(JoomlaSite::context($without, path: '/sub/'));
        self::assertFalse($plan->hasChanges());
        self::assertFileDoesNotExist($without . '/.htaccess', 'no file is made');
    }

    // ------------------------------------------------------------------------------------------------- the self-test

    /**
     * An HTTP client that answers from a table: path => [status, body]; the paths that were asked for are kept.
     *
     * @param array<string, array{int, string}> $answers
     * @param list<string>                      $asked
     */
    private static function client(array $answers, array &$asked): HttpClient
    {
        return new HttpClient(transport: static function (string $url, string $host) use ($answers, &$asked): array {
            $path = (string) parse_url($url, PHP_URL_PATH);
            $asked[] = $host . $path;
            [$status, $body] = $answers[$path] ?? [404, 'not found'];

            return [$status, ['content-type' => 'text/html'], $body];
        });
    }

    private const string FRONT = '<html><head><meta name="generator" content="Joomla! - Open Source Content Management"></head><body><a href="/index.php/about">About</a></body></html>';

    public function testASiteThatAnswersPasses(): void
    {
        $asked = [];
        $context = JoomlaSite::context($this->root(), [new DomainMapping('old.example', 'new.example')]);

        $result = (new SelfTest(self::client(['/' => [200, self::FRONT], '/administrator/' => [200, '<form>login</form>']], $asked)))->apply($context, (new SelfTest())->plan($context));

        self::assertSame(['new.example/', 'new.example/administrator/'], $asked);
        self::assertCount(2, $result->notices);
    }

    public function testTheBasePathIsPartOfTheAddressesThatAreAsked(): void
    {
        $asked = [];
        $context = JoomlaSite::context($this->root(), [new DomainMapping('old.example', 'new.example')], path: '/sub/');

        (new SelfTest(self::client(['/sub/' => [200, self::FRONT], '/sub/administrator/' => [200, 'login']], $asked)))->apply($context, (new SelfTest())->plan($context));

        self::assertSame(['new.example/sub/', 'new.example/sub/administrator/'], $asked);
    }

    public function testALinkToTheOldDomainFailsTheCheck(): void
    {
        $asked = [];
        $context = JoomlaSite::context($this->root(), [new DomainMapping('old.example', 'new.example')]);
        $front = str_replace('/index.php/about', 'https://old.example/index.php/about', self::FRONT);

        $this->expectException(SelfTestFailed::class);
        $this->expectExceptionMessage('still point to the old domain');

        (new SelfTest(self::client(['/' => [200, $front], '/administrator/' => [200, 'login']], $asked)))->apply($context, (new SelfTest())->plan($context));
    }

    public function testAnotherGeneratorMeansThatThisIsNotJoomla(): void
    {
        $asked = [];
        $context = JoomlaSite::context($this->root(), [new DomainMapping('old.example', 'new.example')]);

        $this->expectException(SelfTestFailed::class);
        $this->expectExceptionMessage('this is not Joomla');

        (new SelfTest(self::client(['/' => [200, '<meta name="generator" content="WordPress 6.8">'], '/administrator/' => [200, 'login']], $asked)))->apply($context, (new SelfTest())->plan($context));
    }

    public function testASiteWithoutAGeneratorTagIsFine(): void
    {
        $asked = [];
        $context = JoomlaSite::context($this->root(), [new DomainMapping('old.example', 'new.example')]);

        $result = (new SelfTest(self::client(['/' => [200, '<html>no tag</html>'], '/administrator/' => [200, 'login']], $asked)))->apply($context, (new SelfTest())->plan($context));

        self::assertCount(2, $result->notices);
    }

    public function testAnAdministrationThatDoesNotAnswerFailsTheCheck(): void
    {
        $asked = [];
        $context = JoomlaSite::context($this->root(), [new DomainMapping('old.example', 'new.example')]);

        $this->expectException(SelfTestFailed::class);
        $this->expectExceptionMessage('administration (/administrator/): HTTP 404');

        (new SelfTest(self::client(['/' => [200, self::FRONT]], $asked)))->apply($context, (new SelfTest())->plan($context));
    }

    public function testWithoutANewDomainOrWhenSwitchedOffNothingIsAsked(): void
    {
        $asked = [];
        $client = self::client([], $asked);

        $inPlace = JoomlaSite::context($this->root());
        self::assertSame(0, (new SelfTest($client))->apply($inPlace, (new SelfTest())->plan($inPlace))->applied);

        $off = JoomlaSite::context($this->root(), [new DomainMapping('old.example', 'new.example')], selfTest: false);
        self::assertSame(0, (new SelfTest($client))->apply($off, (new SelfTest())->plan($off))->applied);

        self::assertSame([], $asked);
    }
}
