<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use ThreadSafe\Tests\Support\CliProcess;
use ThreadSafe\Tests\Support\Stack;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * The dev mode, started the way a user starts it – `threadsafe migrate prestashop9 --mode=dev` as a real process –
 * on the migrated clone (the live connections come from tests/fixtures/seed-live-links.sql). Runs in the third phase of
 * tests/stack/run.sh: it replaces robots.txt and adds a block to .htaccess, which the earlier phases compare with
 * PrestaShop's own output.
 *
 * The tests of this class depend on each other (PHPUnit runs them in order): the first one runs the migration.
 */
#[Group('dev-migration')]
final class DevModeMigrationTest extends IntegrationTestCase
{
    use TempDirectory;

    private const string TARGET = '/shops/target';

    private const array SECRETS = ['seed-smtp-password-1234', 'seed-paypal-live-secret-123', 'seed-rsa-private-key-material', 'seed-facebook-access-token', 'WH-SEED-LIVE-0001'];

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    /**
     * @return list<string>
     */
    private function command(string $directory): array
    {
        if (!is_dir(self::TARGET . '/src/Core')) {
            self::markTestSkipped('The target volume is not mounted at ' . self::TARGET . '.');
        }
        file_put_contents($directory . '/db-password', "target\n");

        return [
            'migrate', 'prestashop9',
            '--root=' . self::TARGET,
            '--db-host=target-db', '--db-name=prestashop_target', '--db-user=target',
            '--db-password-file=' . $directory . '/db-password',
            '--server-path=/var/www/html',
            '--mode=dev', '--mail-catcher=' . Stack::MAIL_CATCHER,
            '--journal-dir=' . $directory . '/journal',
        ];
    }

