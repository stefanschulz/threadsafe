<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Cli\Application;
use ThreadSafe\Cli\BufferedOutput;
use ThreadSafe\Cli\KeysCommand;
use ThreadSafe\Crypto\AgeKeys;
use ThreadSafe\Crypto\AgeSealer;
use ThreadSafe\Crypto\Passphrases;
use ThreadSafe\Crypto\ScryptRecipient;
use ThreadSafe\Crypto\X25519Recipient;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * `threadsafe keys`: a key pair and a passphrase made and shown once, and the check that a key opens a file.
 */
final class KeysCommandTest extends TestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        putenv('THREADSAFE_PASSPHRASE');
        $this->cleanUpTempDirectories();
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string, string}
     */
    private function cli(array $arguments): array
    {
        $output = new BufferedOutput();
        $code = (new Application([new KeysCommand()]))->run(['threadsafe', 'keys', ...$arguments], $output);

        return [$code, $output->stdout(), $output->stderr()];
    }

    public function testAKeyPairIsMadeAndShownAsTheFileOfAgeKeygen(): void
    {
        [$code, $out] = $this->cli(['generate']);

        self::assertSame(0, $code);
        self::assertMatchesRegularExpression('/^# created: \d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/m', $out);
        self::assertSame(1, preg_match('/^# public key: (age1[a-z0-9]{58})$/m', $out, $public));
        self::assertSame(1, preg_match('/^(AGE-SECRET-KEY-1[A-Z0-9]+)$/m', $out, $secret));
        self::assertSame(($public[1] ?? ''), AgeKeys::publicOf(($secret[1] ?? '')), 'the public key belongs to the secret key');
        self::assertStringContainsString('profile edit <id> --recipient=' . ($public[1] ?? ''), $out);
        self::assertStringContainsString('are lost', $out, 'it says what is lost without the key');
        self::assertStringContainsString('somewhere else', $out);
    }

    public function testTheKeyFileIsWrittenWithModeZeroSixHundredAndNeverOverwritten(): void
    {
        $file = $this->tempDirectory() . '/key.txt';

        [$code, $out] = $this->cli(['generate', '--output=' . $file]);

        self::assertSame(0, $code, $out);
        self::assertStringNotContainsString('AGE-SECRET-KEY', $out, 'the secret key is in the file only');
        $content = (string) file_get_contents($file);
        self::assertCount(1, AgeKeys::secretKeysIn($content));
        self::assertStringContainsString(AgeKeys::publicOf(AgeKeys::secretKeysIn($content)[0]), $out);
        if (DIRECTORY_SEPARATOR === '/') {
            self::assertSame(0600, fileperms($file) & 0777);
        }

        [$code, , $err] = $this->cli(['generate', '--output=' . $file]);
        self::assertSame(2, $code);
        self::assertStringContainsString('never overwritten', $err);
        self::assertSame($content, file_get_contents($file), 'the first key is still there');
    }

    public function testAPassphraseIsMadeInTheShapePromised(): void
    {
        [$code, $out] = $this->cli(['passphrase']);

        self::assertSame(0, $code);
        $first = trim(explode("\n", $out)[0]);
        self::assertTrue(Passphrases::isGenerated($first), $first);
        self::assertMatchesRegularExpression('/^([a-z2-9]{4}-){4}[a-z2-9]{4}$/', $first);
        self::assertDoesNotMatchRegularExpression('/[01ilo]/', $first, 'no look-alikes');
        self::assertStringContainsString('99 bits', $out);

        $file = $this->tempDirectory() . '/phrase.txt';
        [$code, $written] = $this->cli(['passphrase', '--output=' . $file]);
        self::assertSame(0, $code);
        self::assertTrue(Passphrases::isGenerated(trim((string) file_get_contents($file))));
        self::assertStringNotContainsString(trim((string) file_get_contents($file)), $written);
        self::assertNotSame($first, trim((string) file_get_contents($file)));
    }

    public function testCheckSaysWhetherAKeyOpensAFile(): void
    {
        $dir = $this->tempDirectory();
        $pair = AgeKeys::generate();
        $other = AgeKeys::generate();
        file_put_contents($dir . '/plain', random_bytes(100000));
        AgeSealer::encryptFile($dir . '/plain', $dir . '/file.age', [new X25519Recipient($pair['public'])]);
        file_put_contents($dir . '/good.txt', AgeKeys::keyFile($pair['secret'], $pair['public']));
        file_put_contents($dir . '/bad.txt', AgeKeys::keyFile($other['secret'], $other['public']));

        [$code, $out] = $this->cli(['check', $dir . '/file.age', '--identity-file=' . $dir . '/good.txt']);
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('The key opens this file', $out);
        self::assertStringContainsString('2 blocks', $out);

        [$code, , $err] = $this->cli(['check', $dir . '/file.age', '--identity-file=' . $dir . '/bad.txt']);
        self::assertSame(2, $code);
        self::assertStringContainsString('does not open this file', $err);

        file_put_contents($dir . '/notage', 'just text');
        [$code, , $err] = $this->cli(['check', $dir . '/notage', '--identity-file=' . $dir . '/good.txt']);
        self::assertSame(2, $code);
        self::assertStringContainsString('not a file of the age format', $err);
    }

    public function testCheckWithAPassphraseFromAFileOrTheEnvironment(): void
    {
        $dir = $this->tempDirectory();
        file_put_contents($dir . '/plain', 'hello');
        AgeSealer::encryptFile($dir . '/plain', $dir . '/file.age', [new ScryptRecipient('kr7m-xq3d-hp9t-w4nc-2vfe', 10)]);
        file_put_contents($dir . '/phrase.txt', "kr7m-xq3d-hp9t-w4nc-2vfe\n");
        file_put_contents($dir . '/wrong.txt', "kr7m-xq3d-hp9t-w4nc-2vff\n");

        self::assertSame(0, $this->cli(['check', $dir . '/file.age', '--passphrase-file=' . $dir . '/phrase.txt'])[0]);
        self::assertSame(2, $this->cli(['check', $dir . '/file.age', '--passphrase-file=' . $dir . '/wrong.txt'])[0]);

        putenv('THREADSAFE_PASSPHRASE=kr7m-xq3d-hp9t-w4nc-2vfe');
        self::assertSame(0, $this->cli(['check', $dir . '/file.age'])[0], 'the environment variable');
    }

    public function testMistakesAreUsageErrors(): void
    {
        $dir = $this->tempDirectory();
        file_put_contents($dir . '/x.age', 'x');

        self::assertSame(1, $this->cli([])[0]);
        self::assertSame(1, $this->cli(['frobnicate'])[0]);
        self::assertSame(1, $this->cli(['generate', 'extra'])[0]);
        self::assertSame(1, $this->cli(['check'])[0], 'the file is needed');
        self::assertSame(1, $this->cli(['check', $dir . '/nothing.age', '--identity-file=' . $dir . '/x.age'])[0]);
        self::assertSame(1, $this->cli(['check', $dir . '/x.age'])[0], 'no key given');
        self::assertSame(1, $this->cli(['check', $dir . '/x.age', '--identity-file=' . $dir . '/x.age', '--passphrase-file=' . $dir . '/x.age'])[0]);
    }
}
