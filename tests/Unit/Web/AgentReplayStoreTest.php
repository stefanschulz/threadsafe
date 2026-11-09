<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Web;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Web\AgentReplayStore;

final class AgentReplayStoreTest extends TestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    public function testANewNonceIsAcceptedOnceAndARepeatIsNot(): void
    {
        $store = new AgentReplayStore($this->tempDirectory() . '/replays');

        self::assertTrue($store->remember('nonce-one', 1000));
        self::assertFalse($store->remember('nonce-one', 1001));
        self::assertTrue($store->remember('nonce-two', 1002), 'another nonce is new');
    }

    public function testANonceIsForgottenWhenItsRequestCouldNotBeAcceptedAnyMore(): void
    {
        $store = new AgentReplayStore($this->tempDirectory() . '/replays');
        $store->remember('nonce-one', 1000);

        self::assertFalse($store->remember('nonce-one', 1000 + 2 * 120));
        self::assertTrue($store->remember('nonce-one', 1000 + 2 * 120 + 11), 'older than two windows: a request that old is refused by its time');
    }

    public function testTheFileIsPrivateAndDamagedLinesAreIgnored(): void
    {
        $file = $this->tempDirectory() . '/replays';
        file_put_contents($file, "garbage\n\n12 \nnot-a-number nonce-x\n");
        $store = new AgentReplayStore($file);

        self::assertTrue($store->remember('nonce-one', 1000));
        self::assertStringNotContainsString('garbage', (string) file_get_contents($file));
        if (DIRECTORY_SEPARATOR === '/') {
            self::assertSame(0600, fileperms($file) & 0777);
        }
    }

    public function testAnUnusableFileMeansNothingIsAccepted(): void
    {
        $directory = $this->tempDirectory();
        mkdir($directory . '/replays');   // a directory where the file should be

        self::assertFalse((new AgentReplayStore($directory . '/replays'))->remember('nonce-one', 1000), 'an agent that cannot tell a repeat from a new request must not answer');
    }

    public function testTheDirectoryIsMadeWhenItIsMissing(): void
    {
        $store = new AgentReplayStore($this->tempDirectory() . '/data/nested/replays');

        self::assertTrue($store->remember('nonce-one', 1000));
    }
}