    private static function config(string $name): ?string
    {
        $statement = Stack::targetDb()->prepare('SELECT value FROM ps_configuration WHERE name = ? AND id_shop IS NULL');
        $statement->execute([$name]);
        $value = $statement->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    private static function moduleActive(string $name): string
    {
        $statement = Stack::targetDb()->prepare('SELECT active FROM ps_module WHERE name = ?');
        $statement->execute([$name]);

        return (string) $statement->fetchColumn();
    }

    public function testDryRunThenApplyCutsTheLiveConnections(): void
    {
        $directory = $this->tempDirectory();
        $command = $this->command($directory);

        // --- the migrated clone still carries the live connections
        self::assertSame('seed-smtp-password-1234', self::config('PS_MAIL_PASSWD'));
        self::assertSame('seed-paypal-live-secret-123', self::config('PAYPAL_LIVE_SECRET'));
        self::assertSame('1', self::moduleActive('ps_accounts'));
        $htaccessBefore = (string) file_get_contents(self::TARGET . '/.htaccess');

        // --- dry run: plans, shows no secret, changes nothing
        [$code, $stdout, $stderr] = CliProcess::run([...$command, '--report=' . $directory . '/dry.json']);
        self::assertSame(0, $code, $stderr . $stdout);
        self::assertStringContainsString('Dev mode: cut live mail, payment and cloud connections', $stdout);
        self::assertStringContainsString('All mail goes to the mail catcher ' . Stack::MAIL_CATCHER, $stdout);
        self::assertStringContainsString('THREADSAFE_FIXTURE_API_KEY', $stdout, 'A credential no rule handles is pointed out.');
        self::assertStringContainsString('customer account(s)', $stdout);
        $dry = json_decode((string) file_get_contents($directory . '/dry.json'), true);
        self::assertIsArray($dry);
        self::assertGreaterThan(20, $dry['plannedChanges']);
        foreach (self::SECRETS as $secret) {
            self::assertStringNotContainsString($secret, $stdout . $stderr . json_encode($dry), 'The report never shows a secret.');
        }
        self::assertSame('seed-smtp-password-1234', self::config('PS_MAIL_PASSWD'));
        self::assertSame($htaccessBefore, file_get_contents(self::TARGET . '/.htaccess'));
        self::assertDirectoryDoesNotExist($directory . '/journal');

        // --- apply
        [$code, $stdout, $stderr] = CliProcess::run([...$command, '--yes', '--report=' . $directory . '/applied.json']);
        self::assertSame(0, $code, $stderr . $stdout);
        $applied = json_decode((string) file_get_contents($directory . '/applied.json'), true);
        self::assertIsArray($applied);
        self::assertSame($dry['plannedChanges'], $applied['plannedChanges'], 'The dry run announced what the real run did.');
        foreach (self::SECRETS as $secret) {
            self::assertStringNotContainsString($secret, $stdout . $stderr . json_encode($applied));
        }

        self::assertSame('2', self::config('PS_MAIL_METHOD'));
        self::assertSame('mailpit', self::config('PS_MAIL_SERVER'));
        self::assertSame('1025', self::config('PS_MAIL_SMTP_PORT'));
        self::assertSame('', self::config('PS_MAIL_USER'));
        self::assertSame('', self::config('PS_MAIL_PASSWD'));
        self::assertSame('1', self::config('PAYPAL_SANDBOX'));
        self::assertNull(self::config('PAYPAL_LIVE_SECRET'));
        self::assertSame('', self::config('PAYPAL_WEBHOOK_ID'));
        self::assertSame('0', self::config('PAYPAL_ENABLE_WEBHOOK'));
        self::assertSame(0, (int) self::scalar(Stack::targetDb(), "SELECT COUNT(*) FROM ps_configuration WHERE name LIKE 'PS\\_ACCOUNTS\\_%' OR name LIKE 'PS\\_PSX\\_FIREBASE\\_%' OR name LIKE 'PS\\_FACEBOOK\\_%'"));
        self::assertSame(0, (int) self::scalar(Stack::targetDb(), 'SELECT COUNT(*) FROM ps_paypal_webhook'));
        self::assertSame(0, (int) self::scalar(Stack::targetDb(), 'SELECT COUNT(*) FROM ps_eventbus_incremental_sync'));
        foreach (['ps_accounts', 'ps_eventbus', 'ps_checkout', 'ps_facebook', 'ps_googleanalytics', 'psxmarketingwithgoogle'] as $module) {
            self::assertContains(self::moduleActive($module), ['0', ''], "module $module is off (or was never installed)");
        }
        self::assertSame('seed-unknown-service-key', self::config('THREADSAFE_FIXTURE_API_KEY'), 'What no rule knows is left alone (and was reported).');
        self::assertGreaterThan(0, (int) self::scalar(Stack::targetDb(), 'SELECT COUNT(*) FROM ps_customer'), 'Customers are not touched.');

        self::assertSame("User-agent: *\nDisallow: /\n", file_get_contents(self::TARGET . '/robots.txt'));
        $htaccess = (string) file_get_contents(self::TARGET . '/.htaccess');
        self::assertStringStartsWith('# ~~threadsafe-dev-start~~', $htaccess);
        self::assertSame(self::managedBlock($htaccessBefore), self::managedBlock($htaccess), 'PrestaShop\'s own block is untouched.');

        // --- the journal is the one place that keeps the old values
        $journals = glob($directory . '/journal/threadsafe-journal-*.jsonl') ?: [];
        self::assertCount(1, $journals);
        self::assertStringContainsString('seed-smtp-password-1234', (string) file_get_contents($journals[0]));
        self::assertSame(0600, fileperms($journals[0]) & 0777);

        // --- a second run has nothing left to do
        [$code, $stdout, $stderr] = CliProcess::run([...$command, '--yes', '--report=' . $directory . '/again.json']);
        self::assertSame(0, $code, $stderr . $stdout);
        $again = json_decode((string) file_get_contents($directory . '/again.json'), true);
        self::assertIsArray($again);
        self::assertSame(0, $again['plannedChanges'], 'A repeated dev run must not change anything.');
    }

    public function testTheShopStillWorksAndKeepsSearchEnginesOut(): void
    {
        $home = Stack::get(Stack::TARGET_URL, Stack::TARGET_HOST);
        self::assertSame(200, $home->status);
        self::assertStringContainsString('</html>', $home->body);
        self::assertSame('noindex, nofollow', $home->header('x-robots-tag'));

        $robots = Stack::get(Stack::TARGET_URL, Stack::TARGET_HOST, '/robots.txt');
        self::assertSame(200, $robots->status);
        self::assertStringContainsString('Disallow: /', $robots->body);

        $backOffice = Stack::get(Stack::TARGET_URL, Stack::TARGET_HOST, '/admin-dev/');
        self::assertSame(200, $backOffice->status);
    }

    public function testTheShopsMailArrivesInTheMailCatcher(): void
    {
        $email = self::scalar(Stack::targetDb(), 'SELECT email FROM ps_customer WHERE active = 1 ORDER BY id_customer LIMIT 1');
        self::assertNotSame('', $email);
        Stack::clearCaughtMails();

        // "I forgot my password" makes the shop send a mail to the customer.
        $response = Stack::post(Stack::TARGET_URL, Stack::TARGET_HOST, '/index.php?controller=password', ['email' => $email, 'submitPasswordEmail' => '1']);
        self::assertContains($response->status, [200, 302, 303], 'The shop accepted the form.');

        $caught = [];
        for ($attempt = 0; $attempt < 20 && $caught === []; ++$attempt) {
            $caught = Stack::caughtMails();
            if ($caught === []) {
                usleep(500000);
            }
        }

        self::assertNotSame([], $caught, 'The mail did not reach the mail catcher.');
        self::assertStringContainsString($email, json_encode($caught[0]['To'] ?? [], JSON_THROW_ON_ERROR));
    }
}
