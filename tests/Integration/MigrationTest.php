<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Integration;

/**
 * The end-to-end test of phase 1: migrate the clone and verify the result (acceptance criteria in docs/PHASE1.md).
 *
 * Placeholder until `bin/threadsafe migrate prestashop9` exists (work packages 2 and 5–8). It will:
 *  1. run the migration against the target stack (request: new domain localhost:8290, target database),
 *  2. request home page, category, product, a product image via its friendly URL, contact and the back office
 *     login on the target and expect 200,
 *  3. assert that no old domain remains outside the skip tables and that foreign domains, e-mail addresses and
 *     bare host names are untouched,
 *  4. assert that every serialized value still unserializes and JSON options are valid,
 *  5. compare the migrated .htaccess block with the oracle output (tests/stack/oracle.sh target),
 *  6. run the migration a second time and expect no change.
 */
final class MigrationTest extends IntegrationTestCase
{
    public function testMigratedTargetServesTheShop(): void
    {
        if (!is_file(dirname(__DIR__, 2) . '/bin/threadsafe')) {
            self::markTestSkipped('The migrate command does not exist yet (work packages 2 and 5–8).');
        }

        self::markTestIncomplete('Add the assertions listed in the class comment together with the command.');
    }
}
