<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Archive\ZipReader;
use ThreadSafe\Backup\BackupRun;
use ThreadSafe\Cli\Application;
use ThreadSafe\Cli\BackupCommand;
use ThreadSafe\Cli\BufferedOutput;
use ThreadSafe\Cli\ProfileCommand;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Engine\Executor;
use ThreadSafe\Engine\Lock;
use ThreadSafe\Engine\RunStore;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * The `profile` and `backup` commands as the application runs them, with a data directory of their own and sites without
 * a database ("any website").
 */
final class ProfileAndBackupCommandTest extends TestCase
{
    use TempDirectory;

    private string $data;

    private string $site;

    private string $storage;

    protected function setUp(): void
    {
        $base = $this->tempDirectory();
        $this->data = $base . '/data';
        $this->site = $base . '/site';
        $this->storage = $base . '/storage';
        mkdir($this->site . '/sub', 0777, true);
        file_put_contents($this->site . '/index.php', '<?php echo 1;');
        file_put_contents($this->site . '/sub/data.txt', str_repeat('data ', 1000));
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string, string} exit code, stdout, stderr
     */
    private function cli(array $arguments, bool $verbose = false): array
    {
        $registry = PluginRegistry::load();
        $application = new Application([new ProfileCommand($this->data, $registry), new BackupCommand($this->data, $registry)]);
        $output = new BufferedOutput($verbose);
        $code = $application->run(['threadsafe', ...$arguments], $output);

        return [$code, $output->stdout(), $output->stderr()];
    }

    private function add(string $id = 'blog', ?string $site = null, ?string $storage = null): void
    {
        [$code, , $stderr] = $this->cli(['profile', 'add', $id, '--type=generic', '--path=' . ($site ?? $this->site), '--storage=' . ($storage ?? $this->storage . '/' . $id)]);
        self::assertSame(0, $code, $stderr);
    }

    public function testProfileLifecycle(): void
    {
        [$code, $stdout] = $this->cli(['profile', 'list']);
        self::assertSame(0, $code);
        self::assertStringContainsString('No profiles yet', $stdout);

        [$code, $stdout] = $this->cli(['profile', 'add', 'blog', '--type=generic', '--path=' . $this->site, '--storage=' . $this->storage, '--name=My Blog', '--exclude=cache', '--exclude=*.log', '--structure-only=sessions', '--compression=9', '--part-size=500M', '--note=test']);
        self::assertSame(0, $code, $stdout);
        self::assertStringContainsString('Profile "blog" added.', $stdout);

        [, $stdout] = $this->cli(['profile', 'list']);
        self::assertMatchesRegularExpression('/^blog\s+generic\s+' . preg_quote($this->site, '/') . '$/m', $stdout);

        [, $stdout] = $this->cli(['profile', 'show', 'blog']);
        self::assertStringContainsString('Profile blog (My Blog)', $stdout);
        self::assertStringContainsString('cache, *.log', $stdout);
        self::assertStringContainsString('parts of 500 MB', $stdout);

        [$code, $stdout] = $this->cli(['profile', 'edit', 'blog', '--note=changed', '--no-part-size', '--exclude=tmp']);
        self::assertSame(0, $code, $stdout);
        $profile = (new ProfileStore($this->data . '/profiles'))->load('blog');
        self::assertSame('changed', $profile->note);
        self::assertNull($profile->partSize);
        self::assertSame(['tmp'], $profile->excludePaths, 'edit replaces a list that is given.');
        self::assertSame('My Blog', $profile->name, 'What is not mentioned stays.');
        self::assertSame(9, $profile->compression);

        [$code, $stdout] = $this->cli(['profile', 'test', 'blog']);
        self::assertSame(0, $code);
        self::assertStringContainsString('Any website', $stdout);
        self::assertStringContainsString('The profile can be backed up.', $stdout);

        [$code, , $stderr] = $this->cli(['profile', 'remove', 'blog']);
        self::assertSame(1, $code);
        self::assertStringContainsString('needs --yes', $stderr);
        [$code, $stdout] = $this->cli(['profile', 'remove', 'blog', '--yes']);
        self::assertSame(0, $code);
        self::assertStringContainsString('backups stay', $stdout);
        self::assertFalse((new ProfileStore($this->data . '/profiles'))->exists('blog'));
    }

    private const string KEY = 'age1ql3z7hjy54pw3hyww5ayyfg7zqgvc7w3j2elw8zmrj2kg5sfn9aqmcac8p';

