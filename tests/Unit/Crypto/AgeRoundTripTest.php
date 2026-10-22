<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Crypto;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Archive\ZipReader;
use ThreadSafe\Crypto\AgeDecryptStream;
use ThreadSafe\Crypto\AgeKeys;
use ThreadSafe\Crypto\AgeReader;
use ThreadSafe\Crypto\AgeSealer;
use ThreadSafe\Crypto\CryptoException;
use ThreadSafe\Crypto\PassphraseIdentity;
use ThreadSafe\Crypto\ScryptRecipient;
use ThreadSafe\Crypto\X25519Identity;
use ThreadSafe\Crypto\X25519Recipient;
use ThreadSafe\Tests\Support\RawZip;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * What the sealer writes the reader reads, at every awkward size; and nothing that was changed, cut or extended opens. The real `age`
 * tool is the other side of these files in AgeOracleTest.
 */
final class AgeRoundTripTest extends TestCase
{
    use TempDirectory;

    /** @var array{secret: string, public: string} */
    private array $pair;

    /** @var array{secret: string, public: string} */
    private array $other;

    protected function setUp(): void
    {
        $this->pair = AgeKeys::generate();
        $this->other = AgeKeys::generate();
    }

    protected function tearDown(): void
    {
        AgeDecryptStream::release('agedec://none');
        $this->cleanUpTempDirectories();
        gc_collect_cycles();
        gc_mem_caches();   // the tests of the budget measure the memory the whole process has taken
    }

    /**
     * @param list<\ThreadSafe\Crypto\Recipient>|null $recipients
     */
    private function seal(string $plain, ?array $recipients = null): string
    {
        $sealer = AgeSealer::begin($recipients ?? [new X25519Recipient($this->pair['public'])]);
        $in = fopen('php://memory', 'w+b');
        $out = fopen('php://memory', 'w+b');
        self::assertNotFalse($in);
        self::assertNotFalse($out);
        fwrite($in, $plain);
        rewind($in);
        $sealer->sealStream($in, $out);
        rewind($out);

        return (string) stream_get_contents($out);
    }

    /** @param list<\ThreadSafe\Crypto\Identity>|null $identities */
    private function open(string $sealed, ?array $identities = null): AgeReader
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertNotFalse($stream);
        fwrite($stream, $sealed);

