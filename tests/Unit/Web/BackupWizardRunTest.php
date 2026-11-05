<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Web;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Crypto\AgeKeys;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Engine\Lock;
use ThreadSafe\Engine\Run;
use ThreadSafe\Engine\RunStatus;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Tests\Support\ToolClient;
use ThreadSafe\Web\BackupWizard;
use ThreadSafe\Web\BackupWorkspace;
use ThreadSafe\Web\Response;

/**
 * The backup tool's wizard from the summary to the end: the start (and what stops it), the run in steps, coming back to a run, the downloads with
 * `Range`, deleting the archive, taking the tool off the server.
 */
final class BackupWizardRunTest extends TestCase
{
    use TempDirectory;

    private const string KEY = 'correct horse battery';

    private string $web;

    private bool $tiny = false;

    protected function setUp(): void
    {
        $this->web = $this->tempDirectory() . '/site';
        mkdir($this->web . '/assets', 0777, true);
        file_put_contents($this->web . '/index.php', '<?php echo 1;');
        file_put_contents($this->web . '/assets/big.bin', random_bytes(1_500_000));
        file_put_contents($this->web . '/assets/text.txt', str_repeat("some text\n", 5000));
        file_put_contents($this->web . '/threadsafe-backup.php', '<?php // the tool');
        file_put_contents($this->web . '/threadsafe-backup-key.php', "<?php return '" . self::KEY . "';");
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    private function client(): ToolClient
    {
        $budget = $this->tiny ? static fn (): Budget => new Budget(0.0) : static fn (): Budget => Budget::unlimited();
        $wizard = new BackupWizard($this->web, PluginRegistry::load(), 'threadsafe-backup.php', static fn (): int => 1_800_000_000, $budget);

        return new ToolClient($wizard->handle(...), BackupWizard::COOKIE, '/tools/threadsafe-backup.php');
    }

    private function login(ToolClient $client): Response
    {
        $client->get();

        return $client->post(['action' => 'login', 'key' => self::KEY], [], true, false);
    }

    /**
     * Logs in and goes through site and options (not encrypted, unless told otherwise) to the summary.
     *
     * @param array<string, string> $options
     */
    private function atTheSummary(array $options = []): ToolClient
    {
        $client = $this->client();
        self::assertStringContainsString('Directory of the site', $this->login($client)->body);
        $client->post(['action' => 'save-site', 'root' => $this->web, 'type' => 'auto', 'no_db' => '1']);
        $summary = $client->post([...['action' => 'save-options', 'mode' => 'none', 'plain' => '1', 'partMb' => '1', 'exclude' => '', 'note' => '', 'storage' => ''], ...$options]);
        self::assertStringContainsString('Back up now', $summary->body);

        return $client;
    }

    /** Starts the backup (the probe said "closed") and ticks until the page says it is done. */
    private function backedUp(ToolClient $client): string
    {
        $client->post(['action' => 'probe', 'result' => 'closed']);
        $run = $client->post(['action' => 'start']);
        self::assertStringContainsString('Backing up', $run->body);

        for ($i = 0; $i < 400; ++$i) {
            $answer = json_decode($client->post(['action' => 'tick', 'format' => 'json'])->body, true);
            self::assertIsArray($answer);
            if (($answer['status'] ?? '') === 'finished') {
                return $client->get(['step' => 'done'])->body;
            }
            self::assertSame('running', $answer['status'], (string) json_encode($answer));
        }
        self::fail('The backup did not finish.');
    }

    private function storage(): string
    {
        return (string) BackupWorkspace::in($this->web)->existingStorage();
    }

    /** @return list<string> the archive parts in the storage */
    private function parts(): array
    {
        return array_map('basename', glob($this->storage() . '/*.{zip,age}', GLOB_BRACE) ?: []);
    }

    private function body(Response $response): string
    {
        if ($response->stream === null) {
            return $response->body;
        }
        ob_start();
        ($response->stream)();

        return (string) ob_get_clean();
    }

    // ------------------------------------------------------------------------------------------------- starting

    public function testTheBackupWillNotStartWhileTheArchiveCanBeFetched(): void
    {
        $client = $this->atTheSummary();
        $client->post(['action' => 'probe', 'result' => 'reachable']);

        $page = $client->post(['action' => 'start', 'probe_ok' => '1']);

        self::assertStringContainsString('outside the document root (on the options page)', $page->body);
        self::assertStringNotContainsString('Backing up', $page->body);
        self::assertSame([], $this->parts());
    }

    public function testWithoutAnAnswerOfTheProbeThePersonMustSayTheyMadeSure(): void
    {
        $client = $this->atTheSummary();

        self::assertStringContainsString('made sure', $client->post(['action' => 'start'])->body);
        self::assertStringContainsString('Backing up', $client->post(['action' => 'start', 'probe_ok' => '1'])->body);
    }

    public function testTheProbeFileIsRemovedWhenTheBackupStarts(): void
    {
        $client = $this->atTheSummary();
        self::assertCount(1, glob($this->storage() . '/probe-*.txt') ?: []);

        $client->post(['action' => 'probe', 'result' => 'closed']);
        $client->post(['action' => 'start']);

        self::assertSame([], glob($this->storage() . '/probe-*.txt') ?: []);
    }

    public function testTheStartIsRefusedBeforeTheOptionsAreDone(): void
    {
        $client = $this->client();
        $this->login($client);

        self::assertStringContainsString('Directory of the site', $client->post(['action' => 'start', 'probe_ok' => '1'])->body);
    }

    // ------------------------------------------------------------------------------------------------- the run and the result

    public function testTheBackupRunsInStepsAndTheDonePageListsTheParts(): void
    {
        $this->tiny = true;
        $client = $this->atTheSummary();

        $done = $this->backedUp($client);

        self::assertStringContainsString('The backup is made', $done);
        self::assertStringContainsString('<strong>not encrypted</strong>', $done);
        $parts = $this->parts();
        self::assertNotSame([], $parts);
        foreach ($parts as $part) {
            self::assertStringContainsString($part, $done);
            self::assertStringContainsString((string) hash_file('sha256', $this->storage() . '/' . $part), $done, 'the SHA-256 of every part');
        }
        self::assertStringContainsString('scp USER@HOST:', $done);
        self::assertStringNotContainsString('.work-', implode(' ', scandir($this->storage()) ?: []), 'the work files are gone');
    }

    public function testABackupOfADirectoryThatHoldsOnlyTheToolSaysSo(): void
    {
        $tools = $this->web . '/tools';
        mkdir($tools);
        rename($this->web . '/threadsafe-backup.php', $tools . '/threadsafe-backup.php');
        rename($this->web . '/threadsafe-backup-key.php', $tools . '/threadsafe-backup-key.php');
        $wizard = new BackupWizard($tools, PluginRegistry::load(), 'threadsafe-backup.php', static fn (): int => 1_800_000_000, static fn (): Budget => Budget::unlimited());
        $client = new ToolClient($wizard->handle(...), BackupWizard::COOKIE, '/tools/threadsafe-backup.php');
        $this->login($client);
        $client->post(['action' => 'save-site', 'root' => $tools, 'type' => 'auto', 'no_db' => '1']);
        $client->post(['action' => 'save-options', 'mode' => 'none', 'plain' => '1', 'partMb' => '1', 'exclude' => '', 'note' => '', 'storage' => '']);

        $done = $this->backedUp($client);

        self::assertStringContainsString('No file was saved', $done);
        self::assertStringNotContainsString('The backup is made', $done);
    }

    public function testTheRunPageShowsTheProgressAndTheStatusAnswerIsData(): void
    {
        $client = $this->atTheSummary();
        $client->post(['action' => 'probe', 'result' => 'closed']);
        $client->post(['action' => 'start']);

        $page = $client->get(['step' => 'run'])->body;
        self::assertStringContainsString('id="bar"', $page);
        self::assertStringContainsString('Give up this backup', $page);

        $status = json_decode($client->get(['step' => 'status'])->body, true);
        self::assertIsArray($status);
        self::assertArrayHasKey('working', $status);
    }

    public function testASecondStartWhileOneIsGoingOnIsRefused(): void
    {
        $client = $this->atTheSummary();
        $client->post(['action' => 'probe', 'result' => 'closed']);
        $client->post(['action' => 'start']);

        $other = $this->client();
        $this->login($other);   // it is taken straight to the run that is going on
        self::assertStringContainsString('Backing up', $other->get(['step' => 'run'])->body);
        self::assertStringContainsString('Backing up', $other->get(['step' => 'run'])->body);
    }

    public function testAnEncryptedBackupHasPartsOfItsOwnKind(): void
    {
        $pair = AgeKeys::generate();
        $client = $this->atTheSummary(['mode' => 'recipient', 'recipient' => $pair['public']]);

        $done = $this->backedUp($client);

        self::assertStringContainsString('encrypted', $done);
        self::assertStringNotContainsString('not encrypted', $done);
        self::assertStringContainsString('.zip.age', $done);
        foreach ($this->parts() as $part) {
            self::assertStringEndsWith('.zip.age', $part, 'only sealed parts are in the storage');
        }
    }

    public function testAFailedBackupIsStartedAgainFromTheBeginning(): void
    {
        $client = $this->atTheSummary();
        $client->post(['action' => 'probe', 'result' => 'closed']);
        $client->post(['action' => 'start']);
        $workspace = BackupWorkspace::in($this->web);
        $run = $workspace->runs()->all('backup')[0];
        $workspace->runs()->save(new Run($run->id, 'backup', RunStatus::Failed, $run->ticks, $run->created, $run->updated, 'it broke', null, $run->state));

        $page = $client->get(['step' => 'run'])->body;
        self::assertStringContainsString('it broke', $page);

        $answer = json_decode($client->post(['action' => 'retry', 'format' => 'json'])->body, true);
        self::assertSame(['status' => 'running'], $answer);
        $runs = $workspace->runs()->all('backup');
        self::assertCount(1, $runs);
        self::assertSame(RunStatus::Running, $runs[0]->status);
        self::assertNotSame($run->id, $runs[0]->id, 'a new run, the failed one is gone');
    }

    // ------------------------------------------------------------------------------------------------- coming back

    public function testAPersonWhoComesBackFindsTheRunOrTheResult(): void
    {
        $client = $this->atTheSummary();
        $client->post(['action' => 'probe', 'result' => 'closed']);
        $client->post(['action' => 'start']);

        $back = $this->client();
        self::assertStringContainsString('Backing up', $this->login($back)->body, 'a run that is going on is continued');

        $this->backedUpFrom($back);
        $again = $this->client();
        self::assertStringContainsString('The backup is made', $this->login($again)->body, 'a finished backup is shown again');
    }

    private function backedUpFrom(ToolClient $client): void
    {
        for ($i = 0; $i < 400; ++$i) {
            $answer = json_decode($client->post(['action' => 'tick', 'format' => 'json'])->body, true);
            if (is_array($answer) && ($answer['status'] ?? '') === 'finished') {
                return;
            }
        }
        self::fail('The backup did not finish.');
    }

    public function testGivingUpRemovesWhatTheRunLeftAndGoesBackToTheSummary(): void
    {
        $this->tiny = true;
        $client = $this->atTheSummary();
        $client->post(['action' => 'probe', 'result' => 'closed']);
        $client->post(['action' => 'start']);
        $client->post(['action' => 'tick', 'format' => 'json']);

        $page = $client->post(['action' => 'abandon']);

        self::assertStringContainsString('Back up now', $page->body);
        self::assertSame([], BackupWorkspace::in($this->web)->runs()->all('backup'));
        self::assertSame([], array_values(array_filter(scandir($this->storage()) ?: [], static fn (string $f): bool => str_starts_with($f, '.work-') || str_ends_with($f, '.part'))));
    }

    public function testGivingUpWhileAStepIsWorkingSaysBusyAndTheRunStaysUntilTheStepHasEnded(): void
    {
        $client = $this->atTheSummary();
        $client->post(['action' => 'probe', 'result' => 'closed']);
        $client->post(['action' => 'start']);
        $workspace = BackupWorkspace::in($this->web);
        $run = $workspace->runs()->all('backup')[0];
        $lock = Lock::tryAcquire($workspace->runs()->lockPath($run->id));   // a tick is working right now

        try {
            $busy = json_decode($client->post(['action' => 'abandon', 'format' => 'json'])->body, true);
            self::assertSame(['status' => 'busy'], $busy, 'the page of the person asks again');
            self::assertCount(1, $workspace->runs()->all('backup'));

            $plain = $client->post(['action' => 'abandon']);
            self::assertStringContainsString('Backing up', $plain->body, 'without script the run page is shown again');
        } finally {
            $lock?->release();
        }

        $done = json_decode($client->post(['action' => 'abandon', 'format' => 'json'])->body, true);
        self::assertSame(['status' => 'abandoned', 'next' => '?step=summary'], $done);
        self::assertSame([], $workspace->runs()->all('backup'));
    }

    public function testGivingUpAFinishedRunPointsToTheResultInsteadOfRemovingTheBackup(): void
    {
        $client = $this->atTheSummary();
        $this->backedUp($client);
        $first = $this->parts();

        $answer = json_decode($client->post(['action' => 'abandon', 'format' => 'json'])->body, true);

        self::assertSame(['status' => 'finished', 'next' => '?step=done'], $answer);
        self::assertSame($first, $this->parts(), 'the archive stays');
    }

    public function testTheRunPageGivesTheGiveUpButtonToTheScript(): void
    {
        $client = $this->atTheSummary();
        $client->post(['action' => 'probe', 'result' => 'closed']);
        $client->post(['action' => 'start']);

        $page = $client->get(['step' => 'run'])->body;

        self::assertStringContainsString('id="giveup"', $page);
        self::assertStringContainsString("post('abandon')", $page);
    }

    public function testAgainLeavesTheArchiveAndStartsAnother(): void
    {
        $client = $this->atTheSummary();
        $this->backedUp($client);
        $first = $this->parts();

        $page = $client->post(['action' => 'again']);
        self::assertStringContainsString('Back up now', $page->body);
        self::assertSame($first, $this->parts(), 'the archive stays');
    }

    // ------------------------------------------------------------------------------------------------- the downloads

    public function testAPartIsDownloadedWithItsSha256AndCanBeResumed(): void
    {
        $client = $this->atTheSummary();
        $this->backedUp($client);
        $parts = $this->parts();
        usort($parts, fn (string $a, string $b): int => filesize($this->storage() . '/' . $b) <=> filesize($this->storage() . '/' . $a));
        $part = $parts[0];   // the biggest: the first part of a backup may hold little more than the start
        $path = $this->storage() . '/' . $part;

        $whole = $client->get(['step' => 'file', 'part' => $part]);
        self::assertSame(200, $whole->status);
        self::assertSame('"' . hash_file('sha256', $path) . '"', $whole->header('ETag'));
        self::assertStringContainsString('attachment; filename="' . $part . '"', (string) $whole->header('Content-Disposition'));
        self::assertSame(hash_file('sha256', $path), hash('sha256', $this->body($whole)));
        self::assertNotNull($whole->header('Content-Security-Policy'), 'the security headers are on it');
        self::assertStringNotContainsString('no-store', (string) $whole->header('Cache-Control'), 'a resume needs the file to be cacheable by the client');

        $size = (int) filesize($path);
        $rest = $client->get(['step' => 'file', 'part' => $part], true, ['range' => 'bytes=1000-']);
        self::assertSame(206, $rest->status);
        self::assertSame('bytes 1000-' . ($size - 1) . '/' . $size, $rest->header('Content-Range'));
        self::assertSame((string) file_get_contents($path, false, null, 1000), $this->body($rest));
    }

    public function testOnlyThePartsOfTheBackupAreHandedOutAndOnlyToTheSession(): void
    {
        $client = $this->atTheSummary();
        $this->backedUp($client);
        file_put_contents($this->storage() . '/other.zip', 'not a part');

        self::assertSame(404, $client->get(['step' => 'file', 'part' => 'other.zip'])->status);
        self::assertSame(404, $client->get(['step' => 'file', 'part' => '../../threadsafe-backup-key.php'])->status);
        self::assertSame(404, $client->get(['step' => 'file'])->status);

        $stranger = $this->client();
        $answer = $stranger->get(['step' => 'file', 'part' => $this->parts()[0]]);
        self::assertStringContainsString('Key from', $answer->body, 'without a login the answer is the key page');
        self::assertNull($answer->stream);
    }

    // ------------------------------------------------------------------------------------------------- the end

    public function testTheArchiveIsDeletedOnlyWhenTheBoxIsTicked(): void
    {
        $client = $this->atTheSummary();
        $this->backedUp($client);

        $refused = $client->post(['action' => 'delete-archive']);
        self::assertStringContainsString('Tick that you have the backup', $refused->body);
        self::assertNotSame([], $this->parts());

        $done = $client->post(['action' => 'delete-archive', 'sure' => '1']);
        self::assertStringContainsString('The archive is deleted', $done->body);
        self::assertSame([], $this->parts());
    }

    public function testTheToolIsTakenOffAndTheArchiveStaysUnlessToldOtherwise(): void
    {
        $client = $this->atTheSummary();
        $this->backedUp($client);
        $parts = $this->parts();
        $storage = $this->storage();

        $page = $client->post(['action' => 'cleanup']);

        self::assertStringContainsString('The tool is gone', $page->body);
        self::assertStringContainsString($storage, $page->body, 'it says where the archive is');
        self::assertFileDoesNotExist($this->web . '/threadsafe-backup.php');
        self::assertFileDoesNotExist($this->web . '/threadsafe-backup-key.php');
        self::assertSame($parts, $this->parts());
        $left = array_values(array_diff(scandir($this->web . '/.threadsafe-backup') ?: [], ['.', '..']));
        self::assertSame([basename($storage)], $left, 'only the storage with the archive is left of the state directory');
    }

    public function testTheToolAndTheArchiveCanGoTogether(): void
    {
        $client = $this->atTheSummary();
        $this->backedUp($client);

        $page = $client->post(['action' => 'cleanup', 'archive' => '1']);

        self::assertStringContainsString('The tool is gone', $page->body);
        self::assertDirectoryDoesNotExist($this->web . '/.threadsafe-backup');
        self::assertFileDoesNotExist($this->web . '/threadsafe-backup.php');
        self::assertFileExists($this->web . '/index.php', 'the site is untouched');
    }

    public function testTheArchiveHoldsTheSiteAndNoneOfTheTool(): void
    {
        $client = $this->atTheSummary();
        $this->backedUp($client);

        $names = [];
        foreach ($this->parts() as $part) {
            $reader = \ThreadSafe\Archive\ZipReader::open($this->storage() . '/' . $part);
            foreach ($reader->entries() as $entry) {
                $names[] = $entry->name;
            }
        }
        self::assertContains('files/index.php', $names);
        self::assertContains('files/assets/big.bin', $names);
        foreach ($names as $name) {
            self::assertStringNotContainsString('threadsafe-backup', $name);
        }
    }
}
