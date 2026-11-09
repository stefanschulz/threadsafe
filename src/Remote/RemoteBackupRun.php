<?php

declare(strict_types=1);

namespace ThreadSafe\Remote;

use Closure;
use ThreadSafe\Backup\BackupName;
use ThreadSafe\Backup\BackupState;
use ThreadSafe\Backup\Sidecar;
use ThreadSafe\Core\Exception\GuardFailed;
use ThreadSafe\Core\Exception\StepFailed;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Engine\TickOutcome;
use ThreadSafe\Profile\ProfileException;
use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Profile\RemoteSettings;
use ThreadSafe\Profile\SiteProfile;
use ThreadSafe\Storage\LocalStorage;
use ThreadSafe\Web\AgentConfig;
use Throwable;

/**
 * The backup of a remote profile (docs/PHASE13.md 3.3): an agent on the site makes the backup and this machine fetches it. It is a phase of the backup run
 * ({@see BackupState::REMOTE}, started by {@see \ThreadSafe\Backup\BackupRun}), so that the schedule, the trigger, `backup <profile>`, the web interface, retention and
 * the catalog treat it like any other backup. Like every task it holds no state: what it needs to go on is in `$state->remote`, saved after every tick.
 *
 * Steps: **start** (ask the agent for its state, take up a backup that an earlier transfer left on the site, or start a new one) - **run** (a step of the
 * backup on the site per call) - **list** (the parts and their SHA-256) - **pull** (every part and the sidecar file, with Range, into the work directory of the
 * storage, each checked) - **store** (the files into the storage, the sidecar file last: the catalog lists by it) - **done** (tell the site that this machine
 * holds the backup, so that it keeps its newest by its own rule) - **finish** (the result). A part is in the storage only when whole and right.
 *
 * A failure that may pass (the site is not reachable, a gateway has no answer, a part arrived wrong) is tried again after a pause that doubles; after
 * {@see MAX_RETRIES} tries in a row the run fails. A failure that will not pass (the agent says no, the clocks differ, the agent speaks another version, the
 * backup on the site failed) ends the run with a sentence that says what to do. A run that is given up asks the agent to give up its run, and removes what it fetched.
 */
final class RemoteBackupRun
{
    /** Tries in a row that fail in a way that may pass before the run gives up. */
    public const int MAX_RETRIES = 6;

    /** A backup that an earlier transfer left on the site is taken up (instead of making a new one) if it is not older than this many seconds. */
    public const int CATCH_UP_SECONDS = 86400;

    private readonly Closure $clock;

    /** @var Closure(int): void */
    private readonly Closure $sleep;

    /** @var Closure(RemoteSettings): AgentClient */
    private readonly Closure $clients;

    /**
     * @param (Closure(RemoteSettings): AgentClient)|null $clients how to reach an agent; default: curl, or PHP streams without curl
     * @param Closure(): int|null                         $clock   for tests: the time in seconds
     * @param (Closure(int): void)|null                   $sleep   for tests: waits this many seconds
     */
    public function __construct(private readonly ProfileStore $profiles, ?Closure $clients = null, ?Closure $clock = null, ?Closure $sleep = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
        $this->clients = $clients ?? static fn (RemoteSettings $remote): AgentClient => new AgentClient($remote, function_exists('curl_init') ? new CurlTransport() : new StreamTransport());
    }

    /**
     * The state of a new remote run.
     *
     * @return array<string, mixed>
     */
    public static function initial(): array
    {
        return ['step' => 'start', 'run' => '', 'backup' => '', 'parts' => [], 'sidecar' => null, 'percent' => 0, 'retries' => 0, 'doneTries' => 0, 'waitUntil' => 0, 'lastError' => '', 'caughtUp' => false];
    }

