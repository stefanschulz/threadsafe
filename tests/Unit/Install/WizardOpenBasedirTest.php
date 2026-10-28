<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Install;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Tests\Support\ReleasePackage;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * The installer on a host where PHP may use only a few directories (open_basedir, the field test of the owner): the page of the checks is made in a PHP
 * that really has the restriction and prints its warnings, and nothing is printed that is not the page. A warning would end the headers.
 */
final class WizardOpenBasedirTest extends TestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    public function testTheCheckPageOffersTheAllowedDirectoryAndPrintsNoWarning(): void
    {
        $base = $this->tempDirectory();
        $docroot = $base . '/public_html';
        mkdir($docroot);
        mkdir($base . '/private');
        copy(ReleasePackage::build($base . '/package'), $docroot . '/threadsafe-install.php');
        file_put_contents($docroot . '/threadsafe-install-key.php', "<?php return 'a long secret key';");
        $root = dirname(__DIR__, 3);
        $code = <<<'PHP'
require $argv[1] . '/vendor/autoload.php';
$wizard = new ThreadSafe\Install\Wizard($argv[2]);
$request = static fn (string $method, array $post, array $query, ?string $token) => new ThreadSafe\Web\Request($method, $query, $post, $token === null ? [] : [ThreadSafe\Install\Wizard::COOKIE => $token], false, '127.0.0.1', '/threadsafe-install.php');
$login = $wizard->handle($request('POST', ['action' => 'login', 'key' => 'a long secret key'], [], null));
preg_match('/threadsafe_install=([0-9a-f]{64})/', (string) $login->header('Set-Cookie'), $m);
$page = $wizard->handle($request('GET', [], ['step' => 'check'], $m[1] ?? ''));
echo $page->body;
PHP;

        $process = proc_open(
            [PHP_BINARY, '-d', 'open_basedir=' . $root . ':' . $docroot . ':' . $base . '/private', '-d', 'display_errors=1', '-d', 'error_reporting=-1', '-r', $code, $root, $docroot . '/threadsafe-install.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        proc_close($process);

        self::assertDoesNotMatchRegularExpression('/\b(Warning|Notice|Deprecated|Fatal error): [A-Za-z_]+\(\)/', $output . $errors, 'PHP printed a message into the page');
        self::assertStringContainsString('In a directory this host allows', $output);
        self::assertStringContainsString($base . '/private/threadsafe', $output);
        self::assertStringContainsString('open_basedir is set', $output, 'the check list says that the host restricts PHP');
        self::assertStringContainsString('value="allowed-1" checked', $output, 'the first place that works is the one that is chosen');
    }
}
