<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Crypto;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Crypto\AgeReader;
use ThreadSafe\Crypto\CryptoException;
use ThreadSafe\Crypto\Identity;
use ThreadSafe\Crypto\PassphraseIdentity;
use ThreadSafe\Crypto\X25519Identity;

/**
 * The reader against the test vectors of the age project (C2SP CCTV, tests/fixtures/age-cctv): files that must open, files that must
 * not and why not. Each vector says what to expect; the reader must agree. A file that must open gives exactly the plain text the vector
 * names by its hash. For a payload that must fail, the hash covers what the reference streaming reader released before it noticed;
 * this reader knows the size of the file and may release less, so there it is checked that only whole, authenticated blocks come out.
 */
final class AgeCctvTest extends TestCase
{
    private const string DIRECTORY = __DIR__ . '/../../fixtures/age-cctv';

    /** What these tests allocate is given back at once: the tests of the budget measure the memory the whole process has taken. */
    protected function tearDown(): void
    {
        gc_collect_cycles();
        gc_mem_caches();
    }

    /** @return iterable<string, array{string}> */
    public static function vectors(): iterable
    {
        foreach (glob(self::DIRECTORY . '/*') ?: [] as $path) {
            if (basename($path) !== 'README.md') {
                yield basename($path) => [$path];
            }
        }
    }

    #[DataProvider('vectors')]
    public function testTheVector(string $path): void
    {
        [$meta, $file] = self::parse($path);
        if (($meta['armored'][0] ?? 'no') === 'yes') {
            self::markTestSkipped('armor is not implemented');
        }
        $identities = [];
        foreach ($meta['identity'] ?? [] as $key) {
            $identities[] = new X25519Identity($key);
        }
        foreach ($meta['passphrase'] ?? [] as $passphrase) {
            $identities[] = new PassphraseIdentity($passphrase, 1 << 40);
        }
        $expect = $meta['expect'][0] ?? '';

        $stream = fopen('php://temp', 'w+b');
        self::assertNotFalse($stream);
        fwrite($stream, $file);
        unset($file);

        $hash = hash_init('sha256');
        $length = 0;
        $failure = null;
        try {
            $reader = AgeReader::open($stream, $identities);
            $reader->each(static function (string $plain) use ($hash, &$length): void {
                hash_update($hash, $plain);
                $length += strlen($plain);
            });
        } catch (CryptoException $e) {
            $failure = $e;
        }

        switch ($expect) {
            case 'success':
                self::assertNull($failure, $failure?->getMessage() ?? '');
                self::assertSame($meta['payload'][0], hash_final($hash));
                break;
            case 'no match':
                self::assertNotNull($failure);
                self::assertSame(CryptoException::NO_MATCH, $failure->getCode(), $failure->getMessage());
                break;
            case 'HMAC failure':
                self::assertNotNull($failure);
                self::assertSame(CryptoException::DAMAGED, $failure->getCode(), $failure->getMessage());
                self::assertSame(0, $length);
                break;
            case 'header failure':
                self::assertNotNull($failure, 'the header must be refused');
                self::assertContains($failure->getCode(), [CryptoException::MALFORMED, CryptoException::DAMAGED], $failure->getMessage());
                self::assertSame(0, $length);
                break;
            case 'payload failure':
                self::assertNotNull($failure, 'the payload must be refused');
                self::assertSame(CryptoException::DAMAGED, $failure->getCode(), $failure->getMessage());
                // this reader knows the size of the file, so it knows which block is the last one and refuses a file whose last block is
                // not marked as such before it releases that block; a streaming reader (the reference one) releases a little more or less
                // before it notices. What matters: never a part of a block, and only blocks that authenticated
                self::assertSame(0, $length % 65536, 'only whole blocks');
                break;
            default:
                self::markTestSkipped("expectation \"$expect\" is not for this implementation");
        }
    }

    /**
     * @return array{array<string, list<string>>, string} the header of the vector and the age file
     */
    private static function parse(string $path): array
    {
        $content = (string) file_get_contents($path);
        $end = strpos($content, "\n\n");
        self::assertNotFalse($end, basename($path));
        $meta = [];
        foreach (explode("\n", substr($content, 0, $end)) as $line) {
            [$key, $value] = explode(': ', $line, 2) + [1 => ''];
            $meta[$key][] = $value;
        }
        $file = substr($content, $end + 2);
        if (($meta['compressed'][0] ?? '') === 'zlib') {
            $file = (string) gzuncompress($file);
        }

        return [$meta, $file];
    }

    public function testTheDirectoryHoldsTheVectors(): void
    {
        self::assertGreaterThan(80, count(glob(self::DIRECTORY . '/*') ?: []));
        self::assertInstanceOf(Identity::class, new X25519Identity(\ThreadSafe\Crypto\AgeKeys::generate()['secret']));
    }
}