    public function tick(BackupState $s, Budget $budget): TickOutcome
    {
        $profile = $this->profile($s);
        $remote = $profile->remote ?? throw new GuardFailed("The profile \"{$profile->id}\" is not a remote profile.");
        $client = ($this->clients)($remote);
        $r = array_replace(self::initial(), $s->remote ?? []);
        if ($s->started === 0) {
            $s->started = ($this->clock)();
        }
        $progress = 'starting';
        $worked = false;

        while (true) {
            if ($worked && $budget->exhausted()) {
                break;
            }
            $now = ($this->clock)();
            if ($r['waitUntil'] > $now) {
                $wait = $r['waitUntil'] - $now;
                $remaining = $budget->remaining();
                if ($remaining !== null) {
                    $progress = "waiting {$wait} s before trying again: " . $r['lastError'];
                    break;   // a tick of the web interface or of the trigger does not sleep: the next one looks again
                }
                ($this->sleep)($wait);
                continue;
            }
            $worked = true;
            try {
                $progress = $this->unit($s, $profile, $client, $r, $budget);
                $r['retries'] = 0;
                $r['lastError'] = '';
            } catch (AgentException $e) {
                $this->fail($e, $r);
                $progress = $r['lastError'] !== '' ? 'trying again soon: ' . $r['lastError'] : $progress;
            }
            if ($s->phase === BackupState::DONE) {
                break;
            }
        }

        $s->remote = $r;

        return new TickOutcome($s->toArray(), $s->phase === BackupState::DONE, $progress);
    }

    /**
     * Gives a run up: asks the agent to give its run up (as far as that is still going) and removes what was fetched.
     */
    public function abandon(BackupState $s): void
    {
        $r = $s->remote ?? [];
        try {
            $profile = $this->profile($s);
            if (is_string($r['run'] ?? null) && $r['run'] !== '' && in_array($r['step'] ?? '', ['run'], true) && $profile->remote !== null) {
                ($this->clients)($profile->remote)->abort($r['run']);
            }
            $name = BackupName::parse($s->backup);
            if ($name !== null) {
                (new LocalStorage($profile->storage))->removeBackup($name);
            }
        } catch (Throwable) {
            // the site cannot be reached, or there is nothing left to remove: nothing to be done about either
        }
    }

    // ------------------------------------------------------------------------------------------------- one unit of work

    /**
     * Does one thing and returns the line that says what it was.
     *
     * @param array<string, mixed> $r the state of the remote run, changed here
     *
     * @throws AgentException      the site could not be reached, or said something that is not right
     * @throws GuardFailed|StepFailed what will not pass
     */
    private function unit(BackupState $s, SiteProfile $profile, AgentClient $client, array &$r, Budget $budget): string
    {
        return match ($r['step']) {
            'start' => $this->start($s, $profile, $client, $r),
            'run' => $this->run($client, $r),
            'list' => $this->list($s, $profile, $client, $r),
            'pull' => $this->pull($profile, $client, $r, $budget),
            'store' => $this->store($profile, $r),
            'done' => $this->done($s, $client, $r),
            default => $this->finish($s, $profile, $r),
        };
    }

    /**
     * @param array<string, mixed> $r
     */
    private function start(BackupState $s, SiteProfile $profile, AgentClient $client, array &$r): string
    {
        $status = $client->status();
        if (($status['api'] ?? null) !== AgentConfig::API_VERSION) {
            throw new GuardFailed('The agent on the site speaks version ' . var_export($status['api'] ?? '?', true) . ' of its API and this ThreadSafe speaks version ' . AgentConfig::API_VERSION . ': update the older side (the plugin or the tool on the site, or this ThreadSafe).');
        }
        $remoteProfile = (array) ($status['profile'] ?? []);
        if (($remoteProfile['id'] ?? null) !== $profile->id) {
            throw new GuardFailed('The agent on the site makes its backups under the profile id "' . (is_string($remoteProfile['id'] ?? null) ? $remoteProfile['id'] : '?') . '", and this profile is "' . $profile->id . '": the central catalog finds a backup by that id. Pair again with the same id.');
        }
        if (($remoteProfile['ready'] ?? false) !== true) {
            throw new GuardFailed('The agent on the site has no backup profile ready: finish the setup on the site (the page "The agent" of the tool, or the plugin).');
        }

        // a backup that an earlier transfer left on the site (the site keeps its newest for that) is taken up instead of making another one
        $storage = new LocalStorage($profile->storage);
        $have = [];
        foreach ($storage->names($profile->id) as $name) {
            $have[$name->base()] = true;
        }
        foreach ($client->backups() as $entry) {
            $name = BackupName::parse($entry['backup']);
            if ($entry['complete'] && $name !== null && $name->profile === $profile->id && !isset($have[$entry['backup']])
                && ($this->clock)() - $name->time->getTimestamp() <= self::CATCH_UP_SECONDS) {
                $r['backup'] = $entry['backup'];
                $r['step'] = 'list';
                $r['caughtUp'] = true;
                $r['percent'] = 5;
                $s->warn('The site still held the backup ' . $entry['backup'] . ' that an earlier transfer had not brought over: it was fetched instead of making a new one.');

                return 'taking up the backup ' . $entry['backup'] . ' that the site still holds';
            }
        }

        $started = $client->start();
        $r['run'] = $started['run'];
        $r['step'] = 'run';
        $r['percent'] = 2;

        return $started['resumed'] ? 'the backup on the site was already going on: taking it up' : 'the backup is started on the site';
    }

