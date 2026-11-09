<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Web;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Web\AgentPairing;

final class AgentPairingTest extends TestCase
{
    private const string URL = 'https://shop.example/wp-content/plugins/threadsafe-connector/threadsafe-backup.php';

    private static function base64url(string $text): string
    {
        return rtrim(strtr(base64_encode($text), '+/', '-_'), '=');
    }

    public function testAPairingStringSurvivesTheRoundTrip(): void
    {
        $secret = AgentPairing::secret();
        $string = AgentPairing::encode(self::URL, $secret, 'shop');
        $decoded = AgentPairing::decode("  $string\n");

        self::assertStringStartsWith('ts1:', $string);
        self::assertSame(['url' => self::URL, 'secret' => $secret, 'profile' => 'shop', 'fingerprint' => AgentPairing::fingerprint($secret)], $decoded);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $secret);
        self::assertNotSame($secret, AgentPairing::secret());
    }

    public function testTheFingerprintIsNotTheSecretAndIsReadableInGroups(): void
    {
        $secret = str_repeat('ab', 32);

        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/D', AgentPairing::fingerprint($secret));
        self::assertStringNotContainsString(substr($secret, 0, 8), AgentPairing::fingerprint($secret));
        self::assertMatchesRegularExpression('/^[0-9a-f]{4}(-[0-9a-f]{4}){3}$/D', AgentPairing::readable(AgentPairing::fingerprint($secret)));
    }

    /**
     * @return array<string, array{string, string}> the text, a word of the message
     */
    public static function damaged(): array
    {
        $secret = str_repeat('ab', 32);
        $good = ['v' => 1, 'url' => self::URL, 'secret' => $secret, 'profile' => 'shop', 'fp' => AgentPairing::fingerprint($secret)];
        $encode = static fn (array $data): string => 'ts1:' . self::base64url((string) json_encode($data));

        return [
            'no prefix' => ['hello', 'not a pairing string'],
            'a character that does not belong' => ['ts1:abc def', 'damaged'],
            'not base64 of a JSON' => ['ts1:' . self::base64url('not json'), 'cannot be read'],
            'another version' => [$encode([...$good, 'v' => 2]), 'another version'],
            'a fingerprint that does not fit (a character lost in the clipboard)' => [$encode([...$good, 'secret' => str_repeat('ab', 31) . 'ac']), 'fingerprint'],
            'no address' => [$encode([...$good, 'url' => 'shop.example']), 'web address'],
            'an address with a password' => [$encode([...$good, 'url' => 'https://user:pass@shop.example/x.php']), 'web address'],
            'a secret that is too short' => [$encode([...$good, 'secret' => 'abcd']), '64 hexadecimal'],
            'a profile id that is not one' => [$encode([...$good, 'profile' => 'Shop Name']), 'profile id'],
        ];
    }

    #[DataProvider('damaged')]
    public function testAStringThatIsNotAGoodPairingStringIsRefusedWithAReason(string $text, string $word): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($word);

        AgentPairing::decode($text);
    }

    public function testEncodingRefusesWhatDecodingWouldRefuse(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AgentPairing::encode(self::URL, 'short', 'shop');
    }
}
