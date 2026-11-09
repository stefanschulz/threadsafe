<?php

declare(strict_types=1);

namespace ThreadSafe\Web;

use Closure;
use Throwable;
use ThreadSafe\App\BackupService;
use ThreadSafe\App\CatalogService;
use ThreadSafe\Backup\BackupName;
use ThreadSafe\Core\Exception\GuardFailed;
use ThreadSafe\Core\Version;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Engine\EngineException;
use ThreadSafe\Engine\Run;
use ThreadSafe\Engine\RunStatus;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Profile\ProfileException;
use ThreadSafe\Storage\BackupEntry;
use ThreadSafe\Storage\StorageException;

/**
 * The agent of the backup tool (docs/PHASE13.md 3.1): a door for a program, not for a person. A central ThreadSafe starts a backup on this site, runs
 * its steps, lists the parts and fetches them with `Range`, tells the site that it holds a backup whole, and may give a run up. One address,
 * `threadsafe-backup.php?api=<action>`, answering JSON.
 *
 * | action   | method | what                                                                                                         |
 * |----------|--------|--------------------------------------------------------------------------------------------------------------|
 * | `status` | GET    | the version of the API and of the tool, PHP and its limits, the clock, the free space (the volume's), the last run |
 * | `start`  | POST   | starts a backup of the profile of the pairing; a run that is going on is taken up, not doubled                  |
 * | `tick`   | POST   | one step (`run=<id>`): running, busy, failed or finished (with the name of the backup)                        |
 * | `list`   | GET    | the backups on the site with their parts and the SHA-256 of each, and the sidecar file                         |
 * | `part`   | GET    | one part or the sidecar file (`backup=`, `file=`), with `Range` / `If-Range` (`Web\Download`)                   |
 * | `done`   | POST   | the central side names the SHA-256 of every part it holds; the site then keeps its `keepOnHost` newest backups  |
 * | `abort`  | POST   | gives a run up and cleans up (`run=<id>`)                                                                      |
 *
 * **A stranger gets nothing.** The agent answers only if it is switched on and paired, the request comes over https (unless the person on the site
 * allowed plain http) from an address on the list (if there is one), is not held back by the {@see Throttle} and carries a good signature
 * ({@see AgentSignature}); in every other case the answer is the same plain "Not found" that a file that is not there gives. A request that is signed
 * and only late or repeated is told so (the sender has proved that it holds the secret). **The API never configures** (decision 2): the recipients, the
 * directory, the exclusions and the database access are set on the site by a person.
 */
final class Agent
{
    /** The answer to everything that is not a signed request to an agent that is on. */
    private const string NOT_FOUND = "Not found.\n";

    private readonly Closure $clock;

    private ?BackupService $service = null;

    private ?CatalogService $catalogService = null;

