<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Auth\Base32;
use ThreadSafe\Auth\Totp;

/**
 * TOTP against the test vectors of RFC 6238 appendix B (the SHA-1 column), and the rules that are ours: the window of one step on each
 * side, a step accepted only once, what is read as a typed code, the address for the app.
 */
final class TotpTest extends TestCase
{
    /** The secret of the RFC, the ASCII string 12345678901234567890. */
    private const string RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    /** @return iterable<string, array{int, string}> time, the code of 8 digits */
    public static function vectors(): iterable
    {
        yield '59' => [59, '94287082'];
        yield '1111111109' => [1111111109, '07081804'];
        yield '1111111111' => [1111111111, '14050471'];
        yield '1234567890' => [1234567890, '89005924'];
        yield '2000000000' => [2000000000, '69279037'];
        yield '20000000000' => [20000000000, '65353130'];
    }

    #[DataProvider('vectors')]
    public function testTheCodesOfTheRfcAreReproduced(int $time, string $code): void
    {
        self::assertSame('12345678901234567890', Base32::decode(self::RFC_SECRET));
        self::assertSame($code, Totp::code(self::RFC_SECRET, Totp::step($time), 8));
        self::assertSame(substr($code, -6), Totp::code(self::RFC_SECRET, Totp::step($time)), 'the six digits are the last six of the eight');
    }

    public function testAStepIsThirtySeconds(): void
    {
        self::assertSame(1, Totp::step(59));
        self::assertSame(2, Totp::step(60));
        self::assertSame(2, Totp::step(89));
    }

    public function testTheRightCodeIsAcceptedAndTheStepIsReturned(): void
    {
        $time = 1_800_000_000;
        $step = Totp::step($time);

        self::assertSame($step, Totp::verify(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $step), $time));
    }

    public function testOneStepEarlierAndLaterIsAcceptedButNotTwo(): void
    {
        $time = 1_800_000_000;
        $step = Totp::step($time);

        self::assertSame($step - 1, Totp::verify(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $step - 1), $time), 'a code typed slowly');
        self::assertSame($step + 1, Totp::verify(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $step + 1), $time), 'a phone that runs a little fast');
        self::assertNull(Totp::verify(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $step - 2), $time));
        self::assertNull(Totp::verify(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $step + 2), $time));
    }

    public function testAStepIsAcceptedOnlyOnce(): void
    {
        $time = 1_800_000_000;
        $step = Totp::step($time);
        $code = Totp::code(self::RFC_SECRET, $step);

        $first = Totp::verify(self::RFC_SECRET, $code, $time);
        self::assertSame($step, $first);
        self::assertNull(Totp::verify(self::RFC_SECRET, $code, $time, (int) $first), 'the same code again');
        self::assertNull(Totp::verify(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $step - 1), $time, (int) $first), 'an older code after a newer one');
        self::assertSame($step + 1, Totp::verify(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $step + 1), $time, (int) $first), 'the next code is fine');
    }

    public function testAWrongCodeIsRefused(): void
    {
        $time = 1_800_000_000;
        $right = Totp::code(self::RFC_SECRET, Totp::step($time));
        $wrong = str_pad((string) (((int) $right + 1) % 1_000_000), 6, '0', STR_PAD_LEFT);

        self::assertNull(Totp::verify(self::RFC_SECRET, $wrong, $time));
        self::assertNull(Totp::verify(self::RFC_SECRET, Totp::code('JBSWY3DPEHPK3PXP', Totp::step($time)), $time), 'a code of another secret');
    }

    public function testWhatIsTypedIsReadTheWayAnAppShowsIt(): void
    {
        $time = 1_800_000_000;
        $code = Totp::code(self::RFC_SECRET, Totp::step($time));
        $spaced = substr($code, 0, 3) . ' ' . substr($code, 3);

        self::assertNotNull(Totp::verify(self::RFC_SECRET, $spaced, $time), 'the app writes "123 456"');
        self::assertNotNull(Totp::verify(self::RFC_SECRET, substr($code, 0, 3) . '-' . substr($code, 3), $time));
        foreach (['', '12345', $code . '0', 'abcdef', "$code\n"] as $typed) {
            self::assertNull(Totp::verify(self::RFC_SECRET, $typed, $time), var_export($typed, true));
        }
    }

    public function testACodeWithLeadingZerosKeepsThem(): void
    {
        // the vector of 1111111109 ends in 081804 and begins with 0 in its 8 digits
        self::assertSame('07081804', Totp::code(self::RFC_SECRET, Totp::step(1111111109), 8));
        self::assertSame(6, strlen(Totp::code(self::RFC_SECRET, 12345)));
    }

    public function testANewSecretIs32CharactersOfBase32AndDiffersEachTime(): void
    {
        $secret = Totp::newSecret();

        self::assertSame(1, preg_match('/^[A-Z2-7]{32}$/', $secret));
        self::assertSame(20, strlen(Base32::decode($secret)));
        self::assertNotSame($secret, Totp::newSecret());
    }

    public function testTheAddressForTheApp(): void
    {
        $uri = Totp::uri('JBSWY3DPEHPK3PXP', 'admin', 'shop.example.com');

        self::assertSame(
            'otpauth://totp/ThreadSafe%20%28shop.example.com%29:admin?secret=JBSWY3DPEHPK3PXP&issuer=ThreadSafe%20%28shop.example.com%29&algorithm=SHA1&digits=6&period=30',
            $uri,
        );
        self::assertSame('otpauth://totp/ThreadSafe:admin?secret=JBSWY3DPEHPK3PXP&issuer=ThreadSafe&algorithm=SHA1&digits=6&period=30', Totp::uri('JBSWY3DPEHPK3PXP', 'admin'));
        self::assertStringNotContainsString('&amp;', $uri);
    }

    public function testAHostNameCannotBreakTheAddress(): void
    {
        $uri = Totp::uri('JBSWY3DPEHPK3PXP', 'admin', 'evil.example&secret=AAAA#x');

        self::assertSame(1, substr_count($uri, 'secret='), 'the label cannot carry a second secret');
        self::assertStringNotContainsString('#', $uri);
    }
}
