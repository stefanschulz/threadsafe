<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Group;
use ThreadSafe\Archive\ZipReader;
use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Profile\SiteProfile;
use ThreadSafe\Schedule\Scheduler;
use ThreadSafe\Schedule\ScheduleStore;
use ThreadSafe\Tests\Support\CliProcess;
use ThreadSafe\Tests\Support\WebUi;

/**
 * The trigger and retention on real backups (docs/PHASE5.md 4.2): a profile that backs up a small directory and the whole 299-table database of the
 * seeded shop is due; `threadsafe cron` makes the backup (one snapshot, source `schedule`, the heartbeat), a second call does nothing,
 * retention keeps the newest; the address `/cron/<secret>` of the service `web` (Apache, 5 seconds and 64 MB) makes the same backup by
 * ticks or by a detached worker; a trigger that is killed in the middle of a backup is continued by the next call and the archive equals
 * an uninterrupted one.
 *
 * Retention: four real backups of a small directory, one pinned, one damaged and found faulty by a check; the rule keeps one; the dry run
 * lists exactly what the real run then deletes, and the newest, the pinned and the faulty one stay.
 *
 * Not here: the clock-dependent cases (missed by less or more than the grace, the retries after a failure), which the unit tests of
 * `Scheduler` and `TriggerService` run on a clock of their own; and the profile page, which `EncryptionPagesTest` and `RetentionPagesTest` cover.
 */
#[Group('trigger')]
final class TriggerStackTest extends IntegrationTestCase
{
    private const string DIRECTORY = '/app/.cache/trigger';

    private const string SITE = '/shops/source/modules/threadsafe_fixture';

    protected function setUp(): void
    {
        parent::setUp();
        if (!is_dir(self::SITE)) {
            self::markTestSkipped('The seeded shop is not mounted at /shops/source.');
        }
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string, string}
     */
    private static function threadsafe(string $data, array $arguments, int $timeout = 900): array
    {
        putenv('THREADSAFE_DATA=' . $data);
        try {
            return CliProcess::run($arguments, $timeout);
        } finally {
            putenv('THREADSAFE_DATA');
        }
    }

    /**
     * A profile with a schedule that is due, in the given data directory.
     *
     * @param array<string, mixed> $more more fields of the profile
     */
    private static function profile(string $data, string $storage, array $more = []): void
    {
        (new ProfileStore($data . '/profiles'))->save(SiteProfile::fromArray([
            'id' => 'sched', 'name' => 'Scheduled', 'type' => 'generic', 'path' => self::SITE, 'storage' => $storage,
            'database' => ['host' => 'db', 'port' => 3306, 'name' => 'prestashop', 'user' => 'prestashop', 'password' => getenv('DB_PASSWORD') ?: 'prestashop', 'prefix' => 'ps_', 'driver' => 'auto'],
            'schedule' => ['cron' => '0 * * * *'],
        ] + $more));
        self::due($data);
    }

    /** Makes this hour's scheduled time due: the schedule has counted for two hours. */
    private static function due(string $data): void
    {
        (new Scheduler(new ScheduleStore($data . '/schedule'), new DateTimeZone('UTC')))->reset('sched', new DateTimeImmutable('-2 hours'));
    }

    /** @return list<string> the sidecar files of the storage, oldest first */
    private static function sidecars(string $storage): array
    {
        $files = glob($storage . '/sched_*.json') ?: [];
        sort($files);

        return $files;
    }