    /**
     * @param Closure(): int|null    $clock   for tests: the time in seconds
     * @param Closure(): Budget|null $budgets the budget of a tick; default: a share of the request time limit
     */
    public function __construct(
        private readonly BackupWorkspace $workspace,
        private readonly ?PluginRegistry $plugins = null,
        ?Closure $clock = null,
        private readonly ?Closure $budgets = null,
        private readonly string $version = Version::CURRENT,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function handle(Request $request): Response
    {
        try {
            return $this->guarded($request);
        } catch (Throwable $e) {
            // what went wrong stays in the log of the host; the sender is told only that the step did not work
            @error_log('ThreadSafe agent: ' . $e->getMessage());

            return $this->json(['error' => 'failed', 'message' => 'The agent could not do what was asked; the error log of the host says why.'], 500);
        }
    }

    private function guarded(Request $request): Response
    {
        $data = $this->workspace->existingData();
        if ($data === null) {
            return $this->stranger();   // a tool that was never set up: nothing is made for a request that may be a stranger's
        }
        $config = AgentConfig::in($data);
        if (!$config->isOn() || (!$request->https && !$config->plainAllowed()) || !$config->allows($request->remoteAddress)) {
            return $this->stranger();
        }

        $throttle = new Throttle($this->workspace->directory() . '/agent-attempts.json', $this->workspace->directory() . '/agent.log', $this->clock);
        if ($throttle->begin() > 0) {
            return $this->stranger();
        }
        $signature = new AgentSignature(new AgentReplayStore($data . '/agent-nonces'), $this->clock);
        $verdict = $signature->verify($config->secret(), $request);
        if ($verdict === 'headers' || $verdict === 'signature') {
            $throttle->failed($request->remoteAddress, 'agent: ' . ($verdict === 'headers' ? 'no signature' : 'wrong signature'));

            return $this->stranger();
        }
        $throttle->reset();
        if ($verdict === 'time') {
            return $this->json(['error' => 'clock', 'message' => 'The clocks of the two sides differ by more than ' . AgentSignature::WINDOW . ' seconds.', 'hostTime' => ($this->clock)(), 'window' => AgentSignature::WINDOW], 403);
        }
        if ($verdict === 'replay') {
            return $this->json(['error' => 'replay', 'message' => 'This request was seen before.'], 403);
        }

        $action = $request->query('api') ?? '';
        $methods = ['status' => 'GET', 'list' => 'GET', 'part' => 'GET', 'start' => 'POST', 'tick' => 'POST', 'done' => 'POST', 'abort' => 'POST'];
        if (!isset($methods[$action])) {
            return $this->json(['error' => 'unknown', 'message' => 'There is no such action.'], 404);
        }
        if ($request->method !== $methods[$action]) {
            return $this->json(['error' => 'method', 'message' => "The action \"$action\" is a {$methods[$action]}."], 405);
        }

        return match ($action) {
            'status' => $this->status($request, $config),
            'start' => $this->start($config),
            'tick' => $this->tick($request),
            'list' => $this->list($config),
            'part' => $this->part($request, $config),
            'done' => $this->done($request, $config),
            default => $this->abort($request),
        };
    }

    // ------------------------------------------------------------------------------------------------- the actions

    private function status(Request $request, AgentConfig $config): Response
    {
        $profileId = $config->profile();
        $ready = $this->workspace->profiles()->exists($profileId);
        $free = null;
        $run = null;
        $backups = null;
        if ($ready) {
            try {
                $storage = $this->catalog()->storageDirectory($profileId);
                $free = is_dir($storage) ? @disk_free_space($storage) : false;
                $backups = count($this->catalog()->backups($profileId));
            } catch (ProfileException | StorageException) {
                // a profile that cannot be read is reported as not ready below
                $ready = false;
            }
            $latest = $this->latestRun($profileId);
            $run = $latest === null ? null : $this->runSummary($latest);
        }

        return $this->json([
            'api' => AgentConfig::API_VERSION,
            'tool' => $this->version,
            'php' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'limits' => ['max_execution_time' => (int) ini_get('max_execution_time'), 'memory_limit' => (string) ini_get('memory_limit'), 'post_max_size' => (string) ini_get('post_max_size')],
            'time' => ($this->clock)(),
            'https' => $request->https,
            'profile' => ['id' => $profileId, 'ready' => $ready],
            // the figure is that of the volume, not of the quota of the account: the central side must not rely on it
            'space' => ['free' => $free === false ? null : $free, 'volumeOnly' => true],
            'keepOnHost' => $config->keepOnHost(),
            'backups' => $backups,
            'run' => $run,
        ]);
    }

    private function start(AgentConfig $config): Response
    {
        $profileId = $config->profile();
        if (!$this->workspace->profiles()->exists($profileId)) {
            return $this->json(['error' => 'no-profile', 'message' => 'The site has no backup profile for this pairing: finish the setup on the site (the page "Agent" of the tool, or the plugin).'], 409);
        }
        $service = $this->backups();
        $active = $service->activeRun();
        if ($active !== null) {
            if (($active->state['profileId'] ?? null) === $profileId || ($active->state['profile'] ?? null) === $profileId) {
                return $this->json(['run' => $active->id, 'resumed' => true]);   // a run that is going on is taken up, not doubled
            }

            return $this->json(['error' => 'busy', 'message' => 'Another backup is running on the site.'], 409);
        }
        try {
            $run = $service->startWeb($profileId);
        } catch (GuardFailed $e) {
            return $this->json(['error' => 'refused', 'message' => $e->getMessage()], 409);
        }

        return $this->json(['run' => $run->id, 'resumed' => false]);
    }

    private function tick(Request $request): Response
    {
        $id = $request->query('run') ?? '';
        $service = $this->backups();
        try {
            $run = $service->find($id);
        } catch (EngineException) {
            return $this->json(['error' => 'no-run', 'message' => 'There is no such run.'], 404);
        }
        $answer = ['status' => 'running'];
        try {
            $run = $service->tick($run->id);
        } catch (EngineException $e) {
            $answer = $e->isBusy() ? ['status' => 'busy'] : ['status' => 'failed', 'error' => $e->getMessage()];
            $run = $this->findOr($service, $id, $run);
        } catch (Throwable $e) {
            $answer = ['status' => 'failed', 'error' => 'The step failed: ' . $e->getMessage()];
            $run = $this->findOr($service, $id, $run);
        }
        if ($run->status === RunStatus::Finished) {
            $answer = ['status' => 'finished', 'backup' => (string) ($service->result($run)['backup'] ?? '')];
        }

        return $this->json($answer + ['progress' => (string) $run->progress, 'percent' => (int) ($service->progress($run)['percent'] ?? 0)]);
    }

    private function list(AgentConfig $config): Response
    {
        $profileId = $config->profile();
        try {
            $entries = $this->catalog()->backups($profileId);
            $directory = $this->catalog()->storageDirectory($profileId);
        } catch (ProfileException | StorageException $e) {
            return $this->json(['error' => 'no-profile', 'message' => $e->getMessage()], 409);
        }
        $backups = [];
        foreach ($entries as $entry) {
            $backups[] = $this->describe($entry, $directory);
        }

        return $this->json(['backups' => $backups]);
    }

    private function part(Request $request, AgentConfig $config): Response
    {
        $profileId = $config->profile();
        $missing = new Response(404, ['Content-Type: application/json', 'Cache-Control: no-store'], (string) json_encode(['error' => 'no-part', 'message' => 'There is no such part.']) . "\n");
        $name = BackupName::parse((string) $request->query('backup'));
        $file = (string) $request->query('file');
        if ($name === null || $name->profile !== $profileId) {
            return $missing;
        }
        try {
            if ($file === $name->sidecarFile()) {
                $path = $this->catalog()->storageDirectory($profileId) . '/' . $file;
                $sha = is_file($path) ? hash_file('sha256', $path) : false;

                return $sha === false ? $missing : Download::response($request, $path, $file, $sha, contentType: 'application/json');
            }
            $part = $this->catalog()->part($profileId, $name->base(), $file);
        } catch (ProfileException | StorageException) {
            return $missing;
        }

        return Download::response($request, $part['path'], $file, $part['sha256'], contentType: str_ends_with($file, '.age') ? 'application/octet-stream' : 'application/zip');
    }

    private function done(Request $request, AgentConfig $config): Response
    {
        $profileId = $config->profile();
        $body = json_decode($request->body, true);
        if (!is_array($body) || !is_string($body['backup'] ?? null) || !is_array($body['parts'] ?? null) || $body['parts'] === []) {
            return $this->json(['error' => 'bad-request', 'message' => 'The body is {"backup": "<name>", "parts": {"<file>": "<sha256>", ...}}.'], 400);
        }
        $name = BackupName::parse($body['backup']);
        if ($name === null || $name->profile !== $profileId) {
            return $this->json(['error' => 'no-backup', 'message' => 'There is no such backup.'], 404);
        }
        try {
            $entry = $this->catalog()->find($profileId, $name->base());
            $directory = $this->catalog()->storageDirectory($profileId);
        } catch (ProfileException | StorageException) {
            return $this->json(['error' => 'no-backup', 'message' => 'There is no such backup.'], 404);
        }
        // the central side names every part it holds, and each must be the one that is here: only then is a backup held "whole" elsewhere
        $held = $body['parts'];
        foreach ($entry->sidecar->parts ?? [] as $part) {
            if (!isset($held[$part->file]) || !is_string($held[$part->file]) || !hash_equals($part->sha256, strtolower($held[$part->file]))) {
                return $this->json(['error' => 'mismatch', 'message' => 'The part "' . $part->file . '" is missing from the list or its SHA-256 differs.', 'file' => $part->file], 409);
            }
        }
        if (($entry->sidecar->parts ?? []) === []) {
            return $this->json(['error' => 'no-backup', 'message' => 'The backup has no parts that could be held.'], 409);
        }

        return $this->json($this->keepNewest($profileId, $name->base(), $config->keepOnHost(), $directory));
    }

    private function abort(Request $request): Response
    {
        $service = $this->backups();
        try {
            $service->abandon($request->query('run') ?? '');
        } catch (GuardFailed) {
            return $this->json(['error' => 'busy', 'message' => 'A step is working on the run right now; ask again in a moment.'], 409);
        } catch (EngineException) {
            return $this->json(['error' => 'no-run', 'message' => 'There is no such run.'], 404);
        }

        return $this->json(['aborted' => true]);
    }

    // ------------------------------------------------------------------------------------------------- helpers

    /**
     * After a backup is held elsewhere the site keeps its `$keep` newest complete backups. Only backups that are not newer than the one just
     * confirmed are removed: a newer one has not been fetched yet. A backup that a run is working on is left alone.
     *
     * @return array{kept: list<string>, deleted: list<string>}
     */
    private function keepNewest(string $profileId, string $confirmed, int $keep, string $directory): array
    {
        $complete = array_values(array_filter($this->catalog()->backups($profileId), static fn (BackupEntry $e): bool => $e->condition === BackupEntry::COMPLETE));
        $position = 0;
        foreach ($complete as $index => $entry) {
            if ($entry->name->base() === $confirmed) {
                $position = $index;
            }
        }
        $kept = [];
        $deleted = [];
        foreach ($complete as $index => $entry) {
            $base = $entry->name->base();
            if ($index >= $keep && $index >= $position) {
                try {
                    $this->catalog()->delete($profileId, $base);
                    $deleted[] = $base;

                    continue;
                } catch (GuardFailed | StorageException | ProfileException) {
                    // in use: it stays
                }
            }
            $kept[] = $base;
        }

        return ['kept' => $kept, 'deleted' => $deleted];
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(BackupEntry $entry, string $directory): array
    {
        $files = [];
        foreach ($entry->sidecar->parts ?? [] as $part) {
            $files[] = ['file' => $part->file, 'size' => $part->size, 'sha256' => $part->sha256];
        }
        $sidecarFile = $entry->name->sidecarFile();
        $sidecarPath = $directory . '/' . $sidecarFile;
        $sha = is_file($sidecarPath) ? hash_file('sha256', $sidecarPath) : false;

        return [
            'backup' => $entry->name->base(),
            'time' => $entry->name->time->format('Y-m-d\TH:i:s\Z'),
            'status' => $entry->label(),
            'complete' => $entry->condition === BackupEntry::COMPLETE,
            'encrypted' => $entry->sidecar?->encryption !== null,
            'size' => $entry->sidecar?->size(),
            'parts' => $files,
            'sidecar' => $sha === false ? null : ['file' => $sidecarFile, 'size' => (int) filesize($sidecarPath), 'sha256' => $sha],
        ];
    }

    private function latestRun(string $profileId): ?Run
    {
        $latest = null;
        foreach ($this->backups()->runs() as $run) {
            if (($run->state['profileId'] ?? $run->state['profile'] ?? null) === $profileId && ($latest === null || $run->updated >= $latest->updated)) {
                $latest = $run;
            }
        }

        return $latest;
    }

    /**
     * @return array<string, mixed>
     */
    private function runSummary(Run $run): array
    {
        $service = $this->backups();

        return ['id' => $run->id, 'status' => $run->status->value, 'progress' => (string) $run->progress, 'percent' => (int) ($service->progress($run)['percent'] ?? 0), 'updated' => $run->updated];
    }

    private function findOr(BackupService $service, string $id, Run $fallback): Run
    {
        try {
            return $service->find($id);
        } catch (EngineException) {
            return $fallback;
        }
    }

    private function backups(): BackupService
    {
        return $this->service ??= new BackupService($this->workspace->profiles(), $this->plugins ?? PluginRegistry::load(), $this->workspace->runs(), $this->workspace->data(), $this->budgets, $this->clock);
    }

    private function catalog(): CatalogService
    {
        return $this->catalogService ??= new CatalogService($this->workspace->profiles(), $this->workspace->runs(), $this->budgets, $this->clock);
    }

    private function stranger(): Response
    {
        return new Response(404, ['Content-Type: text/plain; charset=utf-8', 'Cache-Control: no-store'], self::NOT_FOUND);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data, int $status = 200): Response
    {
        return new Response($status, ['Content-Type: application/json', 'Cache-Control: no-store', 'X-Content-Type-Options: nosniff'], (string) json_encode($data, JSON_UNESCAPED_SLASHES) . "\n");
    }
}