    /**
     * @param array<string, mixed> $r
     */
    private function run(AgentClient $client, array &$r): string
    {
        $answer = $client->tick((string) $r['run']);
        if ($answer['status'] === 'failed') {
            throw new StepFailed('The backup on the site failed: ' . ($answer['error'] !== '' ? $answer['error'] : 'the agent gives no reason') . ' (the log of the site may say more).');
        }
        $r['percent'] = 2 + (int) round(max(0, min(100, $answer['percent'])) * 0.5);   // the first half of the bar is the work on the site
        if ($answer['status'] === 'finished') {
            if (BackupName::parse($answer['backup']) === null) {
                throw new StepFailed('The agent says the backup is finished but names no backup.');
            }
            $r['backup'] = $answer['backup'];
            $r['step'] = 'list';

            return 'the backup on the site is made: ' . $answer['backup'];
        }

        return 'on the site: ' . ($answer['progress'] !== '' ? $answer['progress'] : 'working');
    }

    /**
     * @param array<string, mixed> $r
     */
    private function list(BackupState $s, SiteProfile $profile, AgentClient $client, array &$r): string
    {
        $name = BackupName::parse((string) $r['backup']);
        if ($name === null || $name->profile !== $profile->id) {
            throw new GuardFailed('The backup on the site is named "' . $r['backup'] . '", which is not a backup of the profile "' . $profile->id . '".');
        }
        $found = null;
        foreach ($client->backups() as $entry) {
            if ($entry['backup'] === $r['backup'] && $entry['complete']) {
                $found = $entry;
            }
        }
        if ($found === null || $found['parts'] === [] || $found['sidecar'] === null) {
            throw new StepFailed('The site lists no complete backup named "' . $r['backup'] . '" (with its parts and its sidecar file): it may have been removed on the site.');
        }
        // what the site names is not trusted as a file name: only the names that belong to this backup
        $files = [];
        foreach ($found['parts'] as $part) {
            $parsed = BackupName::fromFile($part['file']);
            if ($parsed === null || $parsed['name']->base() !== $name->base() || $parsed['extension'] === 'json' || $part['size'] < 0 || preg_match('/^[0-9a-f]{64}$/D', $part['sha256']) !== 1) {
                throw new GuardFailed('The site names a part "' . $part['file'] . '" that does not belong to the backup "' . $name->base() . '".');
            }
            $files[] = ['file' => $part['file'], 'size' => $part['size'], 'sha256' => $part['sha256'], 'fetched' => false];
        }
        $sidecar = $found['sidecar'];
        if ($sidecar['file'] !== $name->sidecarFile() || preg_match('/^[0-9a-f]{64}$/D', $sidecar['sha256']) !== 1) {
            throw new GuardFailed('The site names a sidecar file that does not belong to the backup "' . $name->base() . '".');
        }
        $total = array_sum(array_column($files, 'size')) + $sidecar['size'];
        $storage = new LocalStorage($profile->storage);
        $storage->prepare();
        $free = @disk_free_space($storage->directory());
        if ($free !== false && $total > $free && !$s->force) {
            throw new GuardFailed(sprintf('The storage "%s" has room for %s and the backup is %s: make room, or start the backup with --force.', $storage->directory(), $this->bytes((int) $free), $this->bytes($total)));
        }
        $s->backup = $name->base();
        $r['parts'] = $files;
        $r['sidecar'] = ['file' => $sidecar['file'], 'size' => $sidecar['size'], 'sha256' => $sidecar['sha256'], 'fetched' => false];
        $r['total'] = $total;
        $r['step'] = 'pull';
        $r['percent'] = max((int) $r['percent'], 50);

        return sprintf('the backup is %s in %d part(s): fetching it', $this->bytes($total), count($files));
    }

