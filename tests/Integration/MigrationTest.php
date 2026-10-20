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
        if (!$this->commandExists('migrate')) {
            self::markTestSkipped('The migrate command does not exist yet (work packages 5–8).');
        }

        self::markTestIncomplete('Add the assertions listed in the class comment together with the command.');
    }

    /** Whether `bin/threadsafe help <command>` succeeds, i.e. the command is registered. */
    private function commandExists(string $command): bool
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/threadsafe', 'help', $command],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            return false;
        }
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);

        return proc_close($process) === 0;
    }
}
