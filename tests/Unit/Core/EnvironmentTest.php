<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Core\Environment;
use ThreadSafe\Core\Home;
use ThreadSafe\Profile\Check;
use ThreadSafe\Profile\CheckLevel;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * The one list of checks (docs/PHASE7.md 3.4): the requirements of a release against this host, the data directory, the installation.
 */
final class EnvironmentTest extends TestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    /**
     * @param list<Check> $checks
     *
     * @return list<string> "level: message" of the checks of one level
     */
    private static function of(array $checks, CheckLevel $level): array
    {
        return array_values(array_map(static fn (Check $c): string => $c->message, array_filter($checks, static fn (Check $c): bool => $c->level === $level)));
    }

    public function testAHostThatMeetsTheRequirementsGetsOneOkLine(): void
    {
        $checks = Environment::requirements('0.2.0', '8.4', ['required' => ['json', 'zlib'], 'oneOf' => [['pdo_mysql', 'mysqli']], 'recommended' => []]);

        self::assertCount(1, $checks);
        self::assertSame(CheckLevel::Ok, $checks[0]->level);
        self::assertSame('Version 0.2.0', $checks[0]->subject);
    }

    public function testAPhpThatIsTooOldIsAFailureThatSaysWhatToAskFor(): void
    {
        $checks = Environment::requirements('9.0.0', '99.0', ['required' => [], 'oneOf' => [], 'recommended' => []]);

        $failures = self::of($checks, CheckLevel::Failure);
        self::assertCount(1, $failures);
        self::assertStringContainsString('needs PHP 99.0 or newer', $failures[0]);
        self::assertStringContainsString('this host has PHP ' . PHP_VERSION, $failures[0]);
        self::assertStringContainsString('Ask your host', $failures[0]);
        self::assertSame([], self::of($checks, CheckLevel::Ok), 'no "meets its requirements" next to a failure');
    }

    public function testAMissingRequiredExtensionIsAFailureWithItsConsequence(): void
    {
        $checks = Environment::requirements('1.0.0', '8.4', ['required' => ['json', 'no_such_extension', 'sodium'], 'oneOf' => [], 'recommended' => []]);

        $failures = self::of($checks, CheckLevel::Failure);
        self::assertCount(1 + (extension_loaded('sodium') ? 0 : 1), $failures);
        self::assertStringContainsString('no_such_extension (it does not work without it)', $failures[0]);
    }

    public function testAKnownExtensionNamesWhatIsLostWithoutIt(): void
    {
        $checks = Environment::requirements('1.0.0', '8.4', ['required' => [], 'oneOf' => [], 'recommended' => ['no_such_extension', 'sodium']]);

        $warnings = self::of($checks, CheckLevel::Warning);
        self::assertStringContainsString('recommends the PHP extension no_such_extension; without it some features are missing', $warnings[0]);
        if (!extension_loaded('sodium')) {
            self::assertStringContainsString('backups cannot be encrypted', $warnings[1]);
        }
    }

    public function testOneOfASetOfExtensionsIsEnough(): void
    {
        $enough = Environment::requirements('1.0.0', '8.4', ['required' => [], 'oneOf' => [['no_such_extension', 'json']], 'recommended' => []]);
        $none = Environment::requirements('1.0.0', '8.4', ['required' => [], 'oneOf' => [['no_such_extension', 'another_missing_one']], 'recommended' => []]);

        self::assertSame(CheckLevel::Ok, $enough[0]->level);
        self::assertSame(CheckLevel::Warning, $none[0]->level);
        self::assertStringContainsString('one of the PHP extensions no_such_extension, another_missing_one', $none[0]->message);
    }

    public function testADataDirectoryThatDoesNotExistYetIsFineIfItCanBeMade(): void
    {
        $checks = Environment::dataDirectory($this->tempDirectory() . '/data');

        self::assertCount(1, $checks);
        self::assertSame(CheckLevel::Info, $checks[0]->level);
        self::assertStringContainsString('does not exist yet', $checks[0]->message);
    }

    public function testADataDirectoryThatCannotBeMadeIsAFailure(): void
    {
        $file = $this->tempDirectory() . '/a-file';
        file_put_contents($file, 'x');

        $checks = Environment::dataDirectory($file . '/data');

        self::assertSame(CheckLevel::Failure, $checks[0]->level);
        self::assertStringContainsString('cannot be made', $checks[0]->message);
    }

    public function testAClosedDataDirectoryIsOk(): void
    {
        $data = $this->tempDirectory();
        chmod($data, 0700);
        file_put_contents($data . '/.htaccess', 'Require all denied');

        $checks = Environment::dataDirectory($data);

        self::assertCount(1, $checks);
        self::assertSame(CheckLevel::Ok, $checks[0]->level);
    }

    public function testADataDirectoryThatOthersCanReadOrFetchIsWarnedAbout(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('File modes.');
        }
        $data = $this->tempDirectory();
        chmod($data, 0755);
        file_put_contents($data . '/setup-key.php', "<?php return 'x';");

        $warnings = self::of(Environment::dataDirectory($data), CheckLevel::Warning);

        self::assertCount(3, $warnings);
        self::assertStringContainsString('0755', $warnings[0]);
        self::assertStringContainsString('no .htaccess', $warnings[1]);
        self::assertStringContainsString('setup-key.php is still in the data directory', $warnings[2]);
    }

    public function testACloneSaysHowItIsUpdated(): void
    {
        $checks = Environment::installation();

        self::assertCount(1, $checks);
        self::assertSame('Installation', $checks[0]->subject);
        self::assertStringContainsString('installed from a clone: update it with git pull and composer install', $checks[0]->message);
    }

    /** A home with the releases unpacked and a pointer to the first; returns the home. */
    private function home(string $active, ?string $previous, string ...$releases): string
    {
        $home = $this->tempDirectory();
        foreach ($releases as $release) {
            mkdir($home . '/releases/' . $release . '/vendor', 0777, true);
            file_put_contents($home . '/releases/' . $release . '/vendor/autoload.php', '<?php');
        }
        Home::writePointer($home, $active, $previous);

        return $home;
    }

    public function testAnInstallationFromAReleaseNamesItsHomeAndThePreviousRelease(): void
    {
        $home = $this->home('0.2.0', '0.1.0', '0.1.0', '0.2.0');

        $checks = Environment::installation($home . '/releases/0.2.0');

        self::assertStringContainsString('from a release; the home is "' . $home . '"', $checks[0]->message);
        self::assertStringContainsString('previous release 0.1.0 is kept for a roll-back', $checks[1]->message);
        self::assertSame([], self::of($checks, CheckLevel::Failure));
    }

    public function testADamagedPointerIsAFailure(): void
    {
        $home = $this->home('0.1.0', null, '0.1.0');
        file_put_contents($home . '/current.php', 'broken');

        $failures = self::of(Environment::installation($home . '/releases/0.1.0'), CheckLevel::Failure);

        self::assertCount(1, $failures);
        self::assertStringContainsString('pointer to the active release', $failures[0]);
    }

    public function testACommandOfAnotherReleaseThanTheActiveOneSaysSo(): void
    {
        $home = $this->home('0.2.0', '0.1.0', '0.1.0', '0.2.0');

        $checks = Environment::installation($home . '/releases/0.1.0');

        self::assertStringContainsString('The active release is 0.2.0; this command runs 0.1.0.', $checks[1]->message);
    }

    public function testTheRuntimeNamesPhpAndTheLimitsAndHasNoFailureHere(): void
    {
        $checks = Environment::runtime();

        self::assertSame('PHP', $checks[0]->subject);
        self::assertStringContainsString(PHP_VERSION, $checks[0]->message);
        self::assertSame([], self::of($checks, CheckLevel::Failure));
    }

    public function testTheConnectionIsJudgedByHttpsAndByWhereItComesFrom(): void
    {
        self::assertSame(CheckLevel::Ok, Environment::connection(true, false)->level);
        self::assertSame(CheckLevel::Info, Environment::connection(false, true)->level);
        self::assertSame(CheckLevel::Warning, Environment::connection(false, false)->level);
    }
}