    public function testScheduleRetentionAndEncryptionAreSetShownAndChanged(): void
    {
        mkdir($this->data, 0777, true);
        file_put_contents($this->data . '/config.json', '{"schedule": {"timezone": "Europe/Berlin"}}');

        [$code, $stdout, $stderr] = $this->cli(['profile', 'add', 'blog', '--type=generic', '--path=' . $this->site, '--storage=' . $this->storage, '--schedule=30 3 * * *', '--keep=14', '--max-age-days=90', '--recipient=' . self::KEY]);
        self::assertSame(0, $code, $stdout . $stderr);
        $profile = (new ProfileStore($this->data . '/profiles'))->load('blog');
        self::assertSame('30 3 * * *', $profile->schedule?->cron);
        self::assertSame([14, 90, null], [$profile->retention?->keep, $profile->retention?->maxAgeDays, $profile->retention?->maxBytes]);
        self::assertSame([self::KEY], $profile->encryption?->recipients);

        [, $stdout] = $this->cli(['profile', 'show', 'blog']);
        self::assertStringContainsString('schedule:   30 3 * * * (time zone Europe/Berlin, next run ', $stdout);
        self::assertMatchesRegularExpression('/next run \d{4}-\d\d-\d\d 03:30 (CET|CEST)\)/', $stdout);
        self::assertStringContainsString('retention:  keep 14, 90 days at most', $stdout);
        self::assertStringContainsString('encrypted for 1 public key: age1ql3z7hjy...mcac8p', $stdout);
        self::assertStringNotContainsString(self::KEY, $stdout, 'the key is shortened for the eye');

        [, $json] = $this->cli(['profile', 'show', 'blog', '--json']);
        $data = json_decode($json, true);
        self::assertIsArray($data);
        self::assertSame(['cron' => '30 3 * * *'], $data['schedule']);
        self::assertSame(['keep' => 14, 'maxAgeDays' => 90], $data['retention']);
        self::assertSame(['recipients' => [self::KEY]], $data['encryption']);

        // a rule that is not named stays; the others are replaced
        [$code] = $this->cli(['profile', 'edit', 'blog', '--keep=7', '--max-bytes=20G']);
        self::assertSame(0, $code);
        $profile = (new ProfileStore($this->data . '/profiles'))->load('blog');
        self::assertSame([7, 90, 21474836480], [$profile->retention?->keep, $profile->retention?->maxAgeDays, $profile->retention?->maxBytes]);
        self::assertSame('30 3 * * *', $profile->schedule?->cron, 'what is not mentioned stays');

        [$code] = $this->cli(['profile', 'edit', 'blog', '--no-retention', '--no-schedule', '--no-encryption']);
        self::assertSame(0, $code);
        $profile = (new ProfileStore($this->data . '/profiles'))->load('blog');
        self::assertNull($profile->schedule);
        self::assertNull($profile->retention);
        self::assertNull($profile->encryption);
        $stored = json_decode((string) file_get_contents($this->data . '/profiles/blog.json'), true);
        self::assertIsArray($stored);
        self::assertArrayNotHasKey('schedule', $stored);
    }

    public function testMistakesInTheSchedulingOptionsAreUsageErrors(): void
    {
        $this->add();

        foreach ([
            [['--schedule=61 3 * * *'], 'out of range'],
            [['--schedule=nonsense'], 'five fields'],
            [['--schedule=0 3 * * *', '--no-schedule'], 'contradict'],
            [['--keep=0'], '"retention.keep"'],
            [['--keep=ten'], 'whole number'],
            [['--max-bytes=lots'], 'size'],
            [['--max-bytes=1000'], '"retention.maxBytes"'],
            [['--keep=5', '--no-retention'], 'contradicts'],
            [['--recipient=not-a-key'], 'not a public key'],
            [['--recipient=' . self::KEY, '--no-encryption'], 'contradict'],
        ] as [$options, $message]) {
            [$code, , $stderr] = $this->cli(['profile', 'edit', 'blog', ...$options]);
            self::assertSame(1, $code, implode(' ', $options));
            self::assertStringContainsString($message, $stderr, implode(' ', $options));
        }
        self::assertNull((new ProfileStore($this->data . '/profiles'))->load('blog')->schedule, 'nothing was stored');
    }

