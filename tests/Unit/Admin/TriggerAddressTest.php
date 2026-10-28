<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Admin;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Admin\Config;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Schedule\CronSecret;
use ThreadSafe\Schedule\Heartbeat;
use ThreadSafe\Schedule\Scheduler;
use ThreadSafe\Schedule\ScheduleStore;
use ThreadSafe\Storage\BackupCatalog;
use ThreadSafe\Tests\Support\AdminBrowser;
use ThreadSafe\Tests\Support\BackupFixture;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * The address of the trigger, `/cron/<secret>`, through the kernel: it exists only with a secret, whether or not the interface is
 * switched on; a wrong secret is a plain "not found" and is slowed down; each call does one tick.
 */
final class TriggerAddressTest extends TestCase
{
    use TempDirectory;

    private BackupFixture $fixture;

    private AdminBrowser $browser;

    protected function setUp(): void
    {
        $this->fixture = new BackupFixture($this->tempDirectory());
        $this->browser = new AdminBrowser($this->fixture->data, '', static fn (): Budget => new Budget(0.0));
        $this->browser->now = (new DateTimeImmutable('2026-10-22 03:05:00', new DateTimeZone('UTC')))->getTimestamp();
        // ticks, so that no test starts a real process
        Config::defaults()->with('cron.mode', 'ticks')->save($this->fixture->data . '/config.json');
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    private function secret(): string
    {
        return (new CronSecret($this->fixture->data . '/cron-secret'))->make();
    }

    public function testWithoutASecretThereIsNoSuchAddress(): void
    {
        $off = $this->browser->get('/cron/' . str_repeat('a', 64));
        self::assertSame(404, $off->status, 'the interface is off: everything is not found');
        self::assertFileDoesNotExist($this->fixture->data . '/heartbeat.json', 'and nothing happened');

        $this->browser->install();
        $on = $this->browser->get('/cron/' . str_repeat('a', 64));
        self::assertSame(303, $on->status, 'with the interface on, a request to it is handled like any other: the login');
        self::assertStringContainsString('/login', (string) $on->header('Location'));
    }

    public function testTheAddressWorksWhileTheInterfaceIsSwitchedOff(): void
    {
        $secret = $this->secret();

        $answer = $this->browser->get('/cron/' . $secret);

        self::assertSame(200, $answer->status);
        self::assertStringStartsWith("idle\n", $answer->body, 'nothing is scheduled');
        self::assertStringStartsWith('text/plain', (string) $answer->header('Content-Type'));
        self::assertSame('web', (new Heartbeat($this->fixture->data . '/heartbeat.json'))->read()['via'] ?? null);
        self::assertSame(404, $this->browser->get('/')->status, 'and every other address is still not found');
        self::assertSame(404, $this->browser->get('/login')->status);
    }

    public function testAWrongSecretIsAPlainNotFoundAndIsSlowedDown(): void
    {
        $secret = $this->secret();
        $notFound = $this->browser->get('/nothing-here');

        foreach (range(1, 3) as $ignored) {
            $wrong = $this->browser->get('/cron/' . str_repeat('0', 64));
            self::assertSame(404, $wrong->status);
            self::assertSame($notFound->body, $wrong->body, 'the same answer as for any address that does not exist');
        }
        self::assertFileDoesNotExist($this->fixture->data . '/heartbeat.json');

        self::assertSame(404, $this->browser->get('/cron/' . $secret)->status, 'after three failures even the right secret waits');
        $this->browser->now += 10;
        self::assertSame(200, $this->browser->get('/cron/' . $secret)->status);
    }

    public function testOnlyGetIsAnswered(): void
    {
        $secret = $this->secret();

        self::assertSame(404, $this->browser->send('POST', '/cron/' . $secret, ['x' => 'y'])->status);
        self::assertSame(404, $this->browser->send('DELETE', '/cron/' . $secret)->status);
    }

    public function testEachCallDoesOneTickUntilTheScheduledBackupIsMade(): void
    {
        $secret = $this->secret();
        $profile = $this->fixture->profiles->load('blog')->with(['schedule' => ['every' => 'day', 'at' => '03:00']]);
        $this->fixture->profiles->save($profile);
        (new Scheduler(new ScheduleStore($this->fixture->data . '/schedule'), new DateTimeZone('UTC')))->reset('blog', new DateTimeImmutable('2026-10-21 12:00:00', new DateTimeZone('UTC')));

        $first = $this->browser->get('/cron/' . $secret);
        self::assertStringStartsWith("working\n", $first->body);
        self::assertStringContainsString('backing up "blog"', $first->body);

        $calls = 1;
        $status = 'working';
        while ($status === 'working' && $calls < 300) {
            $this->browser->now += 61;
            $status = strtok($this->browser->get('/cron/' . $secret)->body, "\n");
            ++$calls;
        }

        self::assertSame('idle', $status);
        self::assertGreaterThan(3, $calls);
        self::assertCount(1, array_filter((new BackupCatalog($this->fixture->storage()))->entries('blog', false), static fn ($e): bool => $e->sidecar !== null));
        self::assertStringContainsString('"event":"backup.finished"', (string) file_get_contents($this->fixture->data . '/audit.log'));
        self::assertStringNotContainsString($secret, (string) file_get_contents($this->fixture->data . '/audit.log'), 'the secret is in no log of ours');
    }
}
