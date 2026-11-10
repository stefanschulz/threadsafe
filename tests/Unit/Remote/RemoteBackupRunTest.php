<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Remote;

use PHPUnit\Framework\TestCase;
use ThreadSafe\App\BackupService;
use ThreadSafe\App\CatalogService;
use ThreadSafe\Backup\BackupState;
use ThreadSafe\Core\Exception\GuardFailed;
use ThreadSafe\Core\Exception\StepFailed;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Engine\Run;
use ThreadSafe\Engine\RunStatus;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Profile\RemoteSettings;
use ThreadSafe\Profile\SiteProfile;
use ThreadSafe\Remote\AgentClient;
use ThreadSafe\Remote\AgentResponse;
use ThreadSafe\Remote\RemoteBackupRun;
use ThreadSafe\Tests\Support\AgentHost;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * The backup of a remote profile (docs/PHASE13.md 3.3), as the app runs it: through {@see BackupService}, against an agent in the same process. The site is made,
 * fetched with Range, checked, put into the storage, told about, and listed by the catalog like any other backup.
 */
final class RemoteBackupRunTest extends TestCase
{
    use TempDirectory;

    private AgentHost $host;

    private string $central;

    private string $storage;

    private ProfileStore $profiles;

    /** The budget of a tick: unlimited, or small, or none at all. */
    private Budget $budget;

