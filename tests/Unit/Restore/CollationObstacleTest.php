<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Restore;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Database\ServerInfo;
use ThreadSafe\Restore\Preflight;

/**
 * Which collation a database server can hold (field test: a backup with `utf8mb4_uca1400_ai_ci` was refused on MariaDB 11.4.13, which has it;
 * the rule said 11.5, the default of that version, instead of 11.4, the version that brought the collations).
 */
final class CollationObstacleTest extends TestCase
{
    /** @return iterable<string, array{string, string, bool}> */
    public static function cases(): iterable
    {
        yield 'uca1400 on MariaDB 10.11' => ['utf8mb4_uca1400_ai_ci', '10.11.18-MariaDB', true];
        yield 'uca1400 on MariaDB 11.3' => ['utf8mb4_uca1400_ai_ci', '11.3.2-MariaDB', true];
        yield 'uca1400 on MariaDB 11.4' => ['utf8mb4_uca1400_ai_ci', '11.4.13-MariaDB-ubu2404', false];
        yield 'uca1400 on MariaDB 11.8' => ['utf8mb4_uca1400_ai_ci', '11.8.8-MariaDB', false];
        yield 'uca1400 on MySQL 8.4' => ['utf8mb4_uca1400_ai_ci', '8.4.2', true];
        yield '0900 on MariaDB 11.8' => ['utf8mb4_0900_ai_ci', '11.8.8-MariaDB', true];
        yield '0900 on MySQL 8.4' => ['utf8mb4_0900_ai_ci', '8.4.2', false];
        yield 'general_ci on MariaDB 10.11' => ['utf8mb4_general_ci', '10.11.18-MariaDB', false];
        yield 'unicode_520_ci on MySQL 8.4' => ['utf8mb4_unicode_520_ci', '8.4.2', false];
    }

    #[DataProvider('cases')]
    public function testTheRuleForACollation(string $collation, string $version, bool $refused): void
    {
        $obstacle = Preflight::collationObstacle($collation, ServerInfo::parse($version));

        if ($refused) {
            self::assertNotNull($obstacle);
            self::assertStringContainsString($collation, $obstacle[0]);
        } else {
            self::assertNull($obstacle);
        }
    }

    public function testTheHintNamesTheVersionThatHasTheCollation(): void
    {
        $obstacle = Preflight::collationObstacle('utf8mb4_uca1400_ai_ci', ServerInfo::parse('10.11.18-MariaDB'));

        self::assertNotNull($obstacle);
        self::assertStringContainsString('MariaDB 11.4 or newer', $obstacle[1]);
    }
}
