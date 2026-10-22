<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Crypto;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Crypto\Scrypt;

/**
 * scrypt in PHP against the test vectors of RFC 7914 section 12.
 */
final class ScryptTest extends TestCase
{
    /** What these tests allocate is given back at once: the tests of the budget measure the memory the whole process has taken. */
    protected function tearDown(): void
    {
        gc_collect_cycles();
        gc_mem_caches();
    }

    public function testVectorOne(): void
    {
        self::assertSame(
            '77d6576238657b203b19ca42c18a0497f16b4844e3074ae8dfdffa3fede21442fcd0069ded0948f8326a753a0fc81f17e8d3e0fb2e0d3628cf35e20c38d18906',
            bin2hex(Scrypt::derive('', '', 16, 1, 1, 64)),
        );
    }

    public function testVectorThreeWithTheParametersOfAge(): void
    {
        // N = 2^14, r = 8, p = 1: the shape of a passphrase of the age format
        self::assertSame(
            '7023bdcb3afd7348461c06cd81fd38ebfda8fbba904f8e3ea9b543f6545da1f2d5432955613f0fcf62d49705242a9af9e61e85dc0d651e40dfcf017b45575887',
            bin2hex(Scrypt::derive('pleaseletmein', 'SodiumChloride', 16384, 8, 1, 64)),
        );
    }

    public function testSeveralLanesAreIndependentAndCombined(): void
    {
        // vector two of the RFC has p = 16; here a cheaper run with p = 2 is only compared with itself and the lane count matters
        $one = Scrypt::derive('password', 'NaCl', 16, 2, 1, 32);
        $two = Scrypt::derive('password', 'NaCl', 16, 2, 2, 32);

        self::assertNotSame($one, $two);
        self::assertSame($two, Scrypt::derive('password', 'NaCl', 16, 2, 2, 32), 'deterministic');
        self::assertSame(32, strlen($two));
    }

    public function testTheMemoryEstimateGrowsWithTheWorkFactorAndFitsWhatWasMeasured(): void
    {
        self::assertGreaterThan(Scrypt::memoryFor(14), Scrypt::memoryFor(15));
        self::assertGreaterThan(42_000_000, Scrypt::memoryFor(15), 'the measured peak of 2^15 was 42 MB');
        self::assertLessThan(60_000_000, Scrypt::memoryFor(15));
        self::assertGreaterThan(300_000_000, Scrypt::memoryFor(18), '2^18 measured 328 MB');
    }
}