    protected function setUp(): void
    {
        $base = $this->tempDirectory();
        $this->host = new AgentHost($base);
        $this->central = $base . '/central';
        $this->storage = $this->central . '/storage';
        mkdir($this->central . '/data/profiles', 0777, true);
        mkdir($this->storage, 0777, true);
        $this->profiles = new ProfileStore($this->central . '/data/profiles');
        $this->profiles->save($this->profile('shop'));
        $this->budget = Budget::unlimited();
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    private function profile(string $id): SiteProfile
    {
        return SiteProfile::fromArray(['id' => $id, 'storage' => $this->storage, 'remote' => ['url' => AgentHost::URL, 'secret' => $this->host->secret]]);
    }

    private function service(): BackupService
    {
        return new BackupService(
            $this->profiles,
            PluginRegistry::load(),
            new \ThreadSafe\Engine\RunStore($this->central . '/data/runs'),
            $this->central . '/data',
            fn (): Budget => $this->budget,
            fn (): int => $this->host->now,
            null,
            null,
            fn (RemoteSettings $remote): AgentClient => new AgentClient($remote, $this->host->transport, fn (): int => $this->host->now),
        );
    }

    private function catalog(): CatalogService
    {
        return new CatalogService($this->profiles, new \ThreadSafe\Engine\RunStore($this->central . '/data/runs'), null, fn (): int => $this->host->now);
    }

    /**
     * Ticks a run until it ends.
     *
     * @return list<string> the lines of progress, in order
     */
    private function runToTheEnd(BackupService $service, Run $run, int $limit = 400, int $advance = 0): array
    {
        $lines = [];
        for ($i = 0; $i < $limit; ++$i) {
            $run = $service->tick($run->id);
            $lines[] = (string) $run->progress;
            if ($run->status !== RunStatus::Running) {
                return $lines;
            }
            $this->host->now += $advance;
        }
        self::fail('The run did not end: ' . end($lines));
    }

    /** @return list<string> the files in the storage that belong to a backup (the protection files of the storage are not) */
    private function backupFiles(): array
    {
        return array_values(array_filter(array_map('basename', glob($this->storage . '/*') ?: []), static fn (string $file): bool => \ThreadSafe\Backup\BackupName::fromFile($file) !== null));
    }

    /** @return list<string> the actions that were asked of the agent, in order */
    private function asked(): array
    {
        return array_map(static function (array $entry): string {
            parse_str((string) parse_url($entry['uri'], PHP_URL_QUERY), $query);

            $api = $query['api'] ?? '';

            return is_string($api) ? $api : '';
        }, $this->host->transport->log);
    }

    // ------------------------------------------------------------------------------------------------- a backup

    public function testTheBackupOfARemoteProfileIsMadeFetchedAndListedLikeAnyOther(): void
    {
        $service = $this->service();
        $run = $service->startWeb('shop');

        $this->runToTheEnd($service, $run);

        $run = $service->find($run->id);
        self::assertSame(RunStatus::Finished, $run->status);
        $result = $service->result($run) ?? [];
        self::assertTrue($result['remote']);
        self::assertSame('ok', $result['status']);
        self::assertGreaterThan(0, $result['files']);
        self::assertMatchesRegularExpression('/^shop_\d{8}-\d{6}_[0-9a-f]{8}$/D', $result['backup']);
        self::assertSame(100, $service->progress($run)['percent']);

        $entries = $this->catalog()->backups('shop');
        self::assertCount(1, $entries);
        self::assertSame($result['backup'], $entries[0]->name->base());
        self::assertSame('complete', $entries[0]->condition);
        foreach ($result['parts'] as $part) {
            self::assertFileExists($this->storage . '/' . $part);
        }
        self::assertSame([], glob($this->storage . '/.work-*') ?: [], 'the work directory is gone');
        // the site was told, in the order of the plan: status, start, ticks, list, parts, done
        $asked = $this->asked();
        self::assertSame('status', $asked[0]);
        self::assertContains('start', $asked);
        self::assertContains('list', $asked);
        self::assertSame('done', $asked[array_key_last($asked)]);
    }

    public function testTheFetchedBackupIsTheOneOnTheSiteByteForByte(): void
    {
        $service = $this->service();
        $this->runToTheEnd($service, $service->startWeb('shop'));
        $result = $service->result($service->find($service->runs()[0]->id)) ?? [];
        $onSite = $this->host->client()->backups()[0];

        foreach ($onSite['parts'] as $part) {
            self::assertSame($part['sha256'], hash_file('sha256', $this->storage . '/' . $part['file']));
        }
        self::assertSame($result['backup'], $onSite['backup']);
        self::assertSame($onSite['sidecar']['sha256'] ?? null, hash_file('sha256', $this->storage . '/' . $result['backup'] . '.json'), 'the sidecar file is fetched, not made again');
    }

    public function testATickThatHasNoTimeLeftDoesOneStepAndTheRunStillEnds(): void
    {
        $this->budget = new Budget(0.0);
        $service = $this->service();
        $run = $service->startWeb('shop');
        $this->host->transport->chunk = 200;   // a part comes in small pieces: the tick stops in the middle of it

        $lines = $this->runToTheEnd($service, $run);

        self::assertGreaterThan(8, count($lines), 'many small ticks');
        $result = $service->result($service->find($run->id)) ?? [];
        foreach ($result['parts'] as $part) {
            self::assertFileExists($this->storage . '/' . $part);
        }
        self::assertSame('complete', $this->catalog()->backups('shop')[0]->condition);
    }

    public function testAScheduledRunInOneProcessGoesThroughToTheEnd(): void
    {
        $service = $this->service();
        $run = $service->startScheduled('shop', true);

        $finished = $service->complete($run->id);

        self::assertSame(RunStatus::Finished, $finished->status);
        self::assertSame('schedule', BackupState::fromArray($finished->state)->source);
        self::assertCount(1, $this->catalog()->backups('shop'));
    }

    // ------------------------------------------------------------------------------------------------- a broken transfer, a taken-up backup

    public function testATransferThatBreaksOffGoesOnFromTheBytesThatAreThereAfterAPause(): void
    {
        $this->budget = new Budget(1000.0);   // a tick that may wait: it does not sleep, the next one looks again
        $service = $this->service();
        $run = $service->startWeb('shop');
        $this->host->transport->intercept = function (string $action): ?AgentResponse {
            if ($action === 'part' && $this->host->transport->cutNextDownloadAfter === null && !$this->cut) {
                $this->cut = true;
                $this->host->transport->cutNextDownloadAfter = 300;
            }

            return null;
        };

        $lines = $this->runToTheEnd($service, $run, advance: 15);

        self::assertNotEmpty(array_filter($lines, static fn (string $line): bool => str_contains($line, 'trying again') || str_contains($line, 'waiting')), 'the pause was shown');
        $ranges = array_filter($this->host->transport->log, static fn (array $e): bool => isset($e['headers']['range']));
        self::assertNotSame([], $ranges, 'the part went on from where it stopped');
        self::assertSame('complete', $this->catalog()->backups('shop')[0]->condition);
    }

    /** @var bool whether the cut was made (for the test above) */
    private bool $cut = false;

    public function testABackupThatAnEarlierTransferLeftOnTheSiteIsTakenUpInsteadOfMakingAnother(): void
    {
        $left = $this->host->backup();   // made on the site, never fetched
        $this->host->transport->log = [];
        $service = $this->service();

        $this->runToTheEnd($service, $service->startWeb('shop'));

        self::assertNotContains('start', $this->asked(), 'no new backup was made on the site');
        $result = $service->result($service->find($service->runs()[0]->id)) ?? [];
        self::assertSame($left, $result['backup']);
        self::assertTrue($result['caughtUp']);
        self::assertSame(1, $result['warnings']);
        self::assertCount(1, $this->catalog()->backups('shop'));
    }

    public function testAnOldBackupOnTheSiteIsNotTakenUpAndANewOneIsMade(): void
    {
        $this->host->backup();
        $this->host->now += 3 * 86400;   // older than a day: the schedule wants a backup of today
        $this->host->transport->log = [];
        $service = $this->service();

        $this->runToTheEnd($service, $service->startWeb('shop'));

        self::assertContains('start', $this->asked());
        self::assertFalse(($service->result($service->find($service->runs()[0]->id)) ?? [])['caughtUp']);
    }

    // ------------------------------------------------------------------------------------------------- what will not pass

    public function testAnAgentThatSaysNothingEndsTheRunWithWhatToDo(): void
    {
        $this->host->config->enable(false);
        $service = $this->service();
        $run = $service->startWeb('shop');

        try {
            $service->tick($run->id);
            self::fail('the agent does not answer');
        } catch (GuardFailed $e) {
            self::assertStringContainsString('does not answer', $e->getMessage());
            self::assertStringContainsString('page "The agent"', $e->getMessage());
        }
    }

    public function testAClockThatIsOffEndsTheRunWithTheTimeOfTheSite(): void
    {
        $service = new BackupService($this->profiles, PluginRegistry::load(), new \ThreadSafe\Engine\RunStore($this->central . '/data/runs'), $this->central . '/data', fn (): Budget => $this->budget, fn (): int => $this->host->now - 900, null, null,
            fn (RemoteSettings $remote): AgentClient => new AgentClient($remote, $this->host->transport, fn (): int => $this->host->now - 900));
        $run = $service->startWeb('shop');

        try {
            $service->tick($run->id);
            self::fail('the clocks differ');
        } catch (GuardFailed $e) {
            self::assertStringContainsString('clocks', $e->getMessage());
            self::assertStringContainsString(gmdate('Y-m-d H:i:s', $this->host->now), $e->getMessage());
        }
    }

    public function testAProfileIdThatIsNotTheOneOfThePairingIsToldBeforeAnythingIsMade(): void
    {
        $this->profiles->save($this->profile('other'));
        $service = $this->service();
        $run = $service->startWeb('other');

        try {
            $service->tick($run->id);
            self::fail('the ids differ');
        } catch (GuardFailed $e) {
            self::assertStringContainsString('under the profile id "shop"', $e->getMessage());
            self::assertStringContainsString('"other"', $e->getMessage());
        }
        self::assertNotContains('start', $this->asked());
    }

    public function testAnApiOfAnotherVersionIsToldWithWhichSideToUpdate(): void
    {
        $this->host->transport->intercept = static fn (string $action): ?AgentResponse => $action === 'status'
            ? new AgentResponse(200, body: '{"api":2,"profile":{"id":"shop","ready":true}}')
            : null;
        $service = $this->service();
        $run = $service->startWeb('shop');

        try {
            $service->tick($run->id);
            self::fail('another version');
        } catch (GuardFailed $e) {
            self::assertStringContainsString('update the older side', $e->getMessage());
        }
    }

    public function testABackupThatFailedOnTheSiteEndsTheRunWithItsReason(): void
    {
        $this->host->transport->intercept = static fn (string $action): ?AgentResponse => $action === 'tick'
            ? new AgentResponse(200, body: '{"status":"failed","error":"The disk or the quota of this host is full.","progress":"","percent":10}')
            : null;
        $service = $this->service();

        try {
            $this->runToTheEnd($service, $service->startWeb('shop'));
            self::fail('the backup on the site failed');
        } catch (StepFailed $e) {
            self::assertStringContainsString('The backup on the site failed', $e->getMessage());
            self::assertStringContainsString('quota of this host is full', $e->getMessage());
        }
    }

    public function testASiteThatCannotBeReachedIsTriedAgainAndThenGivenUp(): void
    {
        $this->budget = new Budget(1000.0);
        $this->host->transport->intercept = static fn (string $action): AgentResponse => new AgentResponse(0, error: 'connection refused');
        $service = $this->service();
        $run = $service->startWeb('shop');

        try {
            $this->runToTheEnd($service, $run, advance: 200);
            self::fail('the site never answers');
        } catch (StepFailed $e) {
            self::assertStringContainsString('after 6 tries in a row', $e->getMessage());
            self::assertStringContainsString('connection refused', $e->getMessage());
        }
    }

    public function testNamesThatTheSiteMakesUpAreNotTrustedAsFileNames(): void
    {
        $name = $this->host->backup();
        $real = $this->host->client()->backups()[0];
        $evil = ['backups' => [['backup' => $name, 'time' => '', 'status' => 'ok', 'complete' => true, 'encrypted' => false, 'size' => 1,
            'parts' => [['file' => '../../evil.zip', 'size' => 10, 'sha256' => str_repeat('a', 64)]], 'sidecar' => $real['sidecar']]]];
        $this->host->transport->intercept = function (string $action) use ($evil): ?AgentResponse {
            static $listed = 0;
            // the first list (taking up) and the second (the listing of the run) both carry the bad name
            return $action === 'list' && ++$listed >= 1 ? new AgentResponse(200, body: (string) json_encode($evil)) : null;
        };
        $service = $this->service();

        try {
            $this->runToTheEnd($service, $service->startWeb('shop'));
            self::fail('a bad name must be refused');
        } catch (GuardFailed $e) {
            self::assertStringContainsString('does not belong to the backup', $e->getMessage());
        }
        self::assertFileDoesNotExist($this->central . '/evil.zip');
        self::assertFileDoesNotExist(dirname($this->storage) . '/evil.zip');
    }

    public function testASidecarFileAsLargeAsAnArchiveIsNotFetched(): void
    {
        $name = $this->host->backup();
        $real = $this->host->client()->backups()[0];
        self::assertNotNull($real['sidecar']);
        $large = ['backups' => [[...$real, 'sidecar' => [...$real['sidecar'], 'size' => RemoteBackupRun::MAX_SIDECAR + 1]]]];
        $this->host->transport->intercept = static fn (string $action): ?AgentResponse => $action === 'list' ? new AgentResponse(200, body: (string) json_encode($large)) : null;
        $service = $this->service();

        try {
            $this->runToTheEnd($service, $service->startWeb('shop'));
            self::fail('a sidecar file of that size must be refused');
        } catch (GuardFailed $e) {
            self::assertStringContainsString('sidecar file of', $e->getMessage());
        }
        self::assertSame([], $this->backupFiles(), 'nothing of ' . $name . ' was fetched');
    }

    // ------------------------------------------------------------------------------------------------- giving up

    public function testGivingUpAsksTheSiteToGiveUpItsRunAndRemovesWhatWasFetched(): void
    {
        $this->budget = new Budget(0.0);
        $service = $this->service();
        $run = $service->startWeb('shop');
        $service->tick($run->id);   // status, then start: the site makes the backup in the ticks that follow
        $service->tick($run->id);
        self::assertContains('start', $this->asked());

        $service->abandon($run->id);

        self::assertContains('abort', $this->asked(), 'the site is asked to give its run up');
        self::assertSame([], $service->runs());
        self::assertSame([], $this->backupFiles());
    }

    public function testGivingUpInTheMiddleOfAPartRemovesThePartAndTheWorkDirectory(): void
    {
        $this->budget = new Budget(0.0);
        $service = $this->service();
        $run = $service->startWeb('shop');
        $this->host->transport->chunk = 100;
        $stopped = false;
        for ($i = 0; $i < 100 && !$stopped; ++$i) {
            $service->tick($run->id);
            $stopped = glob($this->storage . '/.work-*/*') !== [] && glob($this->storage . '/.work-*/*') !== false;
        }
        self::assertTrue($stopped, 'a part is half fetched');

        $service->abandon($run->id);

        self::assertSame([], glob($this->storage . '/.work-*') ?: []);
        self::assertSame([], $this->backupFiles(), 'only the protection files of the storage are left');
    }

    public function testARemoteRunHasAStateThatEveryReaderOfARunUnderstands(): void
    {
        $service = $this->service();
        $run = $service->startWeb('shop');

        $state = BackupState::fromArray($run->state);

        self::assertSame(BackupState::REMOTE, $state->phase);
        self::assertSame('start', $state->remote['step'] ?? null);
        self::assertSame('active', $service->state($run), 'a web run is active while its last tick is recent');
        self::assertSame(0, $service->progress($run)['percent']);
    }
}
