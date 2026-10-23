<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Joomla;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Database\DatabaseSettings;
use ThreadSafe\Joomla\Config\JoomlaConfigFile;
use ThreadSafe\Joomla\Exception\InvalidConfigFile;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * The reader and the writer of the `configuration.php` of a Joomla site: the real files of Joomla 5.4 and 6.2 (test values), variants
 * that sites make of them, and the promise that the file is never run and that a write changes only what it must.
 */
final class JoomlaConfigFileTest extends TestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    private static function fixture(string $version): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/joomla/configuration-' . $version . '.php');
    }

    /**
     * A small file in the way Joomla writes it (tabs), with the given properties.
     *
     * @param list<string> $lines
     */
    private static function file(array $lines, string $newline = "\n"): string
    {
        return '<?php' . $newline . 'class JConfig {' . $newline . "\t" . implode($newline . "\t", $lines) . $newline . '}' . $newline;
    }

    /** @return iterable<string, array{string}> */
    public static function versions(): iterable
    {
        yield 'Joomla 5.4' => ['5.4'];
        yield 'Joomla 6.2' => ['6.2'];
    }

    #[DataProvider('versions')]
    public function testTheRealFilesOfJoomlaAreReadCompletely(string $version): void
    {
        $config = JoomlaConfigFile::fromString(self::fixture($version));

        self::assertCount(67, $config->all(), 'all 67 properties are understood');
        self::assertSame([], $config->notUnderstood());
        self::assertSame('mysqli', $config->get('dbtype'));
        self::assertSame('joom_', $config->get('dbprefix'));
        self::assertSame(false, $config->get('offline'));
        self::assertSame(true, $config->get('mailonline'));
        self::assertSame(25, $config->get('smtpport'));
        self::assertSame('/var/www/html/tmp', $config->get('tmp_path'));
        self::assertSame('', $config->get('live_site'));
        self::assertTrue($config->has('secret'));
        self::assertFalse($config->has('cache_path'), 'not in 5.4 and 6.2');
        self::assertSame('fallback', $config->get('nonexistent', 'fallback'));
    }

    #[DataProvider('versions')]
    public function testTheDatabaseAccessComesFromTheFile(string $version): void
    {
        $settings = JoomlaConfigFile::fromString(self::fixture($version))->database();

        self::assertEquals(new DatabaseSettings('db', 3306, 'joomla_test', 'joomla', 'test-db-password', 'joom_'), $settings);
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function hosts(): iterable
    {
        yield 'a name' => ['db', 'db', 3306];
        yield 'localhost' => ['localhost', 'localhost', 3306];
        yield 'a name with a port' => ['db.example.com:3307', 'db.example.com', 3307];
        yield 'an address with a port' => ['10.0.0.5:3306', '10.0.0.5', 3306];
        yield 'an IPv6 address in brackets' => ['[::1]', '::1', 3306];
        yield 'an IPv6 address in brackets with a port' => ['[2001:db8::1]:3307', '2001:db8::1', 3307];
        yield 'an IPv6 address without brackets has no port' => ['::1', '::1', 3306];
        yield 'blanks around it' => ['  db  ', 'db', 3306];
    }

    #[DataProvider('hosts')]
    public function testTheHostSettingHasThreeShapes(string $setting, string $host, int $port): void
    {
        self::assertSame([$host, $port], JoomlaConfigFile::splitHost($setting));
    }

    /** @return iterable<string, array{string}> */
    public static function unsupportedHosts(): iterable
    {
        yield 'a socket' => [':/var/run/mysqld/mysqld.sock'];
        yield 'a host with a socket' => ['localhost:/var/run/mysqld/mysqld.sock'];
        yield 'a persistent connection' => ['p:localhost'];
        yield 'a port that is not a number' => ['db:port'];
        yield 'nothing' => [''];
    }

    #[DataProvider('unsupportedHosts')]
    public function testASocketAnEmptyHostAndWrongPortsAreReportedWithTheSetting(string $setting): void
    {
        $this->expectException(InvalidConfigFile::class);

        JoomlaConfigFile::splitHost($setting);
    }

    public function testTheTypeOfTheDatabaseDecidesWhetherThereIsAnyAccess(): void
    {
        $pdo = JoomlaConfigFile::fromString(self::file(["public \$dbtype = 'pdomysql';", "public \$host = 'db';", "public \$user = 'u';", "public \$password = 'p';", "public \$db = 'd';", "public \$dbprefix = 'x_';"]));
        self::assertSame('x_', $pdo->database()->prefix);

        $this->expectException(InvalidConfigFile::class);
        $this->expectExceptionMessage('"pgsql"');
        JoomlaConfigFile::fromString(self::file(["public \$dbtype = 'pgsql';", "public \$host = 'db';"]))->database();
    }

    public function testAMissingSettingIsNamedAndNeverItsValue(): void
    {
        $file = JoomlaConfigFile::fromString(self::file(["public \$dbtype = 'mysqli';", "public \$host = 'db';", "public \$user = 'u';", "public \$password = 'topsecret';", "public \$db = 'd';"]));

        try {
            $file->database();
            self::fail('the prefix is missing');
        } catch (InvalidConfigFile $e) {
            self::assertStringContainsString('"dbprefix"', $e->getMessage());
            self::assertStringNotContainsString('topsecret', $e->getMessage());
        }
    }

    public function testAnExpressionAsAValueIsNotUnderstoodAndTheOthersAre(): void
    {
        $config = JoomlaConfigFile::fromString(self::file([
            "public \$tmp_path = JPATH_ROOT . '/tmp';",
            "public \$log_path = __DIR__ . '/logs';",
            "public \$sitename = 'Fine';",
            "public \$secret = \"with \$interpolation\";",
            "public \$list_limit = 20;",
        ]));

        self::assertSame(['tmp_path', 'log_path', 'secret'], $config->notUnderstood());
        self::assertSame('Fine', $config->get('sitename'));
        self::assertSame(20, $config->get('list_limit'));
        self::assertNull($config->get('tmp_path'));
        self::assertTrue($config->has('tmp_path'), 'it is there, only not a literal');
        self::assertArrayNotHasKey('tmp_path', $config->all());
    }

    public function testASettingThatIsNeededButNotALiteralIsReported(): void
    {
        $config = JoomlaConfigFile::fromString(self::file(["public \$dbtype = 'mysqli';", "public \$host = 'db';", "public \$user = 'u';", "public \$password = getenv('DB_PASSWORD');", "public \$db = 'd';", "public \$dbprefix = 'x_';"]));

        $this->expectException(InvalidConfigFile::class);
        $this->expectExceptionMessage('"password"');
        $config->database();
    }

    public function testLiteralsOfEveryKindAreRead(): void
    {
        $config = JoomlaConfigFile::fromString(self::file([
            "public \$single = 'it\\'s a \\\\ path';",
            'public $double = "tab\\there \\x41 \\u{e9} \\$ \\"q\\"";',
            'public $int = 42;',
            'public $negative = -7;',
            'public $float = 1.5;',
            'public $yes = TRUE;',
            'public $no = false;',
            'public $nothing = NULL;',
            'public $bare;',
            "public \$list = array('a', 'b' => 2, 3);",
            "public \$short = ['x', 'y'];",
            'public $empty = [];',
        ]));

        self::assertSame("it's a \\ path", $config->get('single'));
        self::assertSame("tab\there A é \$ \"q\"", $config->get('double'));
        self::assertSame(42, $config->get('int'));
        self::assertSame(-7, $config->get('negative'));
        self::assertSame(1.5, $config->get('float'));
        self::assertTrue($config->get('yes'));
        self::assertFalse($config->get('no'));
        self::assertNull($config->get('nothing'));
        self::assertNull($config->get('bare'));
        self::assertSame(['a', 'b' => 2, 3], $config->get('list'));
        self::assertSame(['x', 'y'], $config->get('short'));
        self::assertSame([], $config->get('empty'));
        self::assertSame([], $config->notUnderstood());
    }

    public function testCommentsMethodsConstantsAndTypedPropertiesDoNotConfuseIt(): void
    {
        $source = "<?php\n// a site's own file\nclass JConfig {\n\t/** the name */\n\tpublic \$sitename = 'A';\n\tconst VERSION = 3;\n\tpublic string \$typed = 'T';\n\tpublic function hello() { return ['x' => 1]; }\n\t# another comment\n\tpublic \$after = 'B';\n}\n";

        $config = JoomlaConfigFile::fromString($source);

        self::assertSame(['sitename' => 'A', 'typed' => 'T', 'after' => 'B'], $config->all());
    }

    public function testNothingThatIsParsedOrRewrittenRunsAnything(): void
    {
        $marker = $this->tempDirectory() . '/marker';
        $source = "<?php\nfile_put_contents('$marker', 'ran');\nclass JConfig {\n\tpublic \$a = 'b';\n\tpublic \$c = file_put_contents('$marker', 'ran again');\n}\nfile_put_contents('$marker', 'ran at the end');\n";

        $config = JoomlaConfigFile::fromString($source)->with('a', 'changed')->with('new', 1);

        self::assertFileDoesNotExist($marker);
        self::assertSame('changed', $config->get('a'));
        self::assertSame(['c'], $config->notUnderstood());
    }

    public function testAFileWithoutTheClassIsRefused(): void
    {
        $this->expectException(InvalidConfigFile::class);
        $this->expectExceptionMessage('no class JConfig');

        JoomlaConfigFile::fromString("<?php\n\$config = ['a' => 'b'];\n");
    }

    public function testAClassThatIsNotClosedIsRefused(): void
    {
        $this->expectException(InvalidConfigFile::class);

        JoomlaConfigFile::fromString("<?php\nclass JConfig {\n\tpublic \$a = 'b';\n");
    }

    public function testAMissingFileIsRefusedWithItsPath(): void
    {
        $path = $this->tempDirectory() . '/configuration.php';

        $this->expectException(InvalidConfigFile::class);
        $this->expectExceptionMessage($path);

        JoomlaConfigFile::fromPath($path);
    }

    // ------------------------------------------------------------------------------------------------- writing

    #[DataProvider('versions')]
    public function testChangingAValueChangesNothingElse(string $version): void
    {
        $source = self::fixture($version);
        $changed = JoomlaConfigFile::fromString($source)->with('tmp_path', '/srv/new/tmp')->with('offline', true)->with('list_limit', 50)->toString();

        $expected = strtr($source, [
            "public \$tmp_path = '/var/www/html/tmp';" => "public \$tmp_path = '/srv/new/tmp';",
            'public $offline = false;' => 'public $offline = true;',
            'public $list_limit = 20;' => 'public $list_limit = 50;',
        ]);
        self::assertSame($expected, $changed, 'only the three literals differ, byte for byte');
        self::assertSame('/srv/new/tmp', JoomlaConfigFile::fromString($changed)->get('tmp_path'));
    }

    public function testTheSameValueIsNoChange(): void
    {
        $config = JoomlaConfigFile::fromString(self::fixture('6.2'));

        self::assertSame($config, $config->with('dbprefix', 'joom_'));
        self::assertSame($config->toString(), $config->with('offline', false)->toString());
    }

    public function testStringsAreWrittenInSingleQuotesAndEscaped(): void
    {
        $written = JoomlaConfigFile::fromString(self::file(["public \$password = 'x';"]))->with('password', "it's a \\ \"pw\" \$1\n")->toString();

        self::assertStringContainsString("public \$password = 'it\\'s a \\\\ \"pw\" \$1\n';", $written);
        self::assertSame("it's a \\ \"pw\" \$1\n", JoomlaConfigFile::fromString($written)->get('password'));
    }

    public function testNullBoolsAndNumbersAreWrittenAsJoomlaWritesThem(): void
    {
        $written = JoomlaConfigFile::fromString(self::file(['public $a = 1;', 'public $b = 1;', 'public $c = 1;', 'public $d = 1;', 'public $e = 1;']))
            ->withMany(['a' => null, 'b' => true, 'c' => false, 'd' => -3, 'e' => 2.5])->toString();

        foreach (['public $a = null;', 'public $b = true;', 'public $c = false;', 'public $d = -3;', 'public $e = 2.5;'] as $line) {
            self::assertStringContainsString($line, $written);
        }
    }

    public function testAPropertyWithoutAValueGetsOne(): void
    {
        $written = JoomlaConfigFile::fromString(self::file(['public $bare;', "public \$after = 'x';"]))->with('bare', 'now')->toString();

        self::assertStringContainsString("public \$bare = 'now';", $written);
        self::assertStringContainsString("public \$after = 'x';", $written);
    }

    public function testAnArrayCanBeReplacedByAScalar(): void
    {
        $written = JoomlaConfigFile::fromString(self::file(["public \$list = array('a',\n\t\t'b');", "public \$after = 'x';"]))->with('list', 'none')->toString();

        self::assertSame(self::file(["public \$list = 'none';", "public \$after = 'x';"]), $written);
    }

    public function testANewPropertyIsAddedBehindTheLastOneInItsIndentation(): void
    {
        $source = self::file(["public \$a = 'x';", "public \$b = 'y';"]);

        $written = JoomlaConfigFile::fromString($source)->with('c', 'z')->toString();

        self::assertSame(self::file(["public \$a = 'x';", "public \$b = 'y';", "public \$c = 'z';"]), $written);
        self::assertSame('z', JoomlaConfigFile::fromString($written)->get('c'));
    }

    public function testANewPropertyInAnEmptyClassAndInAFileWithCrLf(): void
    {
        $empty = JoomlaConfigFile::fromString("<?php\nclass JConfig {\n}\n")->with('a', 1)->toString();
        self::assertSame("<?php\nclass JConfig {\n\tpublic \$a = 1;\n}\n", $empty);

        $crlf = JoomlaConfigFile::fromString(self::file(["public \$a = 'x';"], "\r\n"))->with('b', 2)->toString();
        self::assertSame(self::file(["public \$a = 'x';", 'public $b = 2;'], "\r\n"), $crlf, 'every line end stays CRLF');
    }

    public function testTheLineEndsCommentsAndOwnPropertiesOfASiteSurviveAWrite(): void
    {
        $source = "<?php\r\n// kept\r\nclass JConfig {\r\n\t/* kept too */\r\n\tpublic \$mine = JPATH_ROOT . '/mine';\r\n\tpublic \$host = 'db';\r\n\tpublic \$custom_flag = true;\r\n}\r\n";

        $written = JoomlaConfigFile::fromString($source)->with('host', 'db2:3307')->toString();

        self::assertSame(str_replace("'db'", "'db2:3307'", $source), $written);
    }

    public function testTheDatabaseAccessIsWrittenWithThePortBehindTheHostOnlyIfItIsNotTheUsualOne(): void
    {
        $file = JoomlaConfigFile::fromString(self::fixture('5.4'));

        $usual = $file->withDatabase(new DatabaseSettings('db2', 3306, 'newdb', 'newuser', 'new-pass', 'abc_'), 'pdomysql');
        self::assertSame('db2', $usual->get('host'));
        self::assertSame('pdomysql', $usual->get('dbtype'));
        self::assertEquals(new DatabaseSettings('db2', 3306, 'newdb', 'newuser', 'new-pass', 'abc_'), $usual->database());

        $other = $file->withDatabase(new DatabaseSettings('db2', 3307, 'newdb', 'newuser', 'new-pass', 'abc_'));
        self::assertSame('db2:3307', $other->get('host'));
        self::assertSame('mysqli', $other->get('dbtype'), 'the type stays unless a type is given');
        self::assertSame(3307, $other->database()->port);

        // everything else is what it was
        self::assertSame($file->get('secret'), $other->get('secret'));
        self::assertSame($file->get('sitename'), $other->get('sitename'));
    }

    public function testOnlyTheTypesOfMariaDbAndMySqlCanBeWritten(): void
    {
        $this->expectException(InvalidArgumentException::class);

        JoomlaConfigFile::fromString(self::fixture('5.4'))->withDatabase(new DatabaseSettings('db', 3306, 'd', 'u', 'p', 'x_'), 'pgsql');
    }

    public function testAnyStringThatIsWrittenIsReadBackAsItWas(): void
    {
        mt_srand(20261023);
        $alphabet = ["'", '"', '\\', '$', "\n", "\r", "\t", ' ', '{', '}', ';', '?', '>', '<?php', 'é', 'ß', '日本', '😀', '%', '=', '//', '#', '/*'];
        $file = JoomlaConfigFile::fromString(self::file(["public \$password = 'x';"]));

        for ($i = 0; $i < 300; ++$i) {
            $value = '';
            for ($j = mt_rand(0, 12); $j > 0; --$j) {
                $value .= mt_rand(0, 2) === 0 ? $alphabet[mt_rand(0, count($alphabet) - 1)] : chr(mt_rand(32, 126));
            }
            $written = $file->with('password', $value)->toString();

            self::assertSame($value, JoomlaConfigFile::fromString($written)->get('password'), 'round trip of ' . json_encode($value));
            self::assertSame(self::file(["public \$password = " . var_export($value, true) . ';']), $written);
        }
    }

    public function testAValueWithANulByteIsRefusedBecauseItCannotBeWrittenAsALiteral(): void
    {
        $this->expectException(InvalidArgumentException::class);

        JoomlaConfigFile::fromString(self::file(["public \$a = 'x';"]))->with('a', "a\0b");
    }

    public function testNothingThatIsNotAPhpNameCanBeWritten(): void
    {
        $this->expectException(InvalidArgumentException::class);

        JoomlaConfigFile::fromString(self::fixture('5.4'))->with("bad name'; evil();//", 'x');
    }

    public function testTheFileIsReplacedAndKeepsItsMode(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('File modes are not those of Linux on Windows.');
        }
        $path = $this->tempDirectory() . '/configuration.php';
        file_put_contents($path, self::fixture('6.2'));
        chmod($path, 0444);   // as the image of Joomla makes it

        JoomlaConfigFile::fromPath($path)->with('live_site', '')->with('sitename', 'Moved')->writeTo($path);

        self::assertSame('Moved', JoomlaConfigFile::fromPath($path)->get('sitename'));
        self::assertSame(0444, fileperms($path) & 0777, 'a read-only file stays read-only: it was replaced, not opened');
        self::assertSame([], glob($path . '.threadsafe-*'), 'no temporary file is left');
    }
}