    /** @return array<string, mixed> */
    private static function record(string $sidecar): array
    {
        return json_decode((string) file_get_contents($sidecar), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, int> name => CRC32 of the entries of the archive that hold the site and the database
     */
    private static function content(string $storage, string $sidecar): array
    {
        $names = [];
        foreach (self::record($sidecar)['parts'] as $part) {
            foreach (ZipReader::open($storage . '/' . $part['file'])->entries() as $entry) {
                if (str_starts_with($entry->name, 'files/') || str_starts_with($entry->name, 'db/')) {
                    $names[$entry->name] = $entry->crc32;
                }
            }
        }
        ksort($names);

        return $names;
    }

    public function testTheCommandMakesTheDueBackupOnceAndRetentionKeepsTheNewest(): void
    {
        $dir = self::DIRECTORY . '/command';
        exec('rm -rf ' . escapeshellarg($dir));
        mkdir($dir, 0777, true);
        $data = $dir . '/data';
        $storage = $dir . '/storage';
        self::profile($data, $storage, ['retention' => ['keep' => 1]]);

        [$code, $out, $err] = self::threadsafe($data, ['cron']);
        self::assertSame(0, $code, $out . $err);
        self::assertStringContainsString('Backing up "sched"', $out);
        $first = self::sidecars($storage);
        self::assertCount(1, $first);
        $record = self::record($first[0]);
        self::assertSame('snapshot', $record['manifest']['consistency'], 'the command reads the database in one snapshot');
        self::assertSame('schedule', $record['source']);
        self::assertGreaterThan(250, $record['manifest']['counts']['tables']);
        $heartbeat = json_decode((string) file_get_contents($data . '/heartbeat.json'), true);
        self::assertSame('cli', $heartbeat['via']);

        [$code, $out] = self::threadsafe($data, ['cron']);
        self::assertSame(0, $code);
        self::assertStringContainsString('Nothing to do.', $out, 'a second call within the hour does nothing');
        self::assertCount(1, self::sidecars($storage));

        // the next scheduled time comes: a second backup, and retention keeps only the newest
        sleep(1);
        self::due($data);
        [$code, $out, $err] = self::threadsafe($data, ['cron']);
        self::assertSame(0, $code, $out . $err);
        $after = self::sidecars($storage);
        self::assertCount(1, $after, 'keep 1: the older backup is deleted');
        self::assertNotSame(basename($first[0]), basename($after[0]));
        self::assertSame([], glob($storage . '/' . basename($first[0], '.json') . '*') ?: [], 'all files of the older backup are gone');
        self::assertStringContainsString('retention.deleted', (string) file_get_contents($data . '/audit.log'));
    }

    /**
     * The service `web` (Apache, 5 seconds and 64 MB) with its data directory prepared from here: no interface set up, a profile, a secret.
     */
    private static function prepareWeb(string $mode): string
    {
        if (!is_dir('/web-data') || !is_dir('/web-storage')) {
            self::markTestSkipped('The web service is not part of this stack (docker compose --profile web).');
        }
        exec('find /web-data /web-storage -mindepth 1 -delete 2>&1', $ignored, $code);
        self::assertSame(0, $code, 'the volumes of the web service can be emptied');
        $data = '/web-data';
        // the web service sees its storage volume at /srv/storage (this container at /web-storage)
        self::profile($data, '/srv/storage/sched');
        [$code, $out, $err] = self::threadsafe($data, ['admin', 'set', 'cron.mode', $mode]);
        self::assertSame(0, $code, $out . $err);
        [$code, $out, $err] = self::threadsafe($data, ['admin', 'cron-secret']);
        self::assertSame(0, $code, $out . $err);
        $secret = trim((string) file_get_contents($data . '/cron-secret'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $secret);
        // Apache runs as www-data; what was made here belongs to root
        exec('chown -R www-data:www-data /web-data /web-storage 2>&1', $ignored, $code);
        self::assertSame(0, $code);

        return $secret;
    }

    public function testTheAddressMakesTheBackupByTicksUnderTheLimitsAndAWrongSecretIsNotFound(): void
    {
        $secret = self::prepareWeb('ticks');
        $web = new WebUi();

        $wrong = $web->get('/cron/' . str_repeat('a', 64));
        self::assertSame(404, $wrong['status']);
        self::assertStringNotContainsString('idle', $wrong['body']);
        self::assertDirectoryDoesNotExist('/web-storage/sched');

        $words = [];
        for ($call = 1; $call <= 400; ++$call) {
            $answer = $web->get('/cron/' . $secret);
            self::assertSame(200, $answer['status'], $answer['body']);
            $word = strtok($answer['body'], "\n");
            $words[] = $word;
            if ($word === 'idle') {
                break;
            }
            self::assertSame('working', $word, 'in ticks mode the answer is working until the backup is done: ' . $answer['body']);
        }
        self::assertSame('idle', end($words), 'the backup was finished by the calls');
        self::assertContains('working', $words, 'a backup is not done by one call: the first only names it, the others are ticks');
        self::assertGreaterThanOrEqual(2, count($words));
        $sidecars = self::sidecars('/web-storage/sched');
        self::assertCount(1, $sidecars);
        $record = self::record($sidecars[0]);
        self::assertSame('table', $record['manifest']['consistency'], 'ticks read the database table by table');
        self::assertSame('schedule', $record['source']);
        $heartbeat = json_decode((string) file_get_contents('/web-data/heartbeat.json'), true);
        self::assertSame('web', $heartbeat['via']);

        $again = $web->get('/cron/' . $secret);
        self::assertStringStartsWith("idle\n", $again['body'], 'one call too many does nothing');
        self::assertCount(1, self::sidecars('/web-storage/sched'));
    }

    public function testTheAddressStartsADetachedWorkerThatMakesTheBackupInOneSnapshot(): void
    {
        $secret = self::prepareWeb('worker');
        $web = new WebUi();

        $first = $web->get('/cron/' . $secret);
        self::assertSame(200, $first['status'], $first['body']);
        self::assertStringStartsWith("started\n", $first['body'], 'the worker is started and the answer comes at once: ' . $first['body']);

        $second = $web->get('/cron/' . $secret);
        self::assertStringStartsWith("working\n", $second['body'], 'a second call while it works starts nothing: ' . $second['body']);

        $sidecars = [];
        for ($wait = 0; $wait < 120; ++$wait) {
            sleep(2);
            $sidecars = self::sidecars('/web-storage/sched');
            if ($sidecars !== [] && str_starts_with($web->get('/cron/' . $secret)['body'], 'idle')) {
                break;
            }
        }
        self::assertCount(1, $sidecars, 'exactly one backup, made by the worker');
        $record = self::record($sidecars[0]);
        self::assertSame('snapshot', $record['manifest']['consistency'], 'a worker is a command line process: one snapshot');
        self::assertSame('schedule', $record['source']);
    }

    public function testATriggerThatIsKilledInTheMiddleIsContinuedAndTheArchiveEqualsAnUninterruptedOne(): void
    {
        $dir = self::DIRECTORY . '/killed';
        exec('rm -rf ' . escapeshellarg($dir));
        mkdir($dir, 0777, true);
        $data = $dir . '/data';
        $storage = $dir . '/storage';
        self::profile($data, $storage);

        // the trigger, killed as soon as the backup has begun to write
        putenv('THREADSAFE_DATA=' . $data);
        try {
            $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/bin/threadsafe', 'cron'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        } finally {
            putenv('THREADSAFE_DATA');
        }
        self::assertIsResource($process);
        $killed = false;
        for ($i = 0; $i < 600 && !$killed; ++$i) {
            usleep(100000);
            if (glob($storage . '/.work-*/*') !== [] && glob($storage . '/.work-*/*') !== false) {
                proc_terminate($process, 9);
                $killed = true;
            }
        }
        proc_close($process);
        self::assertTrue($killed, 'the backup began to write');
        self::assertSame([], self::sidecars($storage), 'and was not finished');

        // the next call continues it
        [$code, $out, $err] = self::threadsafe($data, ['cron']);
        self::assertSame(0, $code, $out . $err);
        $sidecars = self::sidecars($storage);
        self::assertCount(1, $sidecars, 'one backup, not two');
        self::assertSame('schedule', self::record($sidecars[0])['source']);
        self::assertSame([], glob($storage . '/.work-*') ?: [], 'nothing of the killed run is left');
        [$code, $out, $err] = self::threadsafe($data, ['verify', 'sched']);
        self::assertSame(0, $code, $out . $err);

        // an uninterrupted backup of the same site equals it
        [$code, $out, $err] = self::threadsafe($data, ['backup', 'sched']);
        self::assertSame(0, $code, $out . $err);
        $both = self::sidecars($storage);
        self::assertCount(2, $both);
        self::assertSame(self::content($storage, $both[1]), self::content($storage, $both[0]), 'the same files and the same SQL files with the same checksums');
    }
    public function testRetentionOnRealBackupsKeepsTheNewestThePinnedAndTheFaultyOneAndTheDryRunAgreesWithTheRun(): void
    {
        $dir = self::DIRECTORY . '/retention';
        exec('rm -rf ' . escapeshellarg($dir));
        mkdir($dir, 0777, true);
        $data = $dir . '/data';
        $storage = $dir . '/storage';
        $profiles = new ProfileStore($data . '/profiles');
        $profiles->save(SiteProfile::fromArray(['id' => 'ret', 'type' => 'generic', 'path' => self::SITE, 'storage' => $storage]));

        $bases = [];
        for ($i = 0; $i < 4; ++$i) {
            [$code, $out, $err] = self::threadsafe($data, ['backup', 'ret']);
            self::assertSame(0, $code, $out . $err);
            sleep(1);
        }
        $bases = array_map(static fn (string $f): string => basename($f, '.json'), glob($storage . '/ret_*.json') ?: []);
        sort($bases);
        self::assertCount(4, $bases);
        $profiles->save($profiles->load('ret')->with(['retention' => ['keep' => 1]]));
        [$oldest, $second, $third, $newest] = $bases;

        [$code, $out, $err] = self::threadsafe($data, ['backups', 'ret', '--pin=' . $oldest]);
        self::assertSame(0, $code, $out . $err);

        // the second backup is damaged (a byte in the middle of its archive) and found faulty by a full check
        $part = $storage . '/' . $second . '.zip';
        $handle = fopen($part, 'r+b');
        self::assertNotFalse($handle);
        $middle = intdiv((int) filesize($part), 2);
        fseek($handle, $middle);
        $byte = (string) fread($handle, 1);
        fseek($handle, $middle);
        fwrite($handle, chr(ord($byte) ^ 0x10));
        fclose($handle);
        [$code] = self::threadsafe($data, ['verify', 'ret', $second]);
        self::assertSame(3, $code, 'the check finds the damage');

        // the dry run says what would go, and deletes nothing
        [$code, $dry, $err] = self::threadsafe($data, ['retention', 'ret', '--dry-run', '--json']);
        self::assertSame(0, $code, $dry . $err);
        $plan = json_decode($dry, true, 512, JSON_THROW_ON_ERROR);
        $planned = array_map(static fn (array $a): string => $a['backup'], $plan['delete'] ?? $plan['actions'] ?? []);
        self::assertSame([$third], $planned, 'only the third goes: the newest, the pinned and the faulty one stay');
        self::assertCount(4, glob($storage . '/ret_*.json') ?: [], 'the dry run deleted nothing');

        // the run does exactly that
        [$code, $out, $err] = self::threadsafe($data, ['retention', 'ret']);
        self::assertSame(0, $code, $out . $err);
        $left = array_map(static fn (string $f): string => basename($f, '.json'), glob($storage . '/ret_*.json') ?: []);
        sort($left);
        self::assertSame([$oldest, $second, $newest], $left);
        self::assertSame([], glob($storage . '/' . $third . '*') ?: [], 'all files of the deleted backup are gone');
        $audit = (string) file_get_contents($data . '/audit.log');
        self::assertStringContainsString('retention.deleted', $audit);
        self::assertStringContainsString($third, $audit);
    }
}
