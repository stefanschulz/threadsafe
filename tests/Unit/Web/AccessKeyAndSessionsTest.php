<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Web;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Web\AccessKey;
use ThreadSafe\Web\Sessions;

/**
 * The key file with its throttling, and the session files.
 */
final class AccessKeyAndSessionsTest extends TestCase
{
    use TempDirectory;

    private string $directory;

    private int $now = 1_800_000_000;

    protected function setUp(): void
    {
        $this->directory = $this->tempDirectory();
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    private function key(string $content = "<?php return 'correct horse battery';"): AccessKey
    {
        file_put_contents($this->directory . '/restore-key.php', $content);

        return new AccessKey($this->directory . '/restore-key.php', $this->directory . '/attempts.json', $this->directory . '/access.log', fn (): int => $this->now);
    }

    public function testWithoutAFileThereIsNoKey(): void
    {
        $key = new AccessKey($this->directory . '/restore-key.php', $this->directory . '/a.json', $this->directory . '/l.log');

        self::assertFalse($key->exists());
    }

    public function testTheRightKeyIsAccepted(): void
    {
        $key = $this->key();

        self::assertTrue($key->exists());
        self::assertNull($key->problem());
        self::assertNull($key->check('correct horse battery', '203.0.113.9'));
    }

    public function testAFileThatPrintsOrReturnsNothingUsefulIsAProblem(): void
    {
        self::assertStringContainsString('does not return a text', (string) $this->key('<?php echo "hello";')->problem());
        self::assertStringContainsString('does not return a text', (string) $this->key('<?php return 12345678;')->problem());
        self::assertStringContainsString('too short', (string) $this->key("<?php return 'abc';")->problem());
    }

    public function testTheKeyFileIsPhpSoItPrintsNothingWhenFetched(): void
    {
        ob_start();
        $key = $this->key("<?php echo 'LEAK'; return 'correct horse battery';");
        $key->check('x', '1.2.3.4');
        $printed = ob_get_clean();

        self::assertSame('', $printed);
    }

    public function testWrongKeysAreSlowedDownAndLogged(): void
    {
        $key = $this->key();
        for ($i = 0; $i < 3; ++$i) {
            self::assertSame('This is not the key.', $key->check('wrong', '203.0.113.9'));
        }

        $message = $key->check('correct horse battery', '203.0.113.9');
        self::assertStringContainsString('Wait 5 seconds', (string) $message, 'even the right key waits: there is no oracle');

        $this->now += 6;
        self::assertSame('This is not the key.', $key->check('wrong', '203.0.113.9'));
        self::assertSame(10, $key->waitSeconds(), 'the wait doubles');

        $this->now += 11;
        self::assertNull($key->check('correct horse battery', '203.0.113.9'));
        self::assertSame(0, $key->waitSeconds(), 'a right key clears the failures');
        self::assertSame(4, substr_count((string) file_get_contents($this->directory . '/access.log'), 'wrong key from 203.0.113.9'));
    }

    public function testOldFailuresAreForgotten(): void
    {
        $key = $this->key();
        for ($i = 0; $i < 3; ++$i) {
            $key->check('wrong', '1.2.3.4');
        }
        $this->now += 901;

        self::assertSame(0, $key->waitSeconds());
    }

    public function testSessionsCarryTheirOwnTokenAndExpire(): void
    {
        $sessions = new Sessions($this->directory . '/sessions', fn (): int => $this->now);

        [$token, $data] = $sessions->create();
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        self::assertNotEmpty($data['csrf']);
        self::assertSame([hash('sha256', $token) . '.json'], array_map('basename', glob($this->directory . '/sessions/*.json') ?: []), 'the file name is not the token');
        self::assertSame(0600, fileperms($this->directory . '/sessions/' . hash('sha256', $token) . '.json') & 0777);

        $loaded = $sessions->load($token);
        self::assertSame($data['csrf'], $loaded['csrf'] ?? null);

        $this->now += Sessions::IDLE + 1;
        self::assertNull($sessions->load($token));
    }

    public function testBadTokensFindNothing(): void
    {
        $sessions = new Sessions($this->directory . '/sessions', fn (): int => $this->now);
        $sessions->create();

        foreach ([null, '', 'abc', str_repeat('g', 64), '../../etc/passwd', str_repeat('0', 64)] as $token) {
            self::assertNull($sessions->load($token));
        }
    }

    public function testASessionCanBeEndedAndKeepsWhatWasSaved(): void
    {
        $sessions = new Sessions($this->directory . '/sessions', fn (): int => $this->now);
        [$token, $data] = $sessions->create();
        $data['archive'] = '/x/y.zip';
        $sessions->save($token, $data);

        self::assertSame('/x/y.zip', $sessions->load($token)['archive'] ?? null);
        $sessions->destroy($token);
        self::assertNull($sessions->load($token));
    }
}
