<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Tests\Support\Stack;

/**
 * Base class of the integration tests: they need the Docker stacks prepared by tests/stack/run.sh and are skipped
 * otherwise, so `composer test` and CI without Docker are not affected.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected function setUp(): void
    {
        if (!Stack::enabled()) {
            self::markTestSkipped('Needs the Docker stacks: run tests/stack/run.sh');
        }
    }

    /** Value of the first column of the first row. */
    protected static function scalar(PDO $db, string $sql): string
    {
        $statement = $db->query($sql);
        self::assertNotFalse($statement);
        $value = $statement->fetchColumn();

        return (string) $value;
    }

    /** The PrestaShop-managed part of an .htaccess (between its start and end markers). */
    protected static function managedBlock(string $htaccess): string
    {
        if (preg_match('/# ~~start~~.*# ~~end~~[^\n]*/s', $htaccess, $m) !== 1) {
            self::fail('Markers not found in .htaccess');
        }

        return $m[0];
    }

    protected static function oracleFile(string $name): string
    {
        $path = dirname(__DIR__, 2) . '/.cache/oracle/' . $name;
        if (!is_file($path)) {
            self::markTestSkipped("Oracle file $name is missing: run tests/stack/oracle.sh");
        }

        return (string) file_get_contents($path);
    }
}