    /**
     * Fetches the next part (or the sidecar file): until it is whole, or until the time of this tick is up (the transfer then goes on from the byte
     * that is there at the next tick).
     *
     * @param array<string, mixed> $r
     */
    private function pull(SiteProfile $profile, AgentClient $client, array &$r, Budget $budget): string
    {
        $name = BackupName::parse((string) $r['backup']);
        if ($name === null) {
            throw new StepFailed('The state of the run has no backup.');
        }
        $storage = new LocalStorage($profile->storage);
        $work = $storage->workDirectory($name);
        if (!is_dir($work) && !@mkdir($work, 0700, true) && !is_dir($work)) {
            throw new StepFailed("Cannot make the work directory \"$work\".");
        }
        $items = [...$r['parts'], $r['sidecar']];
        foreach ($items as $index => $item) {
            if ($item['fetched']) {
                continue;
            }
            $complete = $client->fetch((string) $r['backup'], $item['file'], $work . '/' . $item['file'], (int) $item['size'], (string) $item['sha256'], static fn (): bool => $budget->exhausted());
            if ($complete) {
                if ($index < count($r['parts'])) {
                    $r['parts'][$index]['fetched'] = true;
                } else {
                    $r['sidecar']['fetched'] = true;
                }
            }
            $r['percent'] = 50 + (int) round(45 * $this->fetchedBytes($r, $work) / max(1, (int) $r['total']));

            return sprintf('fetching %s (%d of %d)%s', $item['file'], $index + 1, count($items), $complete ? '' : ': ' . $this->bytes((int) @filesize($work . '/' . $item['file'])) . ' of ' . $this->bytes((int) $item['size']));
        }
        $r['step'] = 'store';

        return 'everything is fetched and checked';
    }

    /**
     * Brings the fetched files into the storage: the parts first, the sidecar file last (the catalog lists a backup by its sidecar file).
     *
     * @param array<string, mixed> $r
     */
    private function store(SiteProfile $profile, array &$r): string
    {
        $name = BackupName::parse((string) $r['backup']);
        if ($name === null) {
            throw new StepFailed('The state of the run has no backup.');
        }
        $storage = new LocalStorage($profile->storage);
        $work = $storage->workDirectory($name);
        foreach ([...$r['parts'], $r['sidecar']] as $item) {
            $from = $work . '/' . $item['file'];
            $to = $storage->directory() . '/' . $item['file'];
            if (is_file($from) && !@rename($from, $to)) {
                throw new StepFailed("Cannot move \"{$item['file']}\" into the storage \"{$storage->directory()}\".");
            }
            if (!is_file($to)) {
                throw new StepFailed("The file \"{$item['file']}\" is neither in the work directory nor in the storage.");
            }
        }
        $storage->removeWorkDirectory($name);
        $r['step'] = 'done';
        $r['percent'] = 97;

        return 'the backup is in the storage';
    }

    /**
     * @param array<string, mixed> $r
     */
    private function done(BackupState $s, AgentClient $client, array &$r): string
    {
        $parts = [];
        foreach ($r['parts'] as $part) {
            $parts[$part['file']] = $part['sha256'];
        }
        try {
            $client->done((string) $r['backup'], $parts);
        } catch (AgentException $e) {
            if ($e->isTransient() && ++$r['doneTries'] <= 2) {
                throw $e;
            }
            // the backup is safe here; the site only keeps more of them than it need
            $s->warn('The site was not told that this machine holds the backup (' . $e->getMessage() . '): it keeps its older backups until the next one.');
        }
        $r['step'] = 'finish';
        $r['percent'] = 99;

        return 'the site is told that the backup is safe here';
    }

