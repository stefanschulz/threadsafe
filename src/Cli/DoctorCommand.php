<?php

declare(strict_types=1);

namespace ThreadSafe\Cli;

use InvalidArgumentException;
use ThreadSafe\Build\ReleaseManifest;
use ThreadSafe\Core\Environment;
use ThreadSafe\Core\ExitCode;
use ThreadSafe\Core\Paths;
use ThreadSafe\Profile\Check;
use ThreadSafe\Profile\CheckLevel;

/**
 * `threadsafe doctor`: what this server, this PHP and this installation allow (docs/PHASE7.md 3.4). The same list of checks as the
 * installation check of the System page, for the command line: before the web interface is switched on, on a host that is being set
 * up, and in support. With `--release=release.json` it also says whether this host can run that version.
 */
final class DoctorCommand implements Command
{
    /**
     * @param string|null $dataDirectory the data directory to look at; default: the one of the program
     */
    public function __construct(private readonly ?string $dataDirectory = null)
    {
    }

    public function name(): string
    {
        return 'doctor';
    }

    public function summary(): string
    {
        return 'Check this server, PHP and the installation; say what to ask the host for';
    }

    public function usage(): string
    {
        return 'doctor [--release=release.json]';
    }

    public function options(): array
    {
        return [
            new OptionSpec('release', 'also check whether this host meets the requirements in this release.json (from a release package)', takesValue: true, valueName: 'FILE'),
        ];
    }

    public function run(Input $input, Output $output): ExitCode
    {
        if ($input->arguments() !== []) {
            throw new UsageError('Unexpected argument "' . $input->arguments()[0] . '".');
        }
        $data = $this->dataDirectory ?? Paths::data();

        $checks = [...Environment::runtime(), ...Environment::installation(), ...Environment::dataDirectory($data)];
        if (is_dir($data . '/profiles')) {
            $checks = [...$checks, ...Environment::profiles($data)];
        }
        if ($input->has('release')) {
            $checks = [...$checks, ...$this->release((string) $input->value('release'))];
        }

        $warnings = 0;
        $failures = 0;
        foreach ($checks as $check) {
            $warnings += $check->level === CheckLevel::Warning ? 1 : 0;
            $failures += $check->level === CheckLevel::Failure ? 1 : 0;
            $output->writeln(sprintf('%-8s %s: %s', self::label($check), $check->subject, $check->message));
        }
        $output->writeln('');
        $output->writeln($failures > 0
            ? sprintf('%d problem%s that must be fixed, %d warning%s.', $failures, $failures === 1 ? '' : 's', $warnings, $warnings === 1 ? '' : 's')
            : ($warnings > 0 ? sprintf('Nothing stops ThreadSafe; %d warning%s.', $warnings, $warnings === 1 ? '' : 's') : 'Everything is in order.'));

        return $failures > 0 ? ExitCode::GuardFailed : ExitCode::Ok;
    }

    /**
     * @return list<Check>
     */
    private function release(string $file): array
    {
        $json = is_file($file) ? @file_get_contents($file) : false;
        if ($json === false) {
            throw new UsageError("Cannot read \"$file\".");
        }
        try {
            $manifest = ReleaseManifest::fromJson($json);
        } catch (InvalidArgumentException $e) {
            throw new UsageError("\"$file\" is not a release.json: " . $e->getMessage());
        }

        return Environment::requirements($manifest->version, $manifest->php, $manifest->extensions);
    }

    private static function label(Check $check): string
    {
        return match ($check->level) {
            CheckLevel::Ok => 'ok',
            CheckLevel::Info => 'info',
            CheckLevel::Warning => 'WARNING',
            CheckLevel::Failure => 'FAILED',
        };
    }
}
