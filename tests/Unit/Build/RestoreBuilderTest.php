<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Build;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Build\Bundler;
use ThreadSafe\Build\RestoreBuilder;
use ThreadSafe\Build\RestoreStub;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * The restore tool built from the real sources: what is in it, that it can be read back, that the stub is old-PHP syntax, and that
 * the code in it needs nothing but itself.
 */
final class RestoreBuilderTest extends TestCase
{
    use TempDirectory;

    private static string $built = '';

    /** @var array{bytes: int, bundleBytes: int, files: int, resources: int} */
    private static array $result = ['bytes' => 0, 'bundleBytes' => 0, 'files' => 0, 'resources' => 0];

    public static function setUpBeforeClass(): void
    {
        self::$built = sys_get_temp_dir() . '/threadsafe-test-build-' . bin2hex(random_bytes(4)) . '/restore.php';
        self::$result = (new RestoreBuilder(dirname(__DIR__, 3)))->build(self::$built);
    }

    public static function tearDownAfterClass(): void
    {
        @unlink(self::$built);
        @rmdir(dirname(self::$built));
    }

    /**
     * @return array<string, string> name => content of the payload
     */
    private function payload(): array
    {
        $file = (string) file_get_contents(self::$built);
        $marker = '__halt_compiler();';
        $data = gzinflate(substr($file, strpos($file, $marker) + strlen($marker)));
        self::assertIsString($data);

        $entries = [];
        for ($at = 0; $at < strlen($data);) {
            $end = (int) strpos($data, "\n", $at);
            [$name, $size] = explode("\t", substr($data, $at, $end - $at));
            $entries[$name] = substr($data, $end + 1, (int) $size);
            $at = $end + 1 + (int) $size;
        }

        return $entries;
    }

    public function testTheFileIsSmallAndLeadsWithTheStub(): void
    {
        $file = (string) file_get_contents(self::$built);

        self::assertStringStartsWith("<?php\n// ThreadSafe restore tool", $file);
        self::assertSame(self::$result['bytes'], strlen($file));
        self::assertLessThan(400 * 1024, strlen($file), 'a few hundred KB, not megabytes');
        self::assertGreaterThan(100 * 1024, strlen($file));
    }

    public function testThePayloadHoldsTheCodeAndThePluginFilesAndNothingElse(): void
    {
        $entries = $this->payload();

        self::assertArrayHasKey('bundle.php', $entries);
        $others = array_keys(array_diff_key($entries, ['bundle.php' => true]));
        self::assertContains('plugins/prestashop9/plugin.json', $others);
        self::assertContains('plugins/generic/plugin.json', $others);
        self::assertContains('plugins/prestashop9/resources/skip-tables.json', $others);
        self::assertSame(self::$result['resources'], count($others));
        foreach ($others as $name) {
            self::assertMatchesRegularExpression('~^plugins/[a-z0-9]+/(plugin\.json|resources/)~', $name, 'only plugin data');
        }
        foreach ($entries as $name => $content) {
            self::assertStringNotContainsString('..', $name);
        }
        self::assertStringNotContainsString('namespace ThreadSafe\\Build', $entries['bundle.php'], 'the build tool is not part of the tool');
        self::assertStringNotContainsString('namespace ThreadSafe\\Tests', $entries['bundle.php']);
    }

    public function testEveryClassOfTheProgramIsInTheBundleAndItLoadsByItself(): void
    {
        $root = dirname(__DIR__, 3);
        $builder = new RestoreBuilder($root);
        $expected = (new Bundler())->declared($builder->sourceFiles());
        self::assertGreaterThan(200, count($expected));
        $bundle = $this->tempDirectory() . '/bundle.php';
        file_put_contents($bundle, $this->payload()['bundle.php']);
        token_get_all((string) file_get_contents($bundle), TOKEN_PARSE);

        // a fresh process with nothing but the bundle: every class must be declared, and with all its parents (so in the right order)
        $code = '$m = []; require $argv[1]; foreach (json_decode($argv[2]) as $n) { if (!class_exists($n, false) && !interface_exists($n, false) && !trait_exists($n, false) && !enum_exists($n, false)) { $m[] = $n; } } echo json_encode($m);';
        $process = proc_open([PHP_BINARY, '-r', $code, $bundle, (string) json_encode($expected)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        proc_close($process);

        self::assertSame('', $errors);
        self::assertSame('[]', $output, 'classes the bundle does not declare');
    }

    public function testTheStubUsesOnlyWhatPhp52CanRead(): void
    {
        $stub = RestoreStub::source('test');
        $tokens = token_get_all(substr($stub, 0, (int) strpos($stub, '__halt_compiler') + strlen('__halt_compiler')));
        $forbidden = [
            T_NAMESPACE => 'namespace', T_NS_SEPARATOR => 'a name with a backslash', T_NAME_QUALIFIED => 'a qualified name', T_NAME_FULLY_QUALIFIED => 'a qualified name',
            T_FN => 'fn', T_DIR => '__DIR__', T_COALESCE => '??', T_POW => '**', T_USE => 'use', T_CLASS => 'class', T_TRAIT => 'trait',
            T_YIELD => 'yield', T_FINALLY => 'finally', T_GOTO => 'goto', T_INSTEADOF => 'insteadof', T_SPACESHIP => '<=>', T_ELLIPSIS => '...', T_PUBLIC => 'visibility', T_STATIC => 'static',
        ];
        $previous = null;
        foreach ($tokens as $token) {
            if (is_array($token) && isset($forbidden[$token[0]])) {
                self::fail('The stub uses ' . $forbidden[$token[0]] . ' (line ' . $token[2] . '), which old PHP cannot parse.');
            }
            if (is_array($token) && $token[0] === T_FUNCTION) {
                self::fail('The stub declares a function or closure (line ' . $token[2] . ').');
            }
            if ($token === '[' && ($previous === '=' || $previous === '(' || $previous === ',' || (is_array($previous) && in_array($previous[0], [T_RETURN, T_DOUBLE_ARROW], true)))) {
                self::fail('The stub uses a short array.');
            }
            if ($token === '?') {
                self::fail('The stub uses ?: or a nullable type.');
            }
            if (!is_array($token) || $token[0] !== T_WHITESPACE) {
                $previous = $token;
            }
        }
        self::assertStringContainsString("'ThreadSafe\\Web\\Entry::run'", $stub, 'the entry is called by name from a string');
    }

    public function testTheKeyFileIsRequiredBeforeAnythingIsWrittenOnTheWeb(): void
    {
        $stub = RestoreStub::source('test');

        self::assertLessThan(strpos($stub, 'mkdir'), strpos($stub, "restore-key.php'"), 'the key check comes before the first directory is made');
    }

    public function testTheBundleAloneIsTheSameCodeThatIsInTheTool(): void
    {
        self::assertSame((new RestoreBuilder(dirname(__DIR__, 3)))->bundle(), $this->payload()['bundle.php']);
    }
}
