<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Build;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Build\Bundler;
use ThreadSafe\Build\RestoreBuilder;
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
        self::$built = sys_get_temp_dir() . '/threadsafe-test-build-' . bin2hex(random_bytes(4)) . '/threadsafe-restore.php';
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
        self::assertLessThan(450 * 1024, strlen($file), 'a few hundred KB, not megabytes');
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
        self::assertStringNotContainsString('namespace ThreadSafe\\Admin', $entries['bundle.php'], 'the web interface is not part of the restore tool');
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

    public function testTheBundleAloneIsTheSameCodeThatIsInTheTool(): void
    {
        self::assertSame((new RestoreBuilder(dirname(__DIR__, 3)))->bundle(), $this->payload()['bundle.php']);
    }
}
