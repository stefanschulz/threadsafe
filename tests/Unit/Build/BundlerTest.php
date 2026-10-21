<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Build;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use ThreadSafe\Build\Bundler;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * The bundler on small source trees with every construct that decides the order of the classes or could confuse a reader of
 * tokens. (That the real sources bundle correctly is proved by `composer test:bundle`: the unit suite against the bundle.)
 */
final class BundlerTest extends TestCase
{
    use TempDirectory;

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = $this->tempDirectory();
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    private function write(string $name, string $code): string
    {
        $path = $this->directory . '/' . $name;
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, "<?php\n\ndeclare(strict_types=1);\n\n" . $code);

        return $path;
    }

    /**
     * @return list<string> the files, deliberately in an order that does not work without sorting
     */
    private function tree(): array
    {
        return [
            // the main class first, although it needs everything else
            $this->write('Main.php', <<<'PHP'
namespace App;

use App\B\Child;
use App\B\Mode as Kind;
use function strtoupper;

#[\AllowDynamicProperties]
final class Main
{
    public static function run(): string
    {
        $suffix = '!';
        $shout = static function (string $text) use ($suffix): string {
            return strtoupper($text) . $suffix;
        };
        $anonymous = new class () extends \App\A\Base {
        };

        return $shout((new Child())->say()) . ' ' . count(new Child()) . ' ' . Kind::A->value . ' ' . Child::class . ' ' . ($anonymous instanceof \App\A\Iface ? 'iface' : 'no');
    }
}
PHP),
            $this->write('B/Child.php', <<<'PHP'
namespace App\B;

use App\A\Base;
use Countable;

final class Child extends Base implements Countable
{
    use Greets, \App\B\Waves {
        Greets::hello insteadof Waves;
    }

    public function count(): int
    {
        return 3;
    }
}
PHP),
            $this->write('B/Greets.php', <<<'PHP'
namespace App\B;

trait Greets
{
    public function hello(): string
    {
        return 'hello';
    }

    public function say(): string
    {
        return $this->hello();
    }
}
PHP),
            $this->write('B/Waves.php', <<<'PHP'
namespace App\B;

trait Waves
{
    public function hello(): string
    {
        return 'wave';
    }
}
PHP),
            $this->write('B/Mode.php', <<<'PHP'
namespace App\B;

use App\A\Iface;

enum Mode: string implements Iface
{
    case A = 'a';
}
PHP),
            $this->write('A/Base.php', <<<'PHP'
namespace App\A;

abstract class Base implements Iface
{
}
PHP),
            $this->write('A/Iface.php', <<<'PHP'
namespace App\A;

interface Iface
{
}
PHP),
        ];
    }

    public function testClassesComeAfterWhatTheyExtendImplementAndUse(): void
    {
        $bundle = (new Bundler())->bundle($this->tree(), $this->directory, 'test');

        foreach ([['interface Iface', 'abstract class Base'], ['abstract class Base', 'final class Child'], ['trait Greets', 'final class Child'], ['trait Waves', 'final class Child'], ['interface Iface', 'enum Mode']] as [$first, $second]) {
            self::assertLessThan(strpos($bundle, $second), strpos($bundle, $first), "$first comes before $second");
        }
        self::assertSame(1, substr_count($bundle, 'declare(strict_types=1);'), 'declared once, at the top');
        self::assertStringContainsString('// test: 7 files', $bundle);
        self::assertStringContainsString("// A/Base.php\nnamespace App\\A {", $bundle);
    }

    public function testTheBundleIsOneFileThatRunsWithoutAnAutoloader(): void
    {
        $bundle = $this->directory . '/bundle.php';
        file_put_contents($bundle, (new Bundler())->bundle($this->tree(), $this->directory));
        token_get_all((string) file_get_contents($bundle), TOKEN_PARSE);

        $process = proc_open([PHP_BINARY, '-r', 'require $argv[1]; echo App\Main::run();', $bundle], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        proc_close($process);

        self::assertSame('', $errors);
        self::assertSame('HELLO! 3 a App\B\Child iface', $output);
    }

    public function testEveryDeclaredNameIsListed(): void
    {
        $names = (new Bundler())->declared($this->tree());
        sort($names);

        self::assertSame(['App\A\Base', 'App\A\Iface', 'App\B\Child', 'App\B\Greets', 'App\B\Mode', 'App\B\Waves', 'App\Main'], $names, 'anonymous classes and X::class are no declarations');
    }

    public function testAFileWithoutANamespaceIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has no namespace');
        (new Bundler())->bundle([$this->write('Plain.php', 'final class Plain {}')]);
    }

    public function testGroupedImportsAreRefusedInsteadOfBeingBundledWrongly(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('grouped imports');
        (new Bundler())->bundle([$this->write('Grouped.php', "namespace App;\n\nuse Other\\{A, B};\n\nfinal class Grouped {}")]);
    }

    public function testCircularInheritanceIsNamed(): void
    {
        $files = [
            $this->write('One.php', "namespace App;\n\nclass One extends Two {}"),
            $this->write('Two.php', "namespace App;\n\nclass Two extends One {}"),
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Circular inheritance');
        (new Bundler())->bundle($files);
    }

    public function testNamesAreComparedWithoutRegardToCase(): void
    {
        $files = [
            $this->write('Child.php', "namespace App;\n\nfinal class Child extends parent_class {}"),
            $this->write('Parent.php', "namespace App;\n\nclass Parent_Class {}"),
        ];

        $bundle = (new Bundler())->bundle($files);

        self::assertLessThan(strpos($bundle, 'final class Child'), strpos($bundle, 'class Parent_Class'));
    }

    public function testAMissingFileIsAnError(): void
    {
        $this->expectException(RuntimeException::class);
        (new Bundler())->bundle([$this->directory . '/nothing.php']);
    }
}