    /**
     * @param array<string, mixed> $r
     */
    private function finish(BackupState $s, SiteProfile $profile, array $r): string
    {
        $name = BackupName::parse((string) $r['backup']);
        if ($name === null) {
            throw new StepFailed('The state of the run has no backup.');
        }
        $storage = new LocalStorage($profile->storage);
        $sidecar = null;
        try {
            $sidecar = $storage->readSidecar($name);
        } catch (Throwable) {
            // an encrypted backup has the minimal sidecar file, which reads fine; a file that cannot be read is told by the catalog later
        }
        $counts = $sidecar instanceof Sidecar ? $sidecar->manifest->counts : null;
        $s->finished = ($this->clock)();
        $s->result = [
            'backup' => $name->base(),
            'parts' => array_map(static fn (array $p): string => (string) $p['file'], $r['parts']),
            'size' => $sidecar instanceof Sidecar ? $sidecar->size() : array_sum(array_column($r['parts'], 'size')),
            'status' => $sidecar instanceof Sidecar ? $sidecar->status->value : 'unverified',
            'warnings' => count($s->warnings) + ($sidecar instanceof Sidecar ? count($sidecar->manifest->warnings) : 0),
            'files' => $counts['files'] ?? 0,
            'directories' => $counts['directories'] ?? 0,
            'tables' => $counts['tables'] ?? 0,
            'rows' => $counts['rows'] ?? 0,
            'consistency' => $sidecar instanceof Sidecar ? $sidecar->manifest->consistency : '',
            'duration' => $s->finished - $s->started,
            'encrypted' => $sidecar instanceof Sidecar && $sidecar->encryption !== null,
            'remote' => true,
            'caughtUp' => $r['caughtUp'] === true,
        ];
        $s->phase = BackupState::DONE;

        return 'the backup is in the catalog: ' . $name->base();
    }

    // ------------------------------------------------------------------------------------------------- helpers

    /**
     * What to do about a failure of a call: the ones that may pass are tried again after a pause that doubles, the others end the run.
     *
     * @param array<string, mixed> $r
     *
     * @throws GuardFailed|StepFailed
     */
    private function fail(AgentException $e, array &$r): void
    {
        $again = $e->isTransient() || $e->kind === AgentException::MISMATCH;
        if (!$again) {
            throw new GuardFailed($this->advice($e), 0, $e);
        }
        $r['retries'] = (int) $r['retries'] + 1;
        if ($r['retries'] > self::MAX_RETRIES) {
            throw new StepFailed('The site could not be brought through after ' . self::MAX_RETRIES . ' tries in a row: ' . $e->getMessage(), 0, $e);
        }
        $r['waitUntil'] = ($this->clock)() + min(120, 10 * (2 ** ($r['retries'] - 1)));
        $r['lastError'] = $e->getMessage();
    }

    /** The sentence for a failure that will not pass, with what to do about it. */
    private function advice(AgentException $e): string
    {
        return match ($e->kind) {
            AgentException::REFUSED => $e->getMessage() . ' Look at the page "The agent" on the site, and pair again if the secret changed.',
            AgentException::CLOCK => $e->getMessage() . ' Correct the clock of one of the two machines (the time of the site was ' . (isset($e->details['hostTime']) ? gmdate('Y-m-d H:i:s', (int) $e->details['hostTime']) . ' UTC' : 'not told') . ').',
            default => $e->getMessage(),
        };
    }

    private function profile(BackupState $s): SiteProfile
    {
        try {
            return $this->profiles->load($s->profileId);
        } catch (ProfileException $e) {
            throw new GuardFailed($e->getMessage(), 0, $e);
        }
    }

    /**
     * Bytes that are in hand: the parts and the sidecar file that are whole, and what there is of the one that is being fetched.
     *
     * @param array<string, mixed> $r
     */
    private function fetchedBytes(array $r, string $work): int
    {
        $bytes = 0;
        foreach ([...$r['parts'], $r['sidecar']] as $item) {
            $bytes += $item['fetched'] ? (int) $item['size'] : (is_file($work . '/' . $item['file']) ? (int) filesize($work . '/' . $item['file']) : 0);
        }

        return $bytes;
    }

    private function bytes(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : ($bytes >= 1024 ? round($bytes / 1024, 1) . ' KB' : $bytes . ' bytes');
    }
}