    public function testShowAndListAsJsonNeverContainThePassword(): void
    {
        $password = $this->tempDirectory() . '/pw';
        file_put_contents($password, "s3cret-pw\n");
        [$code, , $stderr] = $this->cli(['profile', 'add', 'blog', '--type=generic', '--path=' . $this->site, '--storage=' . $this->storage, '--db-name=blog', '--db-user=u', '--db-password-file=' . $password, '--no-test']);
        self::assertSame(0, $code, $stderr);

        self::assertSame('s3cret-pw', (new ProfileStore($this->data . '/profiles'))->load('blog')->database?->password, 'The trailing newline is not part of the password.');
        foreach ([['profile', 'show', 'blog'], ['profile', 'show', 'blog', '--json'], ['profile', 'list', '--json']] as $arguments) {
            [$code, $stdout, $stderr] = $this->cli($arguments);
            self::assertSame(0, $code);
            self::assertStringNotContainsString('s3cret-pw', $stdout . $stderr, implode(' ', $arguments));
        }
        self::assertStringContainsString('"password": "********"', $this->cli(['profile', 'show', 'blog', '--json'])[1]);

        // the stored file is private
        if (DIRECTORY_SEPARATOR === '/') {
            self::assertSame('0600', substr(sprintf('%o', fileperms($this->data . '/profiles/blog.json')), -4));
        }
        [$code] = $this->cli(['profile', 'edit', 'blog', '--no-database', '--no-test']);
        self::assertSame(0, $code);
        self::assertNull((new ProfileStore($this->data . '/profiles'))->load('blog')->database);
    }

    public function testProfileMistakes(): void
    {
        $this->add();
        foreach ([
            [['profile'], 'Say what to do'],
            [['profile', 'frobnicate', 'x'], 'Unknown action'],
            [['profile', 'add'], 'needs the id'],
            [['profile', 'add', 'blog', '--type=generic', '--path=/x', '--storage=/y'], 'exists already'],
            [['profile', 'add', 'Bad Id', '--type=generic', '--path=/x', '--storage=/y'], 'lower-case'],
            [['profile', 'add', 'new', '--type=generic'], 'needs --path'],
            [['profile', 'add', 'new', '--type=generic', '--path=relative', '--storage=/y'], 'absolute path'],
            [['profile', 'add', 'new', '--type=generic', '--path=/x', '--storage=/y', '--compression=12'], '0 to 9'],
            [['profile', 'add', 'new', '--type=generic', '--path=/x', '--storage=/y', '--part-size=10K'], 'at least 1M'],
            [['profile', 'add', 'new', '--type=generic', '--path=/x', '--storage=/y', '--no-database'], 'only makes sense with'],
            [['profile', 'add', 'new', '--type=generic', '--path=/x', '--storage=/y', '--db-name=a', '--db-user=b', '--db-password-file=/nonexistent'], 'cannot be read'],
            [['profile', 'edit', 'blog'], 'Nothing to change'],
            [['profile', 'edit', 'nope', '--note=x'], 'There is no profile'],
            [['profile', 'show', 'nope'], 'There is no profile'],
            [['profile', 'list', 'extra'], 'Unexpected argument'],
            [['profile', 'show', 'blog', 'extra'], 'Unexpected argument'],
        ] as [$arguments, $message]) {
            [$code, , $stderr] = $this->cli($arguments);
            self::assertSame(1, $code, implode(' ', $arguments) . ': ' . $stderr);
            self::assertStringContainsString($message, $stderr, implode(' ', $arguments));
        }
    }

    public function testAProfileThatCannotWorkIsNotStoredUnlessAskedTo(): void
    {
        [$code, $stdout, $stderr] = $this->cli(['profile', 'add', 'ghost', '--type=generic', '--path=' . $this->tempDirectory() . '/missing', '--storage=' . $this->storage]);

        self::assertSame(2, $code);
        self::assertStringContainsString('FAILED', $stdout);
        self::assertStringContainsString('was not stored', $stderr);
        self::assertFalse((new ProfileStore($this->data . '/profiles'))->exists('ghost'));

        [$code] = $this->cli(['profile', 'add', 'ghost', '--type=generic', '--path=' . $this->tempDirectory() . '/missing', '--storage=' . $this->storage, '--no-test']);
        self::assertSame(0, $code);
        [$code, $stdout] = $this->cli(['profile', 'test', 'ghost']);
        self::assertSame(2, $code, 'A profile that fails its test says so with the exit code of a refusal.');
        self::assertStringContainsString('cannot be backed up', $stdout);
    }

    public function testProfileTestAsJson(): void
    {
        $this->add();
        [$code, $stdout] = $this->cli(['profile', 'test', 'blog', '--json']);

        self::assertSame(0, $code);
        $report = json_decode($stdout, true);
        self::assertTrue($report['passed']);
        self::assertSame('blog', $report['profile']);
        self::assertSame('Site', $report['checks'][0]['subject']);
    }

