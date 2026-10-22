<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Schedule;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Schedule\CronSecret;
use ThreadSafe\Schedule\Heartbeat;
use ThreadSafe\Tests\Support\TempDirectory;

final class CronSecretAndHeartbeatTest extends TestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    public function testASecretIsMadeReadBackComparedAndRemoved(): void
    {
        $file = $this->tempDirectory() . '/data/cron-secret';
        $secret = new CronSecret($file);
        self::assertFalse($secret->exists());
        self::assertFalse($secret->matches(''), 'without a secret nothing matches, not even nothing');

        $made = $secret->make();

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $made);
        self::assertSame($made, $secret->get());
        self::assertTrue($secret->matches($made));
        self::assertFalse($secret->matches(strtoupper($made)));
        self::assertFalse($secret->matches(substr($made, 0, 63)));
        self::assertFalse($secret->matches($made . 'a'));
        if (DIRECTORY_SEPARATOR === '/') {
            self::assertSame(0600, fileperms($file) & 0777);
        }
        $second = $secret->make();
        self::assertNotSame($made, $second, 'a new secret replaces the old one');
        self::assertFalse($secret->matches($made));

        $secret->remove();
        self::assertFalse($secret->exists());
    }

    public function testAFileThatHoldsNoSecretIsNoSecret(): void
    {
        $file = $this->tempDirectory() . '/cron-secret';
        $secret = new CronSecret($file);

        foreach (['', "short\n", str_repeat('g', 64), str_repeat('a', 63)] as $content) {
            file_put_contents($file, $content);
            self::assertFalse($secret->exists(), $content);
            self::assertFalse($secret->matches($content));
        }
    }

    public function testTheHeartbeatIsWrittenAndRead(): void
    {
        $heartbeat = new Heartbeat($this->tempDirectory() . '/data/heartbeat.json');
        self::assertNull($heartbeat->read());

        $heartbeat->record(Heartbeat::WEB, "idle\nnothing", 1000);

        self::assertSame(['time' => 1000, 'via' => 'web', 'note' => 'idle nothing'], $heartbeat->read());
        $heartbeat->record(Heartbeat::CLI, 'backed up "shop"', 2000);
        self::assertSame(['time' => 2000, 'via' => 'cli', 'note' => 'backed up "shop"'], $heartbeat->read());
    }

    public function testADamagedHeartbeatReadsAsNone(): void
    {
        $file = $this->tempDirectory() . '/heartbeat.json';
        $heartbeat = new Heartbeat($file);

        foreach (['not json', '[]', '{"time": "now", "via": "cli"}', '{"time": 5, "via": "carrier pigeon"}'] as $content) {
            file_put_contents($file, $content);
            self::assertNull($heartbeat->read(), $content);
        }
    }
}
