<?php

declare(strict_types=1);

namespace ThreadSafe\Admin;

use ThreadSafe\Core\Environment;
use ThreadSafe\Profile\Check;

/**
 * The installation check of the System page (docs/PHASE4.md 3.9): what this server, this PHP and this installation allow, and what is
 * exposed that should not be. The checks themselves are {@see Environment}, which `threadsafe doctor`, the installer and the update use
 * too. This looks and reports; it changes nothing except the probe file for the question whether `data/` can be fetched.
 */
final class SystemChecks
{
    /** What the probe file holds; the page's script fetches the file from where `data/` would be served and compares. */
    public const string PROBE = 'threadsafe-data-probe';

    public function __construct(private readonly Services $services)
    {
    }

    /**
     * @return list<Check>
     */
    public function run(bool $https, bool $local): array
    {
        $runtime = Environment::runtime();
        // the page's own connection comes after the facts about PHP and before the process, as it always did
        $process = array_values(array_filter($runtime, static fn (Check $check): bool => $check->subject === 'Process'));
        $php = array_values(array_filter($runtime, static fn (Check $check): bool => $check->subject !== 'Process'));

        return [
            ...$php,
            Environment::connection($https, $local),
            ...$process,
            ...Environment::installation(),
            ...Environment::dataDirectory($this->services->data),
            ...Environment::profiles($this->services->data),
        ];
    }

    /**
     * Writes the file the browser is asked for to find out whether `data/` can be fetched from outside. Where it would be served
     * is not known to PHP (it depends on the document root), so the page's script asks for the file at the addresses where it would
     * be if the program directory lay below the web root.
     *
     * @return bool the file is there
     */
    public function writeProbe(): bool
    {
        $file = $this->services->data . '/probe.txt';
        if (!is_file($file) || trim((string) @file_get_contents($file)) !== self::PROBE) {
            @file_put_contents($file, self::PROBE . "\n");
        }

        return is_file($file);
    }
}
