<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Crypto;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Crypto\AgeKeys;
use ThreadSafe\Crypto\CryptoException;
use ThreadSafe\Crypto\Passphrases;
use ThreadSafe\Crypto\X25519Identity;

final class AgeKeysTest extends TestCase
{
    public function testAGeneratedPairHangsTogetherAndHasTheShapeOfAgeKeys(): void
    {
        $pair = AgeKeys::generate();

        self::assertMatchesRegularExpression('/^AGE-SECRET-KEY-1[A-Z0-9]{58}$/', $pair['secret']);
        self::assertMatchesRegularExpression('/^age1[a-z0-9]{58}$/', $pair['public']);
        self::assertSame($pair['public'], AgeKeys::publicOf($pair['secret']));
        self::assertSame($pair['public'], (new X25519Identity($pair['secret']))->recipient());
        self::assertTrue(AgeKeys::isRecipient($pair['public']));
        self::assertNotSame($pair['secret'], AgeKeys::generate()['secret']);
    }

    public function testRecipientsThatAreNotRecipientsAreRefused(): void
    {
        $pair = AgeKeys::generate();
        $mistyped = substr($pair['public'], 0, -1) . ($pair['public'][-1] === 'q' ? 'p' : 'q');

        self::assertFalse(AgeKeys::isRecipient($mistyped), 'a character wrong: the checksum says so');
        self::assertFalse(AgeKeys::isRecipient(substr($pair['public'], 0, 40)));
        self::assertFalse(AgeKeys::isRecipient($pair['secret']), 'a secret key is not a recipient');
        self::assertFalse(AgeKeys::isRecipient(''));
        self::assertTrue(AgeKeys::isRecipient('age1ql3z7hjy54pw3hyww5ayyfg7zqgvc7w3j2elw8zmrj2kg5sfn9aqmcac8p'), 'the example key of the age documentation');
    }

    public function testASecretKeyThatIsNotOneIsRefusedWithAnExplanation(): void
    {
        foreach ([AgeKeys::generate()['public'], 'AGE-SECRET-KEY-1QQQQ', 'nonsense', ''] as $text) {
            try {
                AgeKeys::secretBytes($text);
                self::fail("\"$text\" is no secret key");
            } catch (CryptoException $e) {
                self::assertSame(CryptoException::MALFORMED, $e->getCode());
            }
        }
    }

    public function testTheKeyFileIsTheFileOfAgeKeygenAndSeveralKeysAreRead(): void
    {
        $one = AgeKeys::generate();
        $two = AgeKeys::generate();
        $file = AgeKeys::keyFile($one['secret'], $one['public'], new \DateTimeImmutable('2026-10-22 10:00:00', new \DateTimeZone('UTC')));

        self::assertSame("# created: 2026-10-22T10:00:00+00:00\n# public key: {$one['public']}\n{$one['secret']}\n", $file);
        self::assertSame([$one['secret'], $two['secret']], AgeKeys::secretKeysIn($file . "\n\n# a second key\r\n" . $two['secret'] . "\r\n"));

        $this->expectException(CryptoException::class);
        AgeKeys::secretKeysIn("# only comments\n");
    }

    public function testAKeyFileWithSomethingElseInItIsRefused(): void
    {
        $this->expectException(CryptoException::class);
        AgeKeys::secretKeysIn(AgeKeys::generate()['secret'] . "\nsomething else\n");
    }

    public function testGeneratedPassphrasesHaveTheShapeAndAreNotTheSameTwice(): void
    {
        $seen = [];
        for ($i = 0; $i < 50; ++$i) {
            $passphrase = Passphrases::generate();
            self::assertMatchesRegularExpression('/^([a-hjkmnp-z2-9]{4}-){4}[a-hjkmnp-z2-9]{4}$/', $passphrase);
            self::assertTrue(Passphrases::isGenerated($passphrase));
            self::assertNull(Passphrases::weakness($passphrase));
            $seen[$passphrase] = true;
        }
        self::assertCount(50, $seen);
    }

    public function testATypedPassphraseIsJudgedByItsLength(): void
    {
        self::assertStringContainsString('short', (string) Passphrases::weakness('hunter2'));
        self::assertNull(Passphrases::weakness('a long passphrase that a person chose'));
        self::assertFalse(Passphrases::isGenerated('kr7m-xq3d-hp9t-w4nc-2vf0'), 'a 0 is not in the alphabet');
    }
}
