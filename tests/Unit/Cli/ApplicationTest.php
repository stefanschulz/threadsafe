<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Cli;

use Closure;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ThreadSafe\Cli\Application;
use ThreadSafe\Cli\BufferedOutput;
use ThreadSafe\Cli\Input;
use ThreadSafe\Cli\Output;
use ThreadSafe\Cli\UsageError;
use ThreadSafe\Core\Exception\GuardFailed;
use ThreadSafe\Core\Exception\SelfTestFailed;
use ThreadSafe\Core\Exception\StepFailed;
use ThreadSafe\Core\ExitCode;
use ThreadSafe\Core\Version;
use ThreadSafe\Tests\Support\FakeCommand;

final class ApplicationTest extends TestCase
{
    /**
     * @param Closure(Input, Output): ExitCode $behaviour
     */
    private function application(Closure $behaviour): Application
    {
        return new Application([new FakeCommand('demo', $behaviour), new FakeCommand('alpha', static fn (): ExitCode => ExitCode::Ok)]);
    }

    private function ok(): Closure
    {
        return static fn (): ExitCode => ExitCode::Ok;
    }

    /**
     * @param list<string> $arguments
     */
    private function execute(Application $application, array $arguments, ?BufferedOutput $output = null): int
    {
        return $application->run(['threadsafe', ...$arguments], $output ?? new BufferedOutput());
    }

    public function testNoArgumentsShowTheOverview(): void
    {
        $output = new BufferedOutput();

        $code = $this->execute($this->application($this->ok()), [], $output);

        self::assertSame(0, $code);
        self::assertStringContainsString('Commands:', $output->stdout());
        self::assertStringContainsString('Summary of demo', $output->stdout());
        self::assertStringContainsString('Exit codes: 0 ok, 1 usage error', $output->stdout());
        self::assertLessThan(strpos($output->stdout(), 'demo'), strpos($output->stdout(), 'alpha'), 'Commands are listed alphabetically.');
    }

    public function testListAndHelpFlagShowTheOverviewToo(): void
    {
        foreach ([['list'], ['--help'], ['-h'], ['help']] as $arguments) {
            $output = new BufferedOutput();

            self::assertSame(0, $this->execute($this->application($this->ok()), $arguments, $output));
            self::assertStringContainsString('Commands:', $output->stdout());
        }
    }

    public function testVersion(): void
    {
        $output = new BufferedOutput();

        self::assertSame(0, $this->execute($this->application($this->ok()), ['--version'], $output));
        self::assertSame('threadsafe ' . Version::CURRENT . "\n", $output->stdout());
    }

    public function testHelpForACommand(): void
    {
        $output = new BufferedOutput();

        $code = $this->execute($this->application($this->ok()), ['help', 'demo'], $output);

        self::assertSame(0, $code);
        self::assertStringContainsString('threadsafe demo <argument> [options]', $output->stdout());
        self::assertStringContainsString('--root=PATH', $output->stdout());
        self::assertStringContainsString('--verbose', $output->stdout(), 'Global options are listed too.');
    }

    public function testHelpOptionOfACommandDoesNotRunIt(): void
    {
        $ran = false;
        $output = new BufferedOutput();

        $code = $this->execute($this->application(function () use (&$ran): ExitCode {
            $ran = true;

            return ExitCode::Ok;
        }), ['demo', '--help'], $output);

        self::assertSame(0, $code);
        self::assertFalse($ran);
        self::assertStringContainsString('Usage:', $output->stdout());
    }

    public function testUnknownCommandIsAUsageError(): void
    {
        $output = new BufferedOutput();

        $code = $this->execute($this->application($this->ok()), ['nope'], $output);

        self::assertSame(ExitCode::Usage->value, $code);
        self::assertStringContainsString('Unknown command "nope"', $output->stderr());
        self::assertSame('', $output->stdout());
    }

    public function testHelpForAnUnknownCommandIsAUsageError(): void
    {
        self::assertSame(1, $this->execute($this->application($this->ok()), ['help', 'nope']));
    }

    public function testUnknownOptionIsAUsageErrorWithAHint(): void
    {
        $output = new BufferedOutput();

        $code = $this->execute($this->application($this->ok()), ['demo', '--bogus'], $output);

        self::assertSame(1, $code);
        self::assertStringContainsString('Unknown option --bogus', $output->stderr());
        self::assertStringContainsString('threadsafe help demo', $output->stderr());
    }

    public function testCommandReceivesParsedInputAndItsExitCodeIsReturned(): void
    {
        $received = null;
        $application = $this->application(function (Input $input) use (&$received): ExitCode {
            $received = [$input->arguments(), $input->value('root')];

            return ExitCode::StepFailed;
        });

        $code = $this->execute($application, ['demo', 'prestashop9', '--root=/shop']);

        self::assertSame(3, $code);
        self::assertSame([['prestashop9'], '/shop'], $received);
    }

    public function testExceptionsAreMappedToExitCodes(): void
    {
        $cases = [
            [new UsageError('bad arguments'), 1, 'error: bad arguments'],
            [new GuardFailed('wrong site'), 2, 'Refused: wrong site'],
            [new StepFailed('disk full'), 3, 'error: disk full'],
            [new SelfTestFailed('home page 500'), 4, 'error: home page 500'],
            [new RuntimeException('bug'), 5, 'Internal error: bug'],
        ];

        foreach ($cases as [$exception, $expectedCode, $expectedMessage]) {
            $output = new BufferedOutput();
            $application = $this->application(static function () use ($exception): never {
                throw $exception;
            });

            $code = $this->execute($application, ['demo'], $output);

            self::assertSame($expectedCode, $code, $exception::class);
            self::assertStringContainsString($expectedMessage, $output->stderr(), $exception::class);
        }
    }

    public function testInternalErrorShowsTheTraceOnlyWhenVerbose(): void
    {
        $failing = static function (): never {
            throw new RuntimeException('bug');
        };

        $quiet = new BufferedOutput();
        $this->execute($this->application($failing), ['demo'], $quiet);
        $verbose = new BufferedOutput(verbose: true);
        $this->execute($this->application($failing), ['demo'], $verbose);

        self::assertStringNotContainsString('#0', $quiet->stderr());
        self::assertStringContainsString('#0', $verbose->stderr());
    }

    public function testExitCodeValues(): void
    {
        self::assertSame(
            ['Ok' => 0, 'Usage' => 1, 'GuardFailed' => 2, 'StepFailed' => 3, 'SelfTestFailed' => 4, 'Internal' => 5],
            array_column(array_map(static fn (ExitCode $c): array => [$c->name, $c->value], ExitCode::cases()), 1, 0),
        );
    }
}