        return AgeReader::open($stream, $identities ?? [new X25519Identity($this->pair['secret'])]);
    }

    private function readAll(AgeReader $reader): string
    {
        $out = '';
        $reader->each(static function (string $plain) use (&$out): void {
            $out .= $plain;
        });

        return $out;
    }

    /** @return iterable<string, array{int}> */
    public static function sizes(): iterable
    {
        foreach ([0, 1, 15, 16, 17, 65535, 65536, 65537, 131071, 131072, 131073, 196608, 200001] as $size) {
            yield "$size bytes" => [$size];
        }
    }

    #[DataProvider('sizes')]
    public function testEverySizeSurvivesAndTheSizeIsKnownFromTheSealedFile(int $size): void
    {
        $plain = $size <= 0 ? '' : random_bytes($size);

        $sealed = $this->seal($plain);
        $reader = $this->open($sealed);

        self::assertSame($plain, $this->readAll($reader));
        self::assertSame($size, $reader->size());
        self::assertSame(max(1, intdiv($size + 65535, 65536)), $reader->chunkCount());
        self::assertSame(strlen($sealed), AgeSealer::sealedSize($size, strlen($sealed) - $size - 16 * $reader->chunkCount()));
    }

    public function testAnyOfSeveralRecipientsOpensTheFile(): void
    {
        $third = AgeKeys::generate();
        $sealed = $this->seal('shared', [new X25519Recipient($this->pair['public']), new X25519Recipient($this->other['public']), new X25519Recipient($third['public'])]);

        foreach ([$this->pair, $this->other, $third] as $pair) {
            self::assertSame('shared', $this->readAll($this->open($sealed, [new X25519Identity($pair['secret'])])));
        }
    }

    public function testAWrongKeyOrPassphraseDoesNotOpenTheFile(): void
    {
        $sealed = $this->seal('secret');
        try {
            $this->open($sealed, [new X25519Identity($this->other['secret'])]);
            self::fail('a wrong key must not open the file');
        } catch (CryptoException $e) {
            self::assertSame(CryptoException::NO_MATCH, $e->getCode());
        }

        $withPassphrase = $this->seal('secret', [new ScryptRecipient('correct horse battery staple', 10)]);
        self::assertSame('secret', $this->readAll($this->open($withPassphrase, [new PassphraseIdentity('correct horse battery staple')])));
        try {
            $this->open($withPassphrase, [new PassphraseIdentity('wrong horse battery staple')]);
            self::fail('a wrong passphrase must not open the file');
        } catch (CryptoException $e) {
            self::assertSame(CryptoException::NO_MATCH, $e->getCode());
        }
        try {
            $this->open($withPassphrase);
            self::fail('a key does not open a passphrase file');
        } catch (CryptoException $e) {
            self::assertSame(CryptoException::NO_MATCH, $e->getCode());
        }
    }

    public function testAPassphraseCannotBeCombinedWithKeys(): void
    {
        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('cannot be combined');
        AgeSealer::begin([new ScryptRecipient('passphrase long enough', 10), new X25519Recipient($this->pair['public'])]);
    }

    public function testAFileNeedsARecipient(): void
    {
        $this->expectException(CryptoException::class);
        AgeSealer::begin([]);
    }

    /** @return iterable<string, array{string}> */
    public static function damage(): iterable
    {
        yield 'a bit in the header' => ['header'];
        yield 'a bit in the checksum of the header' => ['mac'];
        yield 'a bit in the nonce' => ['nonce'];
        yield 'a bit in the first block' => ['first'];
        yield 'a bit in the last block' => ['last'];
        yield 'a bit in the tag of the last block' => ['tag'];
        yield 'cut after a whole block' => ['cut-block'];
        yield 'cut inside a block' => ['cut-inside'];
        yield 'cut inside the tag' => ['cut-tag'];
        yield 'extended by a byte' => ['extend'];
        yield 'extended by a whole block' => ['extend-block'];
        yield 'cut to the header' => ['cut-header'];
    }

    #[DataProvider('damage')]
    public function testNothingThatWasChangedCutOrExtendedOpens(string $kind): void
    {
        $plain = random_bytes(3 * 65536 + 100);   // three full blocks and a short last one
        $sealed = $this->seal($plain);
        $headLength = strlen($sealed) - strlen($plain) - 16 * 4 - 16;
        $flip = static function (string $data, int $at): string {
            $data[$at] = chr(ord($data[$at]) ^ 1);

            return $data;
        };
        $damaged = match ($kind) {
            'header' => $flip($sealed, 60),
            'mac' => $flip($sealed, $headLength - 6),
            'nonce' => $flip($sealed, $headLength + 3),
            'first' => $flip($sealed, $headLength + 16 + 10),
            'last' => $flip($sealed, strlen($sealed) - 40),
            'tag' => $flip($sealed, strlen($sealed) - 3),
            'cut-block' => substr($sealed, 0, $headLength + 16 + 3 * 65552),
            'cut-inside' => substr($sealed, 0, strlen($sealed) - 60),
            'cut-tag' => substr($sealed, 0, strlen($sealed) - 105),
            'extend' => $sealed . 'x',
            'extend-block' => $sealed . random_bytes(65552),
            'cut-header' => substr($sealed, 0, 80),
            default => throw new \LogicException($kind),
        };

        try {
            $reader = $this->open($damaged);
            $this->readAll($reader);
            self::fail("$kind must not open");
        } catch (CryptoException $e) {
            // (a bit in the share of a key line makes that line a stranger to the key: "does not open this file")
            self::assertContains($e->getCode(), [CryptoException::DAMAGED, CryptoException::MALFORMED, CryptoException::NO_MATCH], $e->getMessage());
        }
    }

    public function testSealingCanStopAndGoOnFromTheSavedState(): void
    {
        $plain = random_bytes(5 * 65536 + 7);
        $first = AgeSealer::begin([new X25519Recipient($this->pair['public'])]);
        $out = $first->head();
        $chunks = str_split($plain, 65536);
        $last = count($chunks) - 1;
        $saved = null;
        $sealer = $first;
        foreach ($chunks as $index => $chunk) {
            if ($index === 2) {
                // the process "ends" here: only the state survives (it goes through JSON, as a run's state does)
                $saved = json_decode((string) json_encode($first->state()), true);
                $sealer = AgeSealer::resume($saved);
            }
            $out .= $sealer->seal($chunk, $index, $index === $last);
        }

        self::assertSame($plain, $this->readAll($this->open($out)));
        self::assertNotNull($saved);
        self::assertSame(['fileKey', 'nonce', 'header'], array_keys($saved));
    }

    public function testASavedStateThatIsNotValidIsRefused(): void
    {
        $this->expectException(CryptoException::class);
        AgeSealer::resume(['fileKey' => 'zz', 'nonce' => str_repeat('00', 16), 'header' => base64_encode('nothing')]);
    }

    public function testFilesAreEncryptedAndDecryptedBySize(): void
    {
        $dir = $this->tempDirectory();
        $plain = random_bytes(150000);
        file_put_contents($dir . '/plain.bin', $plain);

        AgeSealer::encryptFile($dir . '/plain.bin', $dir . '/sealed.age', [new X25519Recipient($this->pair['public'])]);
        AgeReader::decryptFile($dir . '/sealed.age', $dir . '/back.bin', [new X25519Identity($this->pair['secret'])]);

        self::assertSame($plain, file_get_contents($dir . '/back.bin'));
        self::assertSame(AgeSealer::sealedSize(150000, (int) filesize($dir . '/sealed.age') - 150000 - 16 * 3), filesize($dir . '/sealed.age'));
        if (DIRECTORY_SEPARATOR === '/') {
            self::assertSame(0600, fileperms($dir . '/sealed.age') & 0777);
        }
        try {
            AgeSealer::encryptFile($dir . '/plain.bin', $dir . '/sealed.age', [new X25519Recipient($this->pair['public'])]);
            self::fail('an existing file is not overwritten');
        } catch (CryptoException $e) {
            self::assertStringContainsString('must not exist', $e->getMessage());
        }
    }

    // ------------------------------------------------------------------------------------------- the stream

    public function testTheStreamReadsLikeThePlainFileAtAnyPosition(): void
    {
        $dir = $this->tempDirectory();
        $plain = random_bytes(5 * 65536 + 1234);
        file_put_contents($dir . '/sealed.age', $this->seal($plain));
        $url = AgeDecryptStream::url($dir . '/sealed.age', [new X25519Identity($this->pair['secret'])]);
        try {
            $stream = fopen($url, 'rb');
            self::assertNotFalse($stream);
            self::assertSame(strlen($plain), fstat($stream)['size'] ?? -1);

            self::assertSame($plain, stream_get_contents($stream), 'sequential');
            foreach ([0, 1, 65535, 65536, 65537, 3 * 65536 - 1, 5 * 65536, strlen($plain) - 1] as $offset) {
                fseek($stream, $offset);
                self::assertSame($offset, ftell($stream));
                self::assertSame(substr($plain, $offset, 5000), (string) stream_get_contents($stream, 5000), "at $offset");
            }
            fseek($stream, -10, SEEK_END);
            self::assertSame(substr($plain, -10), (string) stream_get_contents($stream));
            self::assertTrue(feof($stream));
            fseek($stream, 70000);
            fseek($stream, 100, SEEK_CUR);
            self::assertSame(substr($plain, 70100, 20), (string) fread($stream, 20));
            for ($i = 0; $i < 40; ++$i) {
                $offset = random_int(0, strlen($plain) - 1);
                $length = random_int(1, 200000);
                fseek($stream, $offset);
                self::assertSame(substr($plain, $offset, $length), (string) stream_get_contents($stream, $length), "$length bytes at $offset");
            }
            fclose($stream);
        } finally {
            AgeDecryptStream::release($url);
        }
    }

    public function testAZipArchiveIsReadThroughTheStreamWithoutADecryptedCopy(): void
    {
        $dir = $this->tempDirectory();
        $zip = RawZip::build([['name' => 'a.bin', 'data' => random_bytes(200000), 'method' => 0], ['name' => 'dir/b.txt', 'data' => str_repeat('hello ', 20000)], ['name' => 'c.txt', 'data' => 'last']]);
        file_put_contents($dir . '/part.zip.age', $this->seal($zip));
        $before = scandir($dir);
        $url = AgeDecryptStream::url($dir . '/part.zip.age', [new X25519Identity($this->pair['secret'])]);
        try {
            $reader = ZipReader::open($url);

            self::assertSame(3, $reader->count());
            self::assertTrue($reader->verify()->ok());
            self::assertSame('last', $reader->contents($reader->find('c.txt') ?? throw new \LogicException()));
            self::assertSame(str_repeat('hello ', 20000), $reader->contents($reader->find('dir/b.txt') ?? throw new \LogicException()));
            self::assertSame($before, scandir($dir), 'nothing was written next to it');
        } finally {
            AgeDecryptStream::release($url);
        }
    }

    public function testTheStreamRefusesAWrongKeyAndSaysWhy(): void
    {
        $dir = $this->tempDirectory();
        file_put_contents($dir . '/sealed.age', $this->seal('secret'));
        $url = AgeDecryptStream::url($dir . '/sealed.age', [new X25519Identity($this->other['secret'])]);
        try {
            self::assertFalse(@fopen($url, 'rb'));
            self::assertStringContainsString('does not open this file', (string) AgeDecryptStream::lastError());
            self::assertFalse(@fopen($url, 'wb'), 'it can only be read');
        } finally {
            AgeDecryptStream::release($url);
        }
        self::assertFalse(@fopen($url, 'rb'), 'a released address is gone');
    }

    public function testAChangedBlockStopsTheReadAndTheErrorIsKept(): void
    {
        $dir = $this->tempDirectory();
        $sealed = $this->seal(random_bytes(3 * 65536));
        $sealed[strlen($sealed) - 70000] = chr(ord($sealed[strlen($sealed) - 70000]) ^ 1);   // inside the second block
        file_put_contents($dir . '/sealed.age', $sealed);
        $url = AgeDecryptStream::url($dir . '/sealed.age', [new X25519Identity($this->pair['secret'])]);
        try {
            $stream = fopen($url, 'rb');
            self::assertNotFalse($stream);
            $data = (string) @stream_get_contents($stream);

            self::assertSame(65536, strlen($data), 'the first block was good, nothing after the bad one is handed out');
            self::assertStringContainsString('does not match its checksum', (string) AgeDecryptStream::lastError());
            fclose($stream);
        } finally {
            AgeDecryptStream::release($url);
        }
    }

    public function testAPassphraseThatCostsMoreThanThisPhpMayUseIsRefusedWithAClearMessage(): void
    {
        $sealed = $this->seal('x', [new ScryptRecipient('a passphrase that is long enough', 12)]);

        try {
            $this->open($sealed, [new PassphraseIdentity('a passphrase that is long enough', 8_000_000)]);
            self::fail('the memory limit must be respected');
        } catch (CryptoException $e) {
            self::assertSame(CryptoException::TOO_EXPENSIVE, $e->getCode());
            self::assertStringContainsString('work factor 2^12', $e->getMessage());
            self::assertStringContainsString('age -d', $e->getMessage());
        }
        self::assertSame('x', $this->readAll($this->open($sealed, [new PassphraseIdentity('a passphrase that is long enough', 500_000_000)])));
    }
}
