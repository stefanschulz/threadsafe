<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Integration;

use ThreadSafe\Tests\Support\Stack;

/**
 * The target stack holds a clone of the source and is deliberately unusable until it is migrated – and safely
 * isolated from the source while it is.
 */
final class CloneTest extends IntegrationTestCase
{
    public function testTargetIsBrokenBeforeTheMigration(): void
    {
        $response = Stack::get(Stack::TARGET_URL, Stack::TARGET_HOST);

        self::assertGreaterThanOrEqual(500, $response->status, 'A restored copy must not work before it is migrated.');
    }

    public function testTargetDatabaseHoldsAnEqualCopyOfTheSourceData(): void
    {
        $source = Stack::sourceDb();
        $target = Stack::targetDb();

        self::assertSame(
            self::scalar($source, "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'prestashop'"),
            self::scalar($target, "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'prestashop_target'"),
        );
        foreach (['ps_product', 'ps_cms_lang', 'ps_configuration', 'ps_shop_url', 'ps_threadsafe_fixture_item'] as $table) {
            self::assertSame(
                self::scalar($source, "SELECT COUNT(*) FROM $table"),
                self::scalar($target, "SELECT COUNT(*) FROM $table"),
                "Row count of $table",
            );
        }

        // A statistics table keeps growing while the source shop is visited, so it can only be compared roughly.
        $logRows = (int) self::scalar($target, 'SELECT COUNT(*) FROM ps_connections_source');
        self::assertGreaterThanOrEqual(200000, $logRows);
        self::assertLessThanOrEqual((int) self::scalar($source, 'SELECT COUNT(*) FROM ps_connections_source'), $logRows);
        self::assertSame(
            self::scalar($source, 'SELECT value FROM ps_configuration WHERE name = \'THREADSAFE_FIXTURE_SERIALIZED\''),
            self::scalar($target, 'SELECT value FROM ps_configuration WHERE name = \'THREADSAFE_FIXTURE_SERIALIZED\''),
        );
    }

    public function testTargetStillCarriesTheOldDomain(): void
    {
        $target = Stack::targetDb();

        self::assertSame(Stack::SOURCE_HOST, self::scalar($target, 'SELECT domain FROM ps_shop_url LIMIT 1'));
    }

    public function testTargetCannotSeeTheSourceDatabase(): void
    {
        // Name resolution of "db" is checked by tests/stack/clone.sh from inside the target container. From here
        // (the tools container sits in both networks) we check the consequence instead: changing the target
        // database must never change the source database.
        $source = Stack::sourceDb();
        $target = Stack::targetDb();

        $before = self::scalar($source, 'SELECT COUNT(*) FROM ps_configuration');
        $target->exec("INSERT INTO ps_configuration (name, value, date_add, date_upd) VALUES ('THREADSAFE_ISOLATION_PROBE', '1', NOW(), NOW())");
        try {
            self::assertSame($before, self::scalar($source, 'SELECT COUNT(*) FROM ps_configuration'));
        } finally {
            $target->exec("DELETE FROM ps_configuration WHERE name = 'THREADSAFE_ISOLATION_PROBE'");
        }
    }
}
