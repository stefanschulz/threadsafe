<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Crypto;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Archive\ZipReader;
use ThreadSafe\Crypto\AgeDecryptStream;
use ThreadSafe\Crypto\AgeKeys;
use ThreadSafe\Crypto\AgeReader;
use ThreadSafe\Crypto\AgeSealer;
use ThreadSafe\Crypto\ScryptRecipient;
use ThreadSafe\Crypto\X25519Identity;
use ThreadSafe\Crypto\X25519Recipient;
use ThreadSafe\Tests\Support\RawZip;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * The real `age` tool as the oracle, in both directions (it is in the tools image; where it is not installed these tests are skipped):
 * the keys we make are its keys, the files we make it opens, and the files it makes we open - at every awkward size, with the
 * passphrase of the format, and as a ZIP part read through the stream without a decrypted copy.
 */
final class AgeOracleTest extends TestCase
{
    use TempDirectory;

    protected function setUp(): void
    {
        if (DIRECTORY_SEPARATOR !== '/' || self::shell('command -v age && command -v age-keygen')[0] !== 0) {
            self::markTestSkipped('the age tool is not installed');
        }
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    /** @return array{int, string} */
    private static function shell(string $command): array
    {
        $lines = [];
        exec($command . ' 2>&1', $lines, $code);

        return [$code, implode("\n", $lines)];
    }

    public function testTheKeysAreTheKeysOfTheRealTool(): void
    {
        $dir = $this->tempDirectory();
        self::assertSame(0, self::shell('age-keygen -o ' . escapeshellarg($dir . '/theirs.txt'))[0]);
        $file = (string) file_get_contents($dir . '/theirs.txt');
        self::assertSame(1, preg_match('/public key: (age1[a-z0-9]+)/', $file, $m));
        $secret = AgeKeys::secretKeysIn($file)[0];

        self::assertSame(($m[1] ?? ''), AgeKeys::publicOf($secret), 'the public key of a secret key that age-keygen made');
        self::assertTrue(AgeKeys::isRecipient(($m[1] ?? '')));

        $ours = AgeKeys::generate();
        file_put_contents($dir . '/ours.txt', AgeKeys::keyFile($ours['secret'], $ours['public']));
        [$code, $out] = self::shell('age-keygen -y ' . escapeshellarg($dir . '/ours.txt'));
        self::assertSame(0, $code, $out);
        self::assertSame($ours['public'], trim($out), 'age-keygen reads our key file and finds the same public key');
        self::assertSame([$ours['secret']], AgeKeys::secretKeysIn(AgeKeys::keyFile($ours['secret'], $ours['public'])));
    }

    public function testFilesOfEverySizeOpenInBothDirections(): void
    {
        $dir = $this->tempDirectory();
        self::assertSame(0, self::shell('age-keygen -o ' . escapeshellarg($dir . '/theirs.txt'))[0]);
        $theirs = (string) file_get_contents($dir . '/theirs.txt');
        self::assertSame(1, preg_match('/public key: (age1[a-z0-9]+)/', $theirs, $m));
        $theirPublic = ($m[1] ?? '');
        $ours = AgeKeys::generate();
        file_put_contents($dir . '/ours.txt', AgeKeys::keyFile($ours['secret'], $ours['public']));

        foreach ([0, 1, 15, 16, 17, 65535, 65536, 65537, 131072, 131073, 196608, 200001] as $size) {
            $plain = $size === 0 ? '' : random_bytes($size);
            file_put_contents($dir . '/plain.bin', $plain);

            // we make it for two recipients, age opens it with either key
            @unlink($dir . '/ours.age');
            AgeSealer::encryptFile($dir . '/plain.bin', $dir . '/ours.age', [new X25519Recipient($ours['public']), new X25519Recipient($theirPublic)]);
            foreach (['ours.txt', 'theirs.txt'] as $key) {
                @unlink($dir . '/back.bin');
                [$code, $out] = self::shell('age -d -i ' . escapeshellarg($dir . '/' . $key) . ' -o ' . escapeshellarg($dir . '/back.bin') . ' ' . escapeshellarg($dir . '/ours.age'));
                self::assertSame(0, $code, "size $size, key $key: $out");
                self::assertSame($plain, file_get_contents($dir . '/back.bin'), "size $size, key $key");
            }

            // age makes it, we open it (the plain size is known from the file alone)
            @unlink($dir . '/theirs.age');
            [$code, $out] = self::shell('age -r ' . escapeshellarg($ours['public']) . ' -o ' . escapeshellarg($dir . '/theirs.age') . ' ' . escapeshellarg($dir . '/plain.bin'));
            self::assertSame(0, $code, $out);
            $handle = fopen($dir . '/theirs.age', 'rb');
            self::assertNotFalse($handle);
            $reader = AgeReader::open($handle, [new X25519Identity($ours['secret'])]);
            $back = '';
            $reader->each(static function (string $part) use (&$back): void {
                $back .= $part;
            });
            fclose($handle);
            self::assertSame($plain, $back, "size $size, made by age");
            self::assertSame($size, $reader->size(), "size $size");
            self::assertSame((int) filesize($dir . '/theirs.age'), AgeSealer::sealedSize($size, (int) filesize($dir . '/theirs.age') - $size - 16 * $reader->chunkCount()), 'the formula for the sealed size agrees with the real file');
        }
    }

    public function testAPassphraseFileOfOursOpensWithTheRealTool(): void
    {
        if (self::shell('command -v script')[0] !== 0) {
            self::markTestSkipped('script (a pseudo terminal for age) is not installed');
        }
        $dir = $this->tempDirectory();
        $plain = random_bytes(150000);
        file_put_contents($dir . '/plain.bin', $plain);
        $passphrase = 'kr7m-xq3d-hp9t-w4nc-2vfe';
        AgeSealer::encryptFile($dir . '/plain.bin', $dir . '/ours.age', [new ScryptRecipient($passphrase, 10)]);
        file_put_contents($dir . '/typed.txt', $passphrase . "\n");

        self::shell('script -qec ' . escapeshellarg('age -d -o ' . escapeshellarg($dir . '/back.bin') . ' ' . escapeshellarg($dir . '/ours.age')) . ' /dev/null < ' . escapeshellarg($dir . '/typed.txt'));

        self::assertFileExists($dir . '/back.bin', 'age did not open the file');
        self::assertSame($plain, file_get_contents($dir . '/back.bin'));
    }

    public function testAZipPartThatTheRealToolSealedIsReadThroughTheStream(): void
    {
        $dir = $this->tempDirectory();
        $ours = AgeKeys::generate();
        $zip = RawZip::build([['name' => 'files/a.bin', 'data' => random_bytes(300000), 'method' => 0], ['name' => 'db/0001_x.sql', 'data' => str_repeat("INSERT INTO x VALUES (1);\n", 8000)], ['name' => 'threadsafe.json', 'data' => '{}']]);
        file_put_contents($dir . '/part.zip', $zip);
        [$code, $out] = self::shell('age -r ' . escapeshellarg($ours['public']) . ' -o ' . escapeshellarg($dir . '/part.zip.age') . ' ' . escapeshellarg($dir . '/part.zip'));
        self::assertSame(0, $code, $out);

        $url = AgeDecryptStream::url($dir . '/part.zip.age', [new X25519Identity($ours['secret'])]);
        try {
            $reader = ZipReader::open($url);
            self::assertSame(3, $reader->count());
            self::assertTrue($reader->verify()->ok());
            self::assertSame('{}', $reader->contents($reader->find('threadsafe.json') ?? throw new \LogicException()));
        } finally {
            AgeDecryptStream::release($url);
        }
    }
}
