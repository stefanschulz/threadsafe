<?php

declare(strict_types=1);

namespace ThreadSafe\PrestaShop9\Steps;

use Closure;
use ThreadSafe\Core\AlwaysRuns;
use ThreadSafe\Core\Context;
use ThreadSafe\Core\Notice;
use ThreadSafe\Core\Plan;
use ThreadSafe\Core\StepResult;
use ThreadSafe\PrestaShop9\ShopAddress;
use ThreadSafe\PrestaShop9\State;

/**
 * Lets PrestaShop itself tidy up after the migration: boots it in a child process (`resources/prestashop9/finalize.php`)
 * and calls `Tools::clearAllCache()`. After a change of the base path it also has PrestaShop regenerate `.htaccess`
 * and `robots.txt` (both hold the path in many places); in dev mode `robots.txt` is left alone, the search engine
 * block owns it.
 *
 * Optional and never fatal: whatever the migration did stays valid. If PrestaShop cannot be booted here (a PHP without
 * the extensions it needs, a different PHP than the web server's) the step says so in a warning.
 */
final class Finalize implements AlwaysRuns
{
    private const int TIMEOUT_SECONDS = 180;

    /**
     * @param Closure(list<string>, string): array{int, string, string}|null $runner for tests: runs a command in a directory and
     *                                                                              returns exit code, stdout and stderr
     */
    public function __construct(private readonly ?Closure $runner = null)
    {
    }

    public function id(): string
    {
        return 'finalize';
    }

    public function title(): string
    {
        return 'Let PrestaShop clear its caches itself';
    }

    public function plan(Context $context): Plan
    {
        $request = State::request($context);
        if (!$request->finalize) {
            return (new Plan())->notice(Notice::info('Finalizing is switched off (--no-finalize).'));
        }

        return (new Plan())->notice(Notice::info('After the migration PrestaShop is started in a separate process to clear its caches' . ($request->physicalUri !== null ? ' and to regenerate .htaccess and robots.txt for the new base path' : '') . '.'));
    }

    public function apply(Context $context, Plan $plan): StepResult
    {
        $request = State::request($context);
        if (!$request->finalize) {
            return new StepResult(0);
        }

        $root = State::source($context)->root;
        $options = json_encode([
            'root' => $root,
            'host' => ShopAddress::of($context)->host,
            'regenerate' => $request->physicalUri !== null,
            'robots' => !$request->isDevMode(),
        ], JSON_THROW_ON_ERROR);
        $command = [PHP_BINARY, dirname(__DIR__, 3) . '/resources/prestashop9/finalize.php', $options];

        [$code, $stdout, $stderr] = ($this->runner ?? $this->run(...))($command, $root);

        if ($code !== 0) {
            $reason = trim($stderr) !== '' ? trim($stderr) : trim($stdout);
            $context->logger->warning('PrestaShop could not finalize: ' . $reason);

            return new StepResult(0, [Notice::warning('PrestaShop could not be started to clear its caches itself (' . $this->firstLine($reason) . '). The migration stays valid; the caches were removed by the earlier step, PrestaShop rebuilds them on the first visit.', 'finalize')]);
        }

        $data = json_decode(trim($stdout), true);
        $done = is_array($data) && is_array($data['done'] ?? null) ? implode(', ', array_map('strval', $data['done'])) : 'done';

        return new StepResult(0, [Notice::info("PrestaShop finished: $done.", 'finalize')]);
    }

    private function firstLine(string $text): string
    {
        $line = strtok($text, "\n");

        return $line === false ? 'no details' : (strlen($line) > 200 ? substr($line, 0, 197) . '...' : $line);
    }

    /**
     * @param list<string> $command
     *
     * @return array{int, string, string}
     */
    private function run(array $command, string $directory): array
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $directory);
        if (!is_resource($process)) {
            return [1, '', 'Cannot start ' . PHP_BINARY . '.'];
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = time() + self::TIMEOUT_SECONDS;
        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if (time() > $deadline) {
                proc_terminate($process);
                proc_close($process);

                return [1, $stdout, 'Timed out after ' . self::TIMEOUT_SECONDS . ' seconds.'];
            }
            usleep(50000);
        }
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return [$status['exitcode'] >= 0 ? $status['exitcode'] : $exit, $stdout, $stderr];
    }
}
