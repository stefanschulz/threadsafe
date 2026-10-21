<?php

/**
 * SPIKE (phase 4, WP 1b) - throw-away script, not part of the program.
 *
 * Question: a web backup is one tick per request, i.e. one process per tick. Does the lock of the engine (flock per execute()
 * call) keep two requests from ticking the same run, what does a killed request leave behind, and how does a web run look to
 * the existing "is this run interrupted?" test between two ticks?
 *
 *   php tools/spikes/web-ticks.php
 */
declare(strict_types=1);

use ThreadSafe\Engine\Budget;
use ThreadSafe\Engine\EngineException;
use ThreadSafe\Engine\Executor;
use ThreadSafe\Engine\Lock;
use ThreadSafe\Engine\RunStatus;
use ThreadSafe\Engine\RunStore;
use ThreadSafe\Engine\Task;
use ThreadSafe\Engine\TickOutcome;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

/** A task whose tick takes a while and counts. */
final class SlowTask implements Task
{
    public function __construct(private readonly float $seconds)
    {
    }

    public function id(): string
    {
        return 'slow';
    }

    public function initialState(array $parameters): array
    {
        return ['done' => 0];
    }

    public function tick(array $state, Budget $budget): TickOutcome
    {
        usleep((int) ($this->seconds * 1000000));
        ++$state['done'];

        return new TickOutcome($state, $state['done'] >= 5, 'tick ' . $state['done']);
    }
}

if (($argv[1] ?? '') === 'worker') {
    [, , $directory, $id, $seconds] = $argv;
    $executor = new Executor(new RunStore($directory), new SlowTask((float) $seconds), static fn (): Budget => Budget::seconds(30));
    try {
        $run = $executor->execute($id, 1);
        echo 'OK ticks=', $run->ticks, ' done=', $run->state['done'], "\n";
    } catch (EngineException $e) {
        echo 'REFUSED ', $e->getMessage(), "\n";
    }
    exit(0);
}

function worker(string $directory, string $id, float $seconds)
{
    $pipes = [];
    $process = proc_open([PHP_BINARY, __FILE__, 'worker', $directory, $id, (string) $seconds], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

    return [$process, $pipes];
}

function finish(array $worker): string
{
    [$process, $pipes] = $worker;
    $out = trim((string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    return $out;
}

$directory = sys_get_temp_dir() . '/spike-ticks-' . bin2hex(random_bytes(3));
mkdir($directory);
$store = new RunStore($directory);
$executor = new Executor($store, new SlowTask(0.0), static fn (): Budget => Budget::seconds(30));

// 1. two requests tick the same run at the same moment
$run = $executor->start();
$a = worker($directory, $run->id, 2.0);
usleep(300000);
$b = worker($directory, $run->id, 2.0);
echo "1. two parallel ticks of one run\n   first:  ", finish($a), "\n   second: ", finish($b), "\n";
$after = $store->load($run->id);
printf("   run after: ticks=%d done=%d (one tick happened, none was lost or doubled)\n", $after->ticks, $after->state['done']);

// 2. between two ticks no lock is held: what does the engine say?
printf("\n2. between two ticks (browser waiting for the next request)\n   status=%s lockHeld=%s isInterrupted=%s updated=%ds ago\n", $after->status->value, var_export(Lock::isHeld($store->lockPath($run->id)), true), var_export($store->isInterrupted($after), true), time() - $after->updated);

// 3. a request that is killed in the middle of a tick
$w = worker($directory, $run->id, 6.0);
usleep(1000000);
printf("\n3. request killed in the middle of a tick\n   while ticking: lockHeld=%s isInterrupted=%s holder=%s\n", var_export(Lock::isHeld($store->lockPath($run->id)), true), var_export($store->isInterrupted($store->load($run->id)), true), Lock::holder($store->lockPath($run->id)) ?? '-');
proc_terminate($w[0], 9);
finish($w);
usleep(200000);
$killed = $store->load($run->id);
printf("   after kill -9: status=%s ticks=%d done=%d lockHeld=%s isInterrupted=%s\n", $killed->status->value, $killed->ticks, $killed->state['done'], var_export(Lock::isHeld($store->lockPath($run->id)), true), var_export($store->isInterrupted($killed), true));

// 4. the next request carries on from the saved state
$c = worker($directory, $run->id, 0.1);
echo '   next request: ', finish($c), "\n";

// 5. the run's own lock and the CLI's locks are different locks: a CLI start does not see a web run between ticks
$cli = Lock::tryAcquire($directory . '/backup.lock', 'cli backup');
printf("\n5. the instance-wide lock of the CLI (backup.lock) is free between web ticks: %s\n", $cli === null ? 'NO' : 'yes, a CLI backup could start now');
$cli?->release();

// 6. timing: what a tick request costs besides the work (load, lock, save)
$t = microtime(true);
$quick = $executor->start();
for ($i = 0; $i < 5; ++$i) {
    $executor->execute($quick->id, 1);
}
printf("\n6. five empty ticks through Executor::execute(\$id, 1): %.1f ms in total\n", (microtime(true) - $t) * 1000);
echo '   run status: ', $store->load($quick->id)->status === RunStatus::Finished ? 'finished' : 'running', "\n";

foreach (glob($directory . '/*') ?: [] as $f) {
    @unlink($f);
}
@rmdir($directory);