    public function testBackupOfOneProfile(): void
    {
        $this->add();
        $reportFile = $this->tempDirectory() . '/report.json';

        [$code, $stdout, $stderr] = $this->cli(['backup', 'blog', '--report=' . $reportFile]);

        self::assertSame(0, $code, $stderr);
        self::assertStringContainsString('Backing up "blog"', $stdout);
        self::assertMatchesRegularExpression('/Backup blog_\d{8}-\d{6}_[0-9a-f]{8}: .*2 file\(s\) and 1 director/', $stdout);
        $archives = glob($this->storage . '/blog/blog_*.zip') ?: [];
        self::assertCount(1, $archives);
        self::assertTrue(ZipReader::open($archives[0])->verify()->ok());
        self::assertCount(1, glob($this->storage . '/blog/blog_*.json') ?: []);
        self::assertSame([], (new RunStore($this->data . '/runs'))->all(), 'A finished run deletes its state.');
        $report = json_decode((string) file_get_contents($reportFile), true);
        self::assertSame('ok', $report['result']['status']);
        self::assertSame(basename($archives[0]), $report['result']['parts'][0]);
    }

    public function testBackupInTicksPrintsProgressWhenVerbose(): void
    {
        $this->add();

        [$code, $stdout] = $this->cli(['backup', 'blog', '--tick-seconds=0'], verbose: true);

        self::assertSame(0, $code);
        self::assertMatchesRegularExpression('/\[tick \d+\] scanning the site/', $stdout);
        self::assertMatchesRegularExpression('/\[tick \d+\] packing files/', $stdout);
        self::assertStringContainsString('complete', $stdout);
    }

    public function testBackupAllReportsAProfileThatCannotBeBackedUp(): void
    {
        $this->add('aaa');
        $this->cli(['profile', 'add', 'bbb', '--type=generic', '--path=' . $this->tempDirectory() . '/missing', '--storage=' . $this->storage . '/bbb', '--no-test']);
        $this->add('ccc');

        [$code, $stdout, $stderr] = $this->cli(['backup', '--all']);

        self::assertSame(2, $code, 'A refusal is a refusal, the others are still backed up.');
        self::assertStringContainsString('Profile "bbb" refused', $stderr);
        self::assertStringContainsString('2 of 3 profiles backed up.', $stdout);
        self::assertCount(1, glob($this->storage . '/aaa/aaa_*.zip') ?: []);
        self::assertCount(1, glob($this->storage . '/ccc/ccc_*.zip') ?: []);
        self::assertSame([], glob($this->storage . '/bbb/*.zip') ?: []);
    }

    public function testBackupMistakes(): void
    {
        $this->add();
        foreach ([
            [['backup'], 'Say what to back up'],
            [['backup', 'blog', '--all'], 'Say what to back up'],
            [['backup', 'nope'], 'There is no profile'],
            [['backup', 'blog', 'extra'], 'Unexpected argument'],
            [['backup', 'blog', '--part-size=1K'], 'at least 1M'],
            [['backup', 'blog', '--compression=x'], '0 to 9'],
            [['backup', 'blog', '--tick-seconds=soon'], 'number of seconds'],
            [['backup', '--resume=nope-20260101-000000-00000000'], 'There is no run'],
        ] as [$arguments, $message]) {
            [$code, , $stderr] = $this->cli($arguments);
            self::assertSame(1, $code, implode(' ', $arguments) . ': ' . $stderr);
            self::assertStringContainsString($message, $stderr, implode(' ', $arguments));
        }
    }

    public function testOnlyOneBackupRunsAtATime(): void
    {
        $this->add();
        $lock = Lock::tryAcquire($this->data . '/locks/backup.lock', 'another backup');
        self::assertNotNull($lock);

        [$code, , $stderr] = $this->cli(['backup', 'blog']);

        self::assertSame(2, $code);
        self::assertStringContainsString('Another backup is running', $stderr);
        $lock->release();
        [$code] = $this->cli(['backup', 'blog']);
        self::assertSame(0, $code, 'The lock is free again.');
    }

    public function testAnInterruptedRunIsContinuedWithResume(): void
    {
        $this->add();
        $profiles = new ProfileStore($this->data . '/profiles');
        $runs = new RunStore($this->data . '/runs');
        $task = new BackupRun($profiles, PluginRegistry::load(), $this->data);
        $executor = new Executor($runs, $task, static fn (): Budget => new Budget(0.0));
        $run = $executor->start(['profile' => 'blog']);
        $executor->execute($run->id, 5);   // the process "dies" here: the run stays "running" without a lock

        [$code, $stdout] = $this->cli(['backup', 'blog']);
        self::assertSame(0, $code);
        self::assertStringContainsString('an interrupted backup of "blog" exists (' . $run->id . ')', $stdout);

        [$code, $stdout, $stderr] = $this->cli(['backup', '--resume=' . $run->id]);

        self::assertSame(0, $code, $stderr);
        self::assertStringContainsString('Continuing the backup of "blog"', $stdout);
        self::assertCount(2, glob($this->storage . '/blog/blog_*.zip') ?: [], 'The new backup and the continued one.');
        self::assertSame([], $runs->all());
    }
}
