<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\PrestaShop9;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Database\DatabaseSettings;
use ThreadSafe\PrestaShop9\Exception\InvalidParametersFile;
use ThreadSafe\PrestaShop9\ParametersFile;

final class ParametersFileTest extends TestCase
{
    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            if (is_dir($path)) {
                foreach (glob($path . '/*') ?: [] as $file) {
                    @unlink($file);
                }
                @rmdir($path);
            }
        }
    }

    /**
     * Parameters as PrestaShop 9 writes them, with fake values.
     *
     * @return array<string, mixed>
     */
    private static function sample(): array
    {
        return [
            'database_host' => 'db',
            'database_port' => '3306',
            'database_name' => 'shopdb',
            'database_user' => 'shopuser',
            'database_password' => 'p"a\'s\\s word',
            'database_prefix' => 'ps_',
            'database_engine' => 'InnoDB',
            'mailer_transport' => 'smtp',
            'mailer_user' => null,
            'secret' => 'FAKE-SECRET-0123456789',
            'ps_cache_enable' => false,
            'use_debug_toolbar' => true,
            'cookie_key' => 'FAKE-COOKIE-KEY',
            'api_public_key' => "-----BEGIN PUBLIC KEY-----\nFAKEFAKEFAKE\nFAKE==\n-----END PUBLIC KEY-----\n",
            'with_nul' => "before\0after",
            'a_float' => 1.5,
            'an_int' => -7,
            'a_list' => ['x', 'y'],
        ];
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private static function source(array $parameters): string
    {
        return '<?php return ' . var_export(['parameters' => $parameters], true) . ';';
    }

    public function testReadsEveryValueWithItsType(): void
    {
        $file = ParametersFile::fromString(self::source(self::sample()));

        self::assertSame(self::sample(), $file->all());
        self::assertSame('db', $file->get('database_host'));
        self::assertTrue($file->has('mailer_user'));
        self::assertFalse($file->has('nope'));
    }

    public function testGetReturnsAnExplicitNullInsteadOfTheDefault(): void
    {
        $file = ParametersFile::fromString(self::source(self::sample()));

        self::assertNull($file->get('mailer_user', 'default'));
        self::assertSame('default', $file->get('missing', 'default'));
    }

    public function testToStringIsTheUnchangedSource(): void
    {
        $source = self::source(self::sample());

        self::assertSame($source, ParametersFile::fromString($source)->toString());
    }

    /**
     * @return iterable<string, array{string, string|int|float|bool|null}>
     */
    public static function replacements(): iterable
    {
        yield 'string' => ['database_host', 'newhost'];
        yield 'string with quotes and backslashes' => ['database_name', 'a\'b"c\\d'];
        yield 'string with newline' => ['secret', "line1\nline2"];
        yield 'string with NUL byte' => ['cookie_key', "x\0y"];
        yield 'empty string' => ['database_prefix', ''];
        yield 'int' => ['database_port', 3307];
        yield 'negative int' => ['an_int', -42];
        yield 'float' => ['a_float', 0.1];
        yield 'float with exponent' => ['a_float', 1e100];
        yield 'bool' => ['ps_cache_enable', true];
        yield 'null' => ['database_user', null];
        yield 'value that was null' => ['mailer_user', 'someone'];
        yield 'multi-line value' => ['api_public_key', 'single line now'];
        yield 'value with concatenation (NUL) replaced' => ['with_nul', 'plain'];
        yield 'array value replaced by a scalar' => ['a_list', 'scalar'];
        yield 'unicode' => ['secret', 'größe ✓ 😀'];
    }

    #[DataProvider('replacements')]
    public function testReplacingAValueGivesExactlyWhatVarExportWouldWrite(string $key, string|int|float|bool|null $value): void
    {
        $data = self::sample();
        $data[$key] = $value;

        $actual = ParametersFile::fromString(self::source(self::sample()))->with($key, $value);

        self::assertSame(self::source($data), $actual->toString());
        self::assertSame($data, $actual->all());
    }

    public function testNewKeyIsAppendedLikeVarExportWouldDoIt(): void
    {
        $data = self::sample();
        $data['brand_new'] = "it's new";

        $actual = ParametersFile::fromString(self::source(self::sample()))->with('brand_new', "it's new");

        self::assertSame(self::source($data), $actual->toString());
    }

    public function testSettingTheSameValueReturnsTheSameInstance(): void
    {
        $file = ParametersFile::fromString(self::source(self::sample()));

        self::assertSame($file, $file->with('database_host', 'db'));
        self::assertSame($file, $file->with('database_port', '3306'));
    }

    public function testOriginalInstanceIsNotChanged(): void
    {
        $file = ParametersFile::fromString(self::source(self::sample()));
        $file->with('database_host', 'other');

        self::assertSame('db', $file->get('database_host'));
    }

    public function testWithManyAppliesAllValues(): void
    {
        $data = self::sample();
        $data['database_host'] = 'h';
        $data['extra'] = 1;

        $actual = ParametersFile::fromString(self::source(self::sample()))->withMany(['database_host' => 'h', 'extra' => 1]);

        self::assertSame(self::source($data), $actual->toString());
    }

    public function testRejectsNonFiniteFloats(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ParametersFile::fromString(self::source(self::sample()))->with('a_float', INF);
    }

    public function testLastDuplicateKeyWinsAndIsTheOneThatChanges(): void
    {
        $source = "<?php return array('parameters' => array(\n    'a' => 1,\n    'a' => 2,\n));";
        $file = ParametersFile::fromString($source);

        self::assertSame(2, $file->get('a'));
        self::assertSame("<?php return array('parameters' => array(\n    'a' => 1,\n    'a' => 3,\n));", $file->with('a', 3)->toString());
    }

    public function testDatabaseSettings(): void
    {
        $settings = ParametersFile::fromString(self::source(self::sample()))->database();

        self::assertEquals(new DatabaseSettings('db', 3306, 'shopdb', 'shopuser', 'p"a\'s\\s word', 'ps_'), $settings);
    }

    public function testWithDatabaseKeepsThePortAsString(): void
    {
        $file = ParametersFile::fromString(self::source(self::sample()));
        $changed = $file->withDatabase(new DatabaseSettings('newhost', 3307, 'newdb', 'newuser', 'newpass', 'x_'));

        self::assertSame('3307', $changed->get('database_port'));
        self::assertSame('newhost', $changed->get('database_host'));
        self::assertSame('x_', $changed->get('database_prefix'));
        self::assertSame($file->get('secret'), $changed->get('secret'));
    }

    public function testWithDatabaseKeepsAnIntegerPortAsInteger(): void
    {
        $source = "<?php return array('parameters' => array('database_port' => 3306, 'database_host' => 'a', 'database_name' => 'b', 'database_user' => 'c', 'database_password' => 'd', 'database_prefix' => 'ps_'));";
        $changed = ParametersFile::fromString($source)->withDatabase(new DatabaseSettings('a', 3307, 'b', 'c', 'd', 'ps_'));

        self::assertSame(3307, $changed->get('database_port'));
    }

    public function testDatabaseSettingsRequireTheKeys(): void
    {
        $data = self::sample();
        unset($data['database_user']);

        $this->expectException(InvalidParametersFile::class);

        ParametersFile::fromString(self::source($data))->database();
    }

    public function testDatabasePortDefaultsTo3306(): void
    {
        $data = self::sample();
        unset($data['database_port']);

        self::assertSame(3306, ParametersFile::fromString(self::source($data))->database()->port);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function insertions(): iterable
    {
        yield 'short syntax, own lines, trailing comma' => [
            "<?php return [\n  'parameters' => [\n    'a' => 1,\n  ],\n];",
            "<?php return [\n  'parameters' => [\n    'a' => 1,\n    'n' => 'v',\n  ],\n];",
        ];
        yield 'own lines, last entry without trailing comma' => [
            "<?php return array (\n  'parameters' => array (\n      'a' => 1\n  ),\n);",
            "<?php return array (\n  'parameters' => array (\n      'a' => 1,\n      'n' => 'v',\n  ),\n);",
        ];
        yield 'empty array on own lines' => [
            "<?php return array (\n  'parameters' => array (\n  ),\n);",
            "<?php return array (\n  'parameters' => array (\n    'n' => 'v',\n  ),\n);",
        ];
        yield 'single line with trailing comma' => [
            "<?php return ['parameters' => ['a' => 1,]];",
            "<?php return ['parameters' => ['a' => 1, 'n' => 'v',]];",
        ];
        yield 'single line without trailing comma' => [
            "<?php return ['parameters' => ['a' => 1]];",
            "<?php return ['parameters' => ['a' => 1, 'n' => 'v']];",
        ];
        yield 'single line, empty' => [
            "<?php return ['parameters' => []];",
            "<?php return ['parameters' => ['n' => 'v']];",
        ];
        yield 'tab indentation' => [
            "<?php return [\n\t'parameters' => [\n\t\t'a' => 1,\n\t],\n];",
            "<?php return [\n\t'parameters' => [\n\t\t'a' => 1,\n\t\t'n' => 'v',\n\t],\n];",
        ];
    }

    #[DataProvider('insertions')]
    public function testInsertionAdaptsToTheExistingLayout(string $source, string $expected): void
    {
        $result = ParametersFile::fromString($source)->with('n', 'v');

        self::assertSame($expected, $result->toString());
        self::assertSame('v', $result->get('n'));
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function syntaxVariants(): iterable
    {
        yield 'comments and odd whitespace' => [
            "<?php\n// comment\nreturn   array  ( /* c */ 'parameters'\n=>\narray('a'=>1, # x\n'b' => 'two' ) ) ;\n",
            ['a' => 1, 'b' => 'two'],
        ];
        yield 'closing tag' => ["<?php return ['parameters' => ['a' => 1]];\n?>\n", ['a' => 1]];
        yield 'double quoted escapes' => ['<?php return ["parameters" => ["a" => "x\\ny\\t\\x41\\101\\u{1F600}\\\\\\$\\"z", "b" => "\\0"]];', ['a' => "x\ny\tAA😀\\\$\"z", 'b' => "\0"]];
        yield 'single quoted escapes' => ['<?php return [\'parameters\' => [\'a\' => \'it\\\'s \\\\ \\n\']];', ['a' => 'it\'s \\ \n']];
        yield 'string concatenation' => ["<?php return ['parameters' => ['a' => 'x' . \"\\0\" . 'y']];", ['a' => "x\0y"]];
        yield 'number formats' => ['<?php return [\'parameters\' => [\'a\' => 0x1A, \'b\' => 1_000, \'c\' => +5, \'d\' => -2.5e3, \'e\' => 007, \'f\' => 0b11]];', ['a' => 26, 'b' => 1000, 'c' => 5, 'd' => -2500.0, 'e' => 7, 'f' => 3]];
        yield 'constants in any case' => ['<?php return [\'parameters\' => [\'a\' => TRUE, \'b\' => False, \'c\' => Null]];', ['a' => true, 'b' => false, 'c' => null]];
        yield 'nested arrays and keys' => ['<?php return [\'parameters\' => [\'a\' => [1, 2, \'k\' => [\'x\' => true], 7 => \'seven\', \'8\' => \'eight\', \'z\']]];', ['a' => [0 => 1, 1 => 2, 'k' => ['x' => true], 7 => 'seven', 8 => 'eight', 9 => 'z']]];
        yield 'other top level keys are ignored' => ['<?php return [\'other\' => [\'a\' => 9], \'parameters\' => [\'a\' => 1]];', ['a' => 1]];
        yield 'empty parameters' => ["<?php return ['parameters' => []];", []];
    }

    /**
     * @param array<string, mixed> $expected
     */
    #[DataProvider('syntaxVariants')]
    public function testToleratesHarmlessSyntaxVariants(string $source, array $expected): void
    {
        self::assertSame($expected, ParametersFile::fromString($source)->all());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejected(): iterable
    {
        yield 'function call' => ["<?php return ['parameters' => ['a' => getenv('X')]];"];
        yield 'variable' => ["<?php \$x = 1; return ['parameters' => ['a' => \$x]];"];
        yield 'variable value only' => ["<?php return ['parameters' => ['a' => \$x]];"];
        yield 'constant' => ["<?php return ['parameters' => ['a' => PHP_EOL]];"];
        yield 'class constant' => ["<?php return ['parameters' => ['a' => Foo::BAR]];"];
        yield 'fully qualified constant' => ["<?php return ['parameters' => ['a' => \\true]];"];
        yield 'interpolated string' => ['<?php return [\'parameters\' => [\'a\' => "x$y"]];'];
        yield 'arithmetic' => ["<?php return ['parameters' => ['a' => 1 + 2]];"];
        yield 'concatenation with a call' => ["<?php return ['parameters' => ['a' => 'x' . trim('y')]];"];
        yield 'ternary' => ["<?php return ['parameters' => ['a' => true ? 1 : 2]];"];
        yield 'closure' => ["<?php return ['parameters' => ['a' => fn() => 1]];"];
        yield 'new object' => ["<?php return ['parameters' => ['a' => new stdClass()]];"];
        yield 'heredoc' => ["<?php return ['parameters' => ['a' => <<<X\nabc\nX]];"];
        yield 'second statement' => ["<?php return ['parameters' => []]; exec('x');"];
        yield 'statement before return' => ["<?php exec('x'); return ['parameters' => []];"];
        yield 'no return' => ["<?php ['parameters' => []];"];
        yield 'return of something else' => ['<?php return 5;'];
        yield 'no open tag' => ["return ['parameters' => []];"];
        yield 'html before open tag' => ["x\n<?php return ['parameters' => []];"];
        yield 'text after close tag' => ["<?php return ['parameters' => []]; ?>\nhello"];
        yield 'missing parameters key' => ["<?php return ['other' => []];"];
        yield 'parameters is not an array' => ["<?php return ['parameters' => 5];"];
        yield 'unterminated array' => ["<?php return ['parameters' => ['a' => 1"];
        yield 'missing comma' => ["<?php return ['parameters' => ['a' => 1 'b' => 2]];"];
        yield 'float key' => ["<?php return ['parameters' => [1.5 => 1]];"];
        yield 'null key' => ["<?php return ['parameters' => [null => 1]];"];
        yield 'empty file' => [''];
        yield 'garbage' => ['not php at all'];
    }

    #[DataProvider('rejected')]
    public function testRejectsAnythingButLiterals(string $source): void
    {
        $this->expectException(InvalidParametersFile::class);

        ParametersFile::fromString($source);
    }

    public function testNeverExecutesTheFile(): void
    {
        $marker = sys_get_temp_dir() . '/threadsafe-exec-' . bin2hex(random_bytes(6));
        $source = "<?php return ['parameters' => ['a' => file_put_contents('$marker', 'x')]];";

        try {
            ParametersFile::fromString($source);
            self::fail('The file must be rejected.');
        } catch (InvalidParametersFile) {
            self::assertFileDoesNotExist($marker);
        }
    }

    public function testErrorMessageNamesTheLine(): void
    {
        try {
            ParametersFile::fromString("<?php\nreturn [\n  'parameters' => [\n    'a' => foo(),\n  ],\n];");
            self::fail('The file must be rejected.');
        } catch (InvalidParametersFile $e) {
            self::assertStringContainsString('line 4', $e->getMessage());
        }
    }

    public function testFromPathReadsAFile(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/parameters.php', self::source(self::sample()));

        self::assertSame('db', ParametersFile::fromPath($dir . '/parameters.php')->get('database_host'));
    }

    public function testFromPathRejectsAMissingFile(): void
    {
        $this->expectException(InvalidParametersFile::class);

        ParametersFile::fromPath($this->tempDir() . '/missing.php');
    }

    public function testWriteToReplacesTheFileAtomicallyAndKeepsItsPermissions(): void
    {
        $dir = $this->tempDir();
        $path = $dir . '/parameters.php';
        file_put_contents($path, self::source(self::sample()));
        chmod($path, 0640);

        ParametersFile::fromPath($path)->with('database_host', 'written')->writeTo($path);

        self::assertSame('written', ParametersFile::fromPath($path)->get('database_host'));
        self::assertSame(0640, fileperms($path) & 0777);
        self::assertSame(['parameters.php'], array_map('basename', glob($dir . '/*') ?: []), 'No temporary file may be left behind.');
    }

    public function testWriteToCreatesANewFile(): void
    {
        $path = $this->tempDir() . '/new.php';

        ParametersFile::fromString(self::source(self::sample()))->writeTo($path);

        self::assertSame(self::source(self::sample()), file_get_contents($path));
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/threadsafe-test-' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        $this->cleanup[] = $dir;

        return $dir;
    }
}
