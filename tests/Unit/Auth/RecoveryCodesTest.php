<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Auth\RecoveryCodes;

final class RecoveryCodesTest extends TestCase
{
    public function testTenDifferentCodesInTheWrittenForm(): void
    {
        $codes = RecoveryCodes::generate();

        self::assertCount(RecoveryCodes::COUNT, $codes);
        self::assertCount(10, array_unique($codes));
        foreach ($codes as $code) {
            self::assertSame(1, preg_match('/^[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/', $code), $code);
            self::assertTrue(RecoveryCodes::looksLike($code), $code);
        }
        self::assertNotSame($codes, RecoveryCodes::generate(), 'new codes every time');
    }

    public function testNoCharacterThatLooksLikeAnotherIsUsed(): void
    {
        $all = implode('', RecoveryCodes::generate(400));

        foreach (['0', '1', 'I', 'O'] as $character) {
            self::assertStringNotContainsString($character, $all);
        }
        self::assertGreaterThan(20, count(array_unique(str_split(str_replace('-', '', $all)))), 'and the alphabet is in use');
    }

    public function testTheHashIgnoresCaseHyphensAndSpaces(): void
    {
        $hash = RecoveryCodes::hash('K7QD-2MWX-9RHT');

        self::assertSame(1, preg_match('/^[0-9a-f]{64}$/', $hash));
        self::assertSame($hash, RecoveryCodes::hash('k7qd2mwx9rht'));
        self::assertSame($hash, RecoveryCodes::hash(' K7QD 2MWX 9RHT '));
        self::assertNotSame($hash, RecoveryCodes::hash('K7QD-2MWX-9RHS'));
    }

    public function testASixDigitCodeIsNotARecoveryCode(): void
    {
        foreach (['123456', '123 456', '', 'K7QD-2MWX', 'K7QD-2MWX-9RHT-AAAA', 'K7QD-2MWX-9RH0'] as $typed) {
            self::assertFalse(RecoveryCodes::looksLike($typed), $typed);
        }
        self::assertTrue(RecoveryCodes::looksLike('k7qd 2mwx 9rht'));
    }
}
