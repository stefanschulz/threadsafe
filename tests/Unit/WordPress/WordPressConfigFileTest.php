<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\WordPress;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Database\DatabaseSettings;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\WordPress\Config\WordPressConfigFile;
use ThreadSafe\WordPress\Exception\InvalidConfigFile;

/**
 * The reader and the writer of the `wp-config.php` of a WordPress site (docs/PHASE9.md 3.2): the real files of the spikes (test values), the
 * shapes that sites and plugins make of them, and the promise that the file is never run and that a write changes only what it must.
 */
final class WordPressConfigFileTest extends TestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/wordpress/' . $name . '.php');
    }

    private static function file(string $name): WordPressConfigFile
    {
        return WordPressConfigFile::fromString(self::fixture($name));
    }

    // ------------------------------------------------------------------------------------------------- reading the real files

    public function testAFileWithConstantsThatAPluginAddedIsReadWithoutRunningIt(): void
    {
        $file = self::file('wp-config-6.9.4-plugin-added-constants');

        self::assertSame('test_db', $file->get('DB_NAME'));
        self::assertSame('test_user', $file->get('DB_USER'));
        self::assertSame('utf8', $file->get('DB_CHARSET'));
        self::assertSame('', $file->get('DB_COLLATE'));
        self::assertSame('wp_', $file->tablePrefix());
        self::assertTrue($file->get('WP_CACHE'), 'a plugin put it above the database settings');
        self::assertSame('/var/www/html/wp-content/plugins/wp-super-cache/', $file->get('WPCACHEHOME'));
        self::assertFalse($file->get('WP_DEBUG', 'unset'), 'a define() inside an if block is read too');
        self::assertFalse($file->isMultisite());
        self::assertEquals(new DatabaseSettings('db', 3306, 'test_db', 'test_user', 'test-password', 'wp_'), $file->database());
        self::assertSame(['ABSPATH' => null], $file->notUnderstood(), '__DIR__ . "/" is computed, and it is the only thing that is');
    }

    public function testTheFileOfTheDockerImageIsComputedAndTheMessageSaysSoWithoutItsValues(): void
    {
        $file = self::file('wp-config-docker-image');

        self::assertSame('getenv_docker', $file->notUnderstood()['DB_NAME']);
        self::assertNull($file->tablePrefix());
        try {
            $file->database();
            self::fail('a computed host must be refused');
        } catch (InvalidConfigFile $e) {
            self::assertStringContainsString('"DB_HOST" is computed by getenv_docker()', $e->getMessage());
            self::assertStringContainsString('Give the database in the profile by hand', $e->getMessage());
            self::assertStringNotContainsString('example password', $e->getMessage());
        }
    }

    public function testAMultisiteIsRecognisedByMultisiteAndNotByTheAllowFlag(): void
    {
        $network = self::file('wp-config-6.9.4-multisite');

        self::assertTrue($network->isMultisite());
        self::assertTrue($network->get('WP_ALLOW_MULTISITE'));
        self::assertSame('old.example', $network->get('DOMAIN_CURRENT_SITE'));
        self::assertFalse($network->get('SUBDOMAIN_INSTALL'));

        $prepared = WordPressConfigFile::fromString("<?php\ndefine( 'WP_ALLOW_MULTISITE', true );\n");
        self::assertFalse($prepared->isMultisite(), 'allowed, not converted: an ordinary site');
    }

    public function testAFileWithoutSettingsHasNothingToGive(): void
    {
        $empty = WordPressConfigFile::fromString("<?php\n// nothing\n");

        self::assertSame([], $empty->all());
        $this->expectException(InvalidConfigFile::class);
        $this->expectExceptionMessage('The setting "DB_HOST" is not in the file.');
        $empty->database();
    }

    // ------------------------------------------------------------------------------------------------- never run

    public function testTheFileIsNeverRun(): void
    {
        $marker = $this->tempDirectory() . '/marker';
        $source = "<?php\nfile_put_contents('$marker', 'it ran');\ndefine('DB_NAME', 'a');\nexit(1);\n\$table_prefix = 'wp_';\n";

        $file = WordPressConfigFile::fromString($source);

        self::assertFileDoesNotExist($marker, 'reading must not execute anything');
        self::assertSame('a', $file->get('DB_NAME'));
        self::assertSame('wp_', $file->tablePrefix(), 'what comes after an exit is still read');
    }

    // ------------------------------------------------------------------------------------------------- shapes

    public function testStringsNumbersAndTheThirdArgument(): void
    {
        $file = WordPressConfigFile::fromString(<<<'PHP'
<?php
define("A", "a\x41b\n");
define('B', 'it\'s a \\ test');
define('C', -5);
define('D', FALSE);
define('E', 'e', true);
define('F', 1.5);
define('G', null);
define('H', "interpolated $value");
define('I', 'a' . 'b');
define('J', SOME_CONSTANT);
define($name, 'x');
$other->define('K', 'k');
function define($a, $b) {}
PHP);

        self::assertSame("aAb\n", $file->get('A'));
        self::assertSame("it's a \\ test", $file->get('B'));
        self::assertSame(-5, $file->get('C'));
        self::assertFalse($file->get('D', 'unset'));
        self::assertSame('e', $file->get('E'));
        self::assertSame(1.5, $file->get('F'));
        self::assertNull($file->get('G', 'unset'));
        self::assertTrue($file->has('G'));
        self::assertSame(['H' => null, 'I' => null, 'J' => null], $file->notUnderstood());
        self::assertFalse($file->has('K'), 'a method of that name is not the language function');
        self::assertSame(['A', 'B', 'C', 'D', 'E', 'F', 'G'], array_keys($file->all()));
    }

    public function testTheLastDefinitionIsTheOneThatCountsForReadingButWritingRefusesAmbiguity(): void
    {
        $file = WordPressConfigFile::fromString("<?php\nif ( \$_SERVER['HTTP_HOST'] === 'a' ) {\n\tdefine( 'DB_NAME', 'first' );\n} else {\n\tdefine( 'DB_NAME', 'second' );\n}\n");

        self::assertSame('second', $file->get('DB_NAME'));
        $this->expectException(InvalidConfigFile::class);
        $this->expectExceptionMessage('"DB_NAME" is defined 2 times');
        $file->with('DB_NAME', 'x');
    }

    public function testASingleDefineInAnIfBlockCanBeWritten(): void
    {
        $file = WordPressConfigFile::fromString("<?php\nif ( ! defined( 'WP_HOME' ) ) {\n\tdefine( 'WP_HOME', 'http://old.example' );\n}\n");

        $changed = $file->with('WP_HOME', 'https://new.example');

        self::assertSame("<?php\nif ( ! defined( 'WP_HOME' ) ) {\n\tdefine( 'WP_HOME', 'https://new.example' );\n}\n", $changed->toString());
    }

    // ------------------------------------------------------------------------------------------------- writing

    public function testASettingIsChangedAndEveryOtherByteStays(): void
    {
        $original = self::fixture('wp-config-6.9.4-plugin-added-constants');
        $file = WordPressConfigFile::fromString($original);

        $changed = $file->with('DB_NAME', 'another_db');

        self::assertSame(str_replace("define( 'DB_NAME', 'test_db' );", "define( 'DB_NAME', 'another_db' );", $original), $changed->toString());
        self::assertSame('test_db', $file->get('DB_NAME'), 'the first instance is not changed');
        self::assertSame($original, $file->with('DB_NAME', 'test_db')->toString(), 'the same value writes nothing');
    }

    public function testTheDatabaseIsWrittenWithThePortAndThePrefix(): void
    {
        $original = self::fixture('wp-config-6.9.4-plugin-added-constants');
        $settings = new DatabaseSettings('db.internal', 3307, 'new_db', 'new_user', "pa'ss\\w\$rd", 'wp2_');

        $changed = WordPressConfigFile::fromString($original)->withDatabase($settings);

        self::assertEquals($settings, $changed->database(), 'what is written is what is read, quotes and backslashes and a dollar included');
        self::assertSame('db.internal:3307', $changed->get('DB_HOST'));
        self::assertSame('utf8', $changed->get('DB_CHARSET'), 'the rest stays');
        self::assertSame($original, str_replace(["'db.internal:3307'", "'new_user'", "'new_db'", "'wp2_'", var_export("pa'ss\\w\$rd", true)], ["'db'", "'test_user'", "'test_db'", "'wp_'", "'test-password'"], $changed->toString()));
        self::assertSame('db', WordPressConfigFile::fromString($original)->withDatabase(new DatabaseSettings('db', 3306, 'x', 'y', 'z', 'wp_'))->get('DB_HOST'), 'the usual port is not written');
    }

    public function testASettingThatIsNotThereIsNeverMadeUp(): void
    {
        $file = self::file('wp-config-6.9.4-plugin-added-constants');

        self::assertSame($file->toString(), $file->withIfPresent('WP_HOME', 'https://new.example')->toString());
        $this->expectException(InvalidConfigFile::class);
        $this->expectExceptionMessage('"WP_HOME" is not in the file; ThreadSafe changes settings and does not add them.');
        $file->with('WP_HOME', 'https://new.example');
    }

    public function testASettingThatIsComputedIsNotWritten(): void
    {
        $file = self::file('wp-config-docker-image');

        $this->expectException(InvalidConfigFile::class);
        $this->expectExceptionMessage('"DB_NAME" is computed by getenv_docker()');
        $file->with('DB_NAME', 'x');
    }

    public function testTheLineEndsOfTheFileStay(): void
    {
        $crlf = str_replace("\n", "\r\n", self::fixture('wp-config-6.9.4-plugin-added-constants'));

        $changed = WordPressConfigFile::fromString($crlf)->with('DB_PASSWORD', 'new')->toString();

        self::assertSame(str_replace("'test-password'", "'new'", $crlf), $changed);
        self::assertSame(substr_count($crlf, "\r\n"), substr_count($changed, "\r\n"));
    }

    public function testAValueThatCannotBeWrittenIsRefused(): void
    {
        $file = self::file('wp-config-6.9.4-plugin-added-constants');

        $this->expectException(InvalidArgumentException::class);
        $file->with('DB_NAME', "a\0b");
    }

    public function testTheFileIsWrittenAtomicallyAndKeepsItsMode(): void
    {
        $path = $this->tempDirectory() . '/wp-config.php';
        file_put_contents($path, self::fixture('wp-config-6.9.4-plugin-added-constants'));
        chmod($path, 0640);

        self::file('wp-config-6.9.4-plugin-added-constants')->with('DB_NAME', 'on_disk')->writeTo($path);

        self::assertSame('on_disk', WordPressConfigFile::fromPath($path)->get('DB_NAME'));
        if (DIRECTORY_SEPARATOR === '/') {
            self::assertSame('0640', substr(sprintf('%o', fileperms($path)), -4));
        }
        self::assertSame([], glob($path . '.*') ?: [], 'no temporary file is left');
    }

    public function testAFileThatCannotBeReadIsReported(): void
    {
        $this->expectException(InvalidConfigFile::class);
        $this->expectExceptionMessage('Cannot read the configuration file');
        WordPressConfigFile::fromPath($this->tempDirectory() . '/missing.php');
    }

    // ------------------------------------------------------------------------------------------------- paths

    public function testPathsThatPluginsWroteMoveWithTheDirectoryAndNothingElseDoes(): void
    {
        $source = "<?php\ndefine( 'WPCACHEHOME', '/var/www/html/wp-content/plugins/wp-super-cache/' );\ndefine( 'WP_CONTENT_DIR', '/var/www/html/wp-content' );\ndefine( 'SOMEWHERE', '/var/www/htmlx/other' );\ndefine( 'EXACT', '/var/www/html' );\ndefine( 'DB_HOST', 'db' );\ndefine( 'NOTE', 'see /var/www/html' );\ndefine( 'COMPUTED', __DIR__ . '/x' );\n";
        $file = WordPressConfigFile::fromString($source);

        self::assertSame(['WPCACHEHOME', 'WP_CONTENT_DIR', 'EXACT'], $file->pathSettingsUnder('/var/www/html/'));
        $moved = $file->withDirectoryMoved('/var/www/html', '/home/u/site/');

        self::assertSame('/home/u/site/wp-content/plugins/wp-super-cache/', $moved->get('WPCACHEHOME'));
        self::assertSame('/home/u/site/wp-content', $moved->get('WP_CONTENT_DIR'));
        self::assertSame('/home/u/site', $moved->get('EXACT'));
        self::assertSame('/var/www/htmlx/other', $moved->get('SOMEWHERE'), 'a directory that only begins the same is another directory');
        self::assertSame('db', $moved->get('DB_HOST'));
        self::assertSame('see /var/www/html', $moved->get('NOTE'), 'text that mentions a path is not a path');
        self::assertSame([], $file->pathSettingsUnder('/nowhere'));
    }

    // ------------------------------------------------------------------------------------------------- the host

    /** @return iterable<string, array{string, string, int}> */
    public static function hosts(): iterable
    {
        yield 'a name' => ['db', 'db', 3306];
        yield 'a name and a port' => ['db.example:3307', 'db.example', 3307];
        yield 'an address' => ['127.0.0.1', '127.0.0.1', 3306];
        yield 'an address and a port' => ['127.0.0.1:3308', '127.0.0.1', 3308];
        yield 'an IPv6 address in brackets' => ['[::1]:3309', '::1', 3309];
        yield 'an IPv6 address in brackets without a port' => ['[2001:db8::1]', '2001:db8::1', 3306];
        yield 'an IPv6 address without brackets' => ['::1', '::1', 3306];
        yield 'blanks around' => ['  db  ', 'db', 3306];
    }

    #[DataProvider('hosts')]
    public function testTheHostAndThePortAreSplit(string $text, string $host, int $port): void
    {
        self::assertSame([$host, $port], WordPressConfigFile::splitHost($text));
    }

    /** @return iterable<string, array{string, string}> */
    public static function badHosts(): iterable
    {
        yield 'a socket' => [':/var/run/mysqld/mysqld.sock', 'socket'];
        yield 'a host and a socket' => ['localhost:/tmp/mysql.sock', 'socket'];
        yield 'a persistent connection' => ['p:db', 'persistent'];
        yield 'a port that is no number' => ['db:abc', 'not a number'];
        yield 'nothing' => ['', 'empty'];
    }

    #[DataProvider('badHosts')]
    public function testAHostThatIsNotSupportedIsSaidSo(string $text, string $fragment): void
    {
        $this->expectException(InvalidConfigFile::class);
        $this->expectExceptionMessage($fragment);
        WordPressConfigFile::splitHost($text);
    }
}
