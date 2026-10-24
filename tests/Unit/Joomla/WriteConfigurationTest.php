<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Joomla;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Database\DatabaseSettings;
use ThreadSafe\Joomla\Config\JoomlaConfigFile;
use ThreadSafe\Joomla\Steps\WriteConfiguration;
use ThreadSafe\Migration\MigrationRequest;
use ThreadSafe\Replace\DomainMapping;
use ThreadSafe\Tests\Support\JoomlaSite;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * The step that adapts `configuration.php` to the new host: the database, the interface to it, the two paths and `live_site`.
 */
final class WriteConfigurationTest extends TestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    private function root(): string
    {
        $root = (string) realpath($this->tempDirectory());
        JoomlaSite::build($root);

        return $root;
    }

    public function testNothingChangesWhenTheSiteStaysWhereItIs(): void
    {
        $root = $this->root();
        $context = JoomlaSite::context($root, serverPath: '/var/www/html');   // the paths of the file are the paths of the host
        $before = (string) file_get_contents($root . '/configuration.php');

        $step = new WriteConfiguration();
        $plan = $step->plan($context);

        self::assertFalse($plan->hasChanges());
        self::assertSame(0, $step->apply($context, $plan)->applied);
        self::assertSame($before, (string) file_get_contents($root . '/configuration.php'));
    }

    public function testThePathsFollowTheDirectoryOfTheSite(): void
    {
        $root = $this->root();
        $context = JoomlaSite::context($root);

        $step = new WriteConfiguration();
        $plan = $step->plan($context);
        $step->apply($context, $plan);

        $config = JoomlaConfigFile::fromPath($root . '/configuration.php');
        self::assertSame($root . '/administrator/logs', $config->get('log_path'));
        self::assertSame($root . '/tmp', $config->get('tmp_path'));
        self::assertSame(2, $plan->changeCount());
    }

    public function testANewDatabaseIsWrittenAndOnlyTheDatabase(): void
    {
        $root = $this->root();
        $before = (string) file_get_contents($root . '/configuration.php');
        $new = new DatabaseSettings('db2.example.org', 3307, 'newdb', 'newuser', 'new-password', 'abc_');
        $context = JoomlaSite::context($root, database: $new, serverPath: '/var/www/html');

        $step = new WriteConfiguration();
        $plan = $step->plan($context);
        $step->apply($context, $plan);

        $after = (string) file_get_contents($root . '/configuration.php');
        self::assertSame(strtr($before, [
            "public \$host = 'db';" => "public \$host = 'db2.example.org:3307';",
            "public \$user = 'joomla';" => "public \$user = 'newuser';",
            "public \$password = 'test-db-password';" => "public \$password = 'new-password';",
            "public \$db = 'joomla_test';" => "public \$db = 'newdb';",
            "public \$dbprefix = 'joom_';" => "public \$dbprefix = 'abc_';",
        ]), $after, 'five values, nothing else (secret, mail settings and all the rest are as they were)');
        self::assertEquals($new, JoomlaConfigFile::fromPath($root . '/configuration.php')->database());
    }

    public function testThePasswordNeverAppearsInThePlan(): void
    {
        $root = $this->root();
        $context = JoomlaSite::context($root, database: new DatabaseSettings('db', 3306, 'joomla_test', 'joomla', 'brand-new-secret', 'joom_'), serverPath: '/var/www/html');

        $plan = (new WriteConfiguration())->plan($context);

        self::assertTrue($plan->hasChanges());
        self::assertStringNotContainsString('brand-new-secret', (string) json_encode($plan->toArray()));
        self::assertStringNotContainsString('test-db-password', (string) json_encode($plan->toArray()));
    }

    public function testAReadOnlyFileIsReplacedAndStaysReadOnly(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('File modes are not those of Linux on Windows.');
        }
        $root = $this->root();
        chmod($root . '/configuration.php', 0444);   // as the image of Joomla makes it
        $context = JoomlaSite::context($root);

        $step = new WriteConfiguration();
        $step->apply($context, $step->plan($context));

        self::assertSame($root . '/tmp', JoomlaConfigFile::fromPath($root . '/configuration.php')->get('tmp_path'));
        self::assertSame(0444, fileperms($root . '/configuration.php') & 0777);
    }

    /** @return iterable<string, array{string, bool, bool, string}> */
    public static function interfaces(): iterable
    {
        yield 'mysqli where only pdo is there' => ['mysqli', false, true, 'pdomysql'];
        yield 'pdomysql where only mysqli is there' => ['pdomysql', true, false, 'mysqli'];
        yield 'mysqli where it is there' => ['mysqli', true, true, 'mysqli'];
        yield 'pdomysql where it is there' => ['pdomysql', true, true, 'pdomysql'];
        yield 'neither is there: nothing to choose' => ['mysqli', false, false, 'mysqli'];
        yield 'only mysqli, and the site has it' => ['mysqli', true, false, 'mysqli'];
    }

    #[DataProvider('interfaces')]
    public function testTheInterfaceToTheDatabaseIsTheOneThisPhpHas(string $configured, bool $mysqli, bool $pdo, string $expected): void
    {
        self::assertSame($expected, WriteConfiguration::databaseType($configured, $mysqli, $pdo));
    }

    public function testADriverThatTheRequestNamesWinsOverWhatThisPhpHas(): void
    {
        // the PHP of a command line is not always the PHP of the web server: the person names the interface of the web server's PHP
        self::assertSame('pdomysql', WriteConfiguration::databaseType('mysqli', true, false, 'pdo'));
        self::assertSame('mysqli', WriteConfiguration::databaseType('pdomysql', false, true, 'mysqli'));
        self::assertSame('mysqli', WriteConfiguration::databaseType('mysqli', true, true, 'auto'), 'auto looks at the extensions');
        self::assertSame('pdomysql', WriteConfiguration::databaseType('mysqli', false, true, 'auto'));
    }

    public function testTheDriverOfTheNewDatabaseIsWrittenAsDbtype(): void
    {
        $root = $this->root();
        $context = JoomlaSite::context($root, database: new DatabaseSettings('db', 3306, 'joomla_test', 'joomla', 'test-db-password', 'joom_', DatabaseSettings::PDO), serverPath: '/var/www/html');

        $step = new WriteConfiguration();
        $step->apply($context, $step->plan($context));

        self::assertSame('pdomysql', JoomlaConfigFile::fromPath($root . '/configuration.php')->get('dbtype'));
    }

    /**
     * @param list<DomainMapping> $domains
     */
    private static function request(array $domains, ?string $path = null): MigrationRequest
    {
        return new MigrationRequest('/x', $domains, physicalUri: $path);
    }

    /** @return iterable<string, array{string, list<array{string, string}>, string|null, string, bool}> */
    public static function liveSites(): iterable
    {
        yield 'follows the move' => ['https://old.example.org', [['old.example.org', 'new.example.org']], null, 'https://new.example.org', false];
        yield 'keeps its scheme and its path' => ['http://old.example.org/blog', [['old.example.org', 'new.example.org']], null, 'http://new.example.org/blog', false];
        yield 'takes the new base path' => ['https://old.example.org/blog', [['old.example.org', 'new.example.org']], '/site/', 'https://new.example.org/site', false];
        yield 'to the root' => ['https://old.example.org/blog', [['old.example.org', 'new.example.org']], '/', 'https://new.example.org', false];
        yield 'with a port' => ['http://localhost:8190', [['localhost:8190', 'localhost:8290']], null, 'http://localhost:8290', false];
        yield 'already the new one' => ['https://new.example.org', [['old.example.org', 'new.example.org']], null, 'https://new.example.org', false];
        yield 'a host that is not part of the move is cleared' => ['https://other.example.org', [['old.example.org', 'new.example.org']], null, '', true];
        yield 'no move, no new path: left alone' => ['https://old.example.org', [], null, 'https://old.example.org', false];
        yield 'no move but a new path' => ['https://old.example.org/blog', [], '/site/', 'https://old.example.org/site', false];
    }

    /**
     * @param list<array{string, string}> $mappings
     */
    #[DataProvider('liveSites')]
    public function testLiveSiteFollowsTheMoveOrIsCleared(string $liveSite, array $mappings, ?string $path, string $expected, bool $warns): void
    {
        $domains = array_map(static fn (array $pair): DomainMapping => new DomainMapping($pair[0], $pair[1]), $mappings);

        [$value, $notice] = WriteConfiguration::liveSite($liveSite, self::request($domains, $path));

        self::assertSame($expected, $value);
        self::assertSame($warns, $notice !== null);
    }

    public function testLiveSiteIsWrittenWhereTheFileHasOne(): void
    {
        $root = $this->root();
        file_put_contents($root . '/configuration.php', str_replace("public \$live_site = '';", "public \$live_site = 'https://old.example.org';", (string) file_get_contents($root . '/configuration.php')));
        $context = JoomlaSite::context($root, [new DomainMapping('old.example.org', 'new.example.org')], serverPath: '/var/www/html');

        $step = new WriteConfiguration();
        $step->apply($context, $step->plan($context));

        self::assertSame('https://new.example.org', JoomlaConfigFile::fromPath($root . '/configuration.php')->get('live_site'));
    }

    public function testAnEmptyLiveSiteStaysEmpty(): void
    {
        $root = $this->root();
        $context = JoomlaSite::context($root, [new DomainMapping('old.example.org', 'new.example.org')], serverPath: '/var/www/html');

        self::assertFalse((new WriteConfiguration())->plan($context)->hasChanges());
    }
}
