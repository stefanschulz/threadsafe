<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Integration;

use PDO;
use PHPUnit\Framework\Attributes\Group;
use ThreadSafe\Backup\Manifest;
use ThreadSafe\Build\RestoreBuilder;
use ThreadSafe\Crypto\AgeKeys;
use ThreadSafe\Crypto\Unlock;
use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Profile\SiteProfile;
use ThreadSafe\Restore\BackupSource;
use ThreadSafe\Tests\Support\CliProcess;
use ThreadSafe\Tests\Support\ShopCompare;
use ThreadSafe\Tests\Support\Stack;
use ThreadSafe\Tests\Support\UnzipOracle;
use ThreadSafe\Tests\Support\WebWizard;
use ThreadSafe\Tests\Support\WizardJourney;

/**
 * Encrypted backups against the real world (docs/PHASE5.md 4.2): the seeded shop (299 tables, 45,000 files) is backed up for a key pair
 * made by `keys generate`; the real `age` tool opens every part with that key file, the ZIP inside passes `unzip -t`, the sealed file has
 * the SHA-256 of the sidecar, and the sidecar names nothing of the site. A passphrase backup of a small directory opens with `age`
 * too. Then the encrypted backup of the shop is restored into the target stack, once by `threadsafe restore --identity-file` (a wrong
 * key is refused before anything is written) and once by `restore.php` through the wizard of the target's own Apache under 5 seconds and
 * 64 MB, with the secret key typed into the first page; tables and files are compared with the source, and the secret key is found in no
 * file of the tool.
 *
 * The tests use what the earlier ones left, so they run in the order of this file. They wipe the target stack (group `encryption`, one
 * of the last phases of tests/stack/run.sh).
 */
#[Group('encryption')]
final class EncryptionStackTest extends IntegrationTestCase
{
    private const string DIRECTORY = '/app/.cache/encryption';

    private static string $keyFile = '';

    private static string $secret = '';

    private static string $public = '';

    /** The base name of the encrypted backup of the shop. */
    private static string $backup = '';

    protected function setUp(): void
    {
        parent::setUp();
        if (!is_dir('/shops/source/src/Core') || !is_dir('/shops/target')) {
            self::markTestSkipped('The shop volumes are not mounted at /shops/source and /shops/target.');
        }
        if (self::shell('command -v age && command -v age-keygen')[0] !== 0) {
            self::markTestSkipped('The real age tool is needed (the tools image has it).');
        }
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string, string}
     */
    private static function threadsafe(string $data, array $arguments, int $timeout = 1800): array
    {
        putenv('THREADSAFE_DATA=' . $data);
        try {
            return CliProcess::run($arguments, $timeout);
        } finally {
            putenv('THREADSAFE_DATA');
        }
    }

    /** @return array{int, string} */
    private static function shell(string $command): array
    {
        exec($command . ' 2>&1', $lines, $code);

        return [$code, implode("\n", $lines)];
    }

    /** @return list<string> */
    private static function sealedParts(string $storage, string $base): array
    {
        $parts = glob($storage . '/' . $base . '*.zip.age') ?: [];
        sort($parts);

        return $parts;
    }

    public function testTheEncryptedBackupOfTheShopOpensWithTheRealAgeToolAndTheSidecarNamesNothing(): void
    {
        exec('rm -rf ' . escapeshellarg(self::DIRECTORY));
        mkdir(self::DIRECTORY, 0777, true);
        $data = self::DIRECTORY . '/data';
        $storage = self::DIRECTORY . '/storage';

        // the key pair as `keys generate` presents it: the file of age-keygen
        self::$keyFile = self::DIRECTORY . '/key.txt';
        [$code, $out, $err] = self::threadsafe($data, ['keys', 'generate', '--output=' . self::$keyFile]);
        self::assertSame(0, $code, $out . $err);
        self::assertSame(0600, fileperms(self::$keyFile) & 0777);
        [$code, $public] = self::shell('age-keygen -y ' . escapeshellarg(self::$keyFile));
        self::assertSame(0, $code, $public);
        self::$public = trim($public);
        self::assertTrue(AgeKeys::isRecipient(self::$public));
        self::assertStringContainsString('# public key: ' . self::$public, (string) file_get_contents(self::$keyFile), 'age-keygen -y prints the public key that the file names');
        self::$secret = AgeKeys::secretKeysIn((string) file_get_contents(self::$keyFile))[0];

        (new ProfileStore($data . '/profiles'))->save(SiteProfile::fromArray([
            'id' => 'shop', 'name' => 'Seeded source shop', 'type' => 'prestashop9', 'path' => '/shops/source', 'storage' => $storage,
            'encryption' => ['recipients' => [self::$public]],
        ]));

        [$code, $out, $err] = self::threadsafe($data, ['backup', 'shop', '--part-size=100M']);
        self::assertSame(0, $code, $out . $err);

        $sidecars = glob($storage . '/shop_*.json') ?: [];
        self::assertCount(1, $sidecars);
        self::$backup = basename($sidecars[0], '.json');
        $parts = self::sealedParts($storage, self::$backup);
        self::assertGreaterThan(1, count($parts), 'a backup of 100 MB parts has several');
        self::assertSame([], glob($storage . '/*.zip') ?: [], 'no plain part lies in the storage');
        self::assertSame([], glob($storage . '/.work-*') ?: [], 'the work directory is gone');

        // the sidecar: the SHA-256 of every sealed file, and nothing about the site
        $sidecar = (string) file_get_contents($sidecars[0]);
        $record = json_decode($sidecar, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(count($parts), count($record['parts']));
        foreach ($record['parts'] as $recorded) {
            self::assertSame(hash_file('sha256', self::DIRECTORY . '/storage/' . $recorded['file']), $recorded['sha256'], $recorded['file']);
            self::assertSame(filesize(self::DIRECTORY . '/storage/' . $recorded['file']), $recorded['size']);
        }
        self::assertSame('age', $record['encryption']['format']);
        self::assertFalse($record['encryption']['passphrase']);
        foreach (['localhost', '8190', '/shops', 'ps_', 'threadsafe_fixture', 'parameters.php'] as $site) {
            self::assertStringNotContainsString($site, $sidecar, "the sidecar says nothing of the site ($site)");
        }

        // the real tool opens every part with the key file, and the ZIP inside is a good ZIP
        foreach ($parts as $part) {
            $plain = self::DIRECTORY . '/' . basename($part, '.age');
            [$code, $output] = self::shell('age -d -i ' . escapeshellarg(self::$keyFile) . ' -o ' . escapeshellarg($plain) . ' ' . escapeshellarg($part));
            self::assertSame(0, $code, $output);
            [$ok, $result] = UnzipOracle::test($plain);
            self::assertTrue($ok, $result);
            unlink($plain);
        }

        // our own full check with the key, the quick one without it
        [$code, $out, $err] = self::threadsafe($data, ['verify', 'shop', '--identity-file=' . self::$keyFile]);
        self::assertSame(0, $code, $out . $err);
        self::assertStringContainsString('(full check', $out);
        [$code, $out] = self::threadsafe($data, ['verify', 'shop']);
        self::assertSame(0, $code);
        self::assertStringContainsString('(quick check', $out);

        // one damaged byte in the middle of a sealed part is found by the full check
        $victim = $parts[0];
        $handle = fopen($victim, 'r+b');
        self::assertNotFalse($handle);
        fseek($handle, intdiv((int) filesize($victim), 2));
        $byte = (string) fread($handle, 1);
        fseek($handle, intdiv((int) filesize($victim), 2));
        fwrite($handle, chr(ord($byte) ^ 0x01));
        fclose($handle);
        [$code, , $err] = self::threadsafe($data, ['verify', 'shop', '--identity-file=' . self::$keyFile]);
        self::assertSame(3, $code, 'a changed byte in a sealed part is found');
        self::assertStringContainsString('NOT intact', $err);
        // put it back
        $handle = fopen($victim, 'r+b');
        self::assertNotFalse($handle);
        fseek($handle, intdiv((int) filesize($victim), 2));
        fwrite($handle, $byte);
        fclose($handle);
        [$code] = self::threadsafe($data, ['verify', 'shop', '--identity-file=' . self::$keyFile]);
        self::assertSame(0, $code, 'and the part is as it was');
    }

    public function testAGeneratedPassphraseHasTheShapePromisedAndOpensABackupWithTheRealAgeTool(): void
    {
        if (self::shell('command -v script')[0] !== 0) {
            self::markTestSkipped('script (a pseudo terminal for age) is not installed');
        }
        $dir = self::DIRECTORY . '/passphrase';
        exec('rm -rf ' . escapeshellarg($dir));
        mkdir($dir, 0777, true);
        $data = $dir . '/data';

        $seen = [];
        foreach (['one', 'two'] as $name) {
            [$code, $out, $err] = self::threadsafe($data, ['keys', 'passphrase', '--output=' . $dir . '/' . $name . '.txt']);
            self::assertSame(0, $code, $out . $err);
            self::assertSame(0600, fileperms($dir . '/' . $name . '.txt') & 0777);
            $seen[$name] = rtrim((string) file_get_contents($dir . '/' . $name . '.txt'));
            self::assertMatchesRegularExpression('/^(?:[a-hjkmnp-z2-9]{4}-){4}[a-hjkmnp-z2-9]{4}$/', $seen[$name]);
            self::assertStringNotContainsString($seen[$name], $out . $err, 'written to the file, not to the screen');
        }
        self::assertNotSame($seen['one'], $seen['two']);

        $site = '/shops/source/modules/threadsafe_fixture';
        self::assertDirectoryExists($site);
        (new ProfileStore($data . '/profiles'))->save(SiteProfile::fromArray(['id' => 'small', 'type' => 'generic', 'path' => $site, 'storage' => $dir . '/storage']));
        [$code, $out, $err] = self::threadsafe($data, ['backup', 'small', '--passphrase-file=' . $dir . '/one.txt']);
        self::assertSame(0, $code, $out . $err);
        self::assertStringNotContainsString($seen['one'], $out . $err);

        $sealed = glob($dir . '/storage/small_*.zip.age') ?: [];
        self::assertCount(1, $sealed);
        $record = json_decode((string) file_get_contents(substr($sealed[0], 0, -8) . '.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($record['encryption']['passphrase']);
        self::assertSame([], $record['encryption']['recipients']);

        // the real tool asks for the passphrase on a terminal
        $plain = $dir . '/plain.zip';
        self::shell('script -qec ' . escapeshellarg('age -d -o ' . escapeshellarg($plain) . ' ' . escapeshellarg($sealed[0])) . ' /dev/null < ' . escapeshellarg($dir . '/one.txt'));
        self::assertFileExists($plain, 'age opened it with the passphrase');
        [$ok, $result] = UnzipOracle::test($plain);
        self::assertTrue($ok, $result);

        // the other passphrase does not
        @unlink($plain);
        self::shell('script -qec ' . escapeshellarg('age -d -o ' . escapeshellarg($plain) . ' ' . escapeshellarg($sealed[0])) . ' /dev/null < ' . escapeshellarg($dir . '/two.txt'));
        self::assertFileDoesNotExist($plain, 'a stranger\'s passphrase opens nothing');

        // and our own check agrees
        [$code, $out] = self::threadsafe($data, ['verify', 'small', '--passphrase-file=' . $dir . '/one.txt']);
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('(full check', $out);
    }

    /** An empty target: an empty directory owned by the web server, an empty database. */
    private static function emptyTarget(): void
    {
        exec('find /shops/target -mindepth 1 -delete 2>&1', $ignored, $code);
        self::assertSame(0, $code, 'The target volume can be emptied.');
        exec('chown www-data:www-data /shops/target 2>&1', $ignored, $code);
        self::assertSame(0, $code);
        $admin = new PDO('mysql:host=target-db;charset=utf8mb4', 'root', getenv('DB_ROOT_PASSWORD') ?: 'prestashop', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $admin->exec('DROP DATABASE IF EXISTS prestashop_target');
        $admin->exec('CREATE DATABASE prestashop_target CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
    }

    private static function tablesInTarget(): int
    {
        return (int) Stack::targetDb()->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'prestashop_target'")?->fetchColumn();
    }

    private function manifest(): Manifest
    {
        $parts = self::sealedParts(self::DIRECTORY . '/storage', self::$backup);

        return BackupSource::open($parts[0], Unlock::keys([self::$secret]))->manifest;
    }

    public function testTheCommandRestoresTheEncryptedBackupAndAWrongKeyIsRefusedBeforeAnythingIsWritten(): void
    {
        self::assertNotSame('', self::$backup, 'the first test made the backup');
        $data = self::DIRECTORY . '/data-restore';
        $storage = self::DIRECTORY . '/storage';
        $first = self::sealedParts($storage, self::$backup)[0];
        $manifest = $this->manifest();
        self::emptyTarget();
        file_put_contents(self::DIRECTORY . '/db-password', "target\n");
        $stranger = AgeKeys::generate();
        file_put_contents(self::DIRECTORY . '/stranger.txt', AgeKeys::keyFile($stranger['secret'], $stranger['public']));
        $command = [
            'restore', $first, '--root=/shops/target', '--to=' . Stack::TARGET_HOST,
            '--db-host=target-db', '--db-name=prestashop_target', '--db-user=target', '--db-password-file=' . self::DIRECTORY . '/db-password',
            '--server-path=/var/www/html', '--rules-dir=' . dirname(__DIR__) . '/fixtures/rules', '--self-test-url=' . Stack::TARGET_URL,
        ];

        [$code, , $err] = self::threadsafe($data, $command);
        self::assertSame(2, $code, 'no key: refused');
        self::assertStringContainsString('is encrypted', $err);
        [$code, , $err] = self::threadsafe($data, [...$command, '--yes', '--identity-file=' . self::DIRECTORY . '/stranger.txt']);
        self::assertSame(2, $code, 'a stranger\'s key: refused');
        self::assertStringContainsString(basename(self::sealedParts($storage, self::$backup)[count(self::sealedParts($storage, self::$backup)) - 1]), $err);
        self::assertSame([], glob('/shops/target/*') ?: [], 'nothing was written');
        self::assertSame(0, self::tablesInTarget());

        [$code, $out, $err] = self::threadsafe($data, [...$command, '--identity-file=' . self::$keyFile]);
        self::assertSame(0, $code, 'Without --yes it only inspects: ' . $err . $out);
        self::assertStringContainsString('Nothing was changed.', $out);
        self::assertSame(0, self::tablesInTarget());

        [$code, $out, $err] = self::threadsafe($data, [...$command, '--identity-file=' . self::$keyFile, '--yes', '--report=' . self::DIRECTORY . '/result.json']);
        self::assertSame(0, $code, $err . $out);
        self::assertStringContainsString('Restored ' . $manifest->backupId, $out);
        self::assertStringNotContainsString(self::$secret, $out . $err);
        self::assertSame([], glob($data . '/runs/*.json') ?: [], 'the run state is deleted');
        $result = json_decode((string) file_get_contents(self::DIRECTORY . '/result.json'), true);
        self::assertSame($manifest->counts['files'], $result['files']);

        $differences = ShopCompare::differences(Stack::sourceDb(), 'ps_', Stack::targetDb(), 'ps_', Stack::SOURCE_HOST, array_map(static fn (string $t): string => 'ps_' . $t, $manifest->filters['structureOnlyTables']));
        self::assertSame([], $differences, 'every table equals the source');
        $different = [];
        $checked = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator('/shops/source/src/Core', \FilesystemIterator::SKIP_DOTS)) as $path => $info) {
            if ($info->isFile()) {
                ++$checked;
                if (md5_file((string) $path) !== md5_file('/shops/target/src/Core' . substr((string) $path, strlen('/shops/source/src/Core')))) {
                    $different[] = $path;
                }
            }
        }
        self::assertGreaterThan(100, $checked);
        self::assertSame([], array_slice($different, 0, 10), 'the files of the core are the files of the source');
        self::assertSame(200, Stack::get(Stack::TARGET_URL, Stack::TARGET_HOST, '/')->status, 'the shop answers');
    }

    public function testTheWizardRestoresItUnderTheLimitsWithTheKeyTypedIntoTheFirstPage(): void
    {
        self::assertNotSame('', self::$backup, 'the first test made the backup');
        $storage = self::DIRECTORY . '/storage';
        $manifest = $this->manifest();
        self::emptyTarget();

        // what a person puts into the empty directory: the tool, the key, the parts of the backup
        $upload = self::DIRECTORY . '/upload';
        exec('rm -rf ' . escapeshellarg($upload));
        mkdir($upload, 0777, true);
        (new RestoreBuilder(dirname(__DIR__, 2)))->build($upload . '/restore.php');
        file_put_contents($upload . '/restore-key.php', "<?php return 'encrypted restore key 12345';");
        foreach (self::sealedParts($storage, self::$backup) as $part) {
            copy($part, $upload . '/' . basename($part));
        }
        foreach (glob($upload . '/*') ?: [] as $file) {
            copy($file, '/shops/target/' . basename($file));
            chmod('/shops/target/' . basename($file), 0644);
        }
        $target = [
            'root' => '/var/www/html', 'db_host' => 'target-db', 'db_port' => '3306', 'db_name' => 'prestashop_target', 'db_user' => 'target', 'db_password' => 'target', 'db_prefix' => 'ps_',
            'f_to' => Stack::TARGET_HOST, 'f_path' => '', 'f_ssl' => '', 'f_mode' => 'production', 'f_mailCatcher' => '', 'allow_leftovers' => '1',
        ];

        // a wrong key is refused on the first page; nothing is written
        $stranger = AgeKeys::generate();
        $wizard = new WebWizard(baseUrl: Stack::TARGET_URL, scriptPath: '/restore.php');
        $page = $wizard->request('GET', '/');
        $login = $wizard->request('POST', '/', ['action' => 'login', 'key' => 'encrypted restore key 12345'] + (str_contains($page['body'], 'name="plain"') ? ['plain' => '1'] : []));
        self::assertSame(303, $login['status'], $login['body']);
        $backupPage = $wizard->follow($login);
        self::assertStringContainsString('encrypted', $backupPage['body']);
        preg_match('/name="choice" value="([^"]+)"/', $backupPage['body'], $choice);
        foreach (['', $stranger['secret']] as $typed) {
            $refused = $wizard->follow($wizard->request('POST', '/', ['action' => 'choose-backup', 'choice' => $choice[1] ?? '', 'path' => '', 'unlock' => $typed, 'csrf' => $wizard->csrf()]));
            self::assertStringContainsString('Choose the backup', $refused['body']);
            self::assertStringContainsString('class="fail"', $refused['body']);
            if ($typed !== '') {
                self::assertStringNotContainsString($typed, $refused['body'], 'what was typed is not shown again');
            }
        }
        self::assertSame(0, self::tablesInTarget());

        // the right one restores the shop in requests of 5 seconds
        $restoring = new WebWizard(baseUrl: Stack::TARGET_URL, scriptPath: '/restore.php');
        $journey = WizardJourney::restore($restoring, 'encrypted restore key 12345', $target, self::$secret);
        self::assertGreaterThan(3, $journey['ticks'], 'a restore of 40,000 files in requests of 5 seconds takes several of them');

        // the secret key is in no file of the tool or of its state
        $hits = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator('/shops/target/.threadsafe-restore', \FilesystemIterator::SKIP_DOTS)) as $path => $info) {
            if ($info->isFile() && str_contains((string) file_get_contents((string) $path), self::$secret)) {
                $hits[] = $path;
            }
        }
        self::assertSame([], $hits, 'the secret key is in no file of the state');

        $differences = ShopCompare::differences(Stack::sourceDb(), 'ps_', Stack::targetDb(), 'ps_', Stack::SOURCE_HOST, array_map(static fn (string $t): string => 'ps_' . $t, $manifest->filters['structureOnlyTables']));
        self::assertSame([], $differences, 'every table equals the source');
        self::assertSame(200, Stack::get(Stack::TARGET_URL, Stack::TARGET_HOST, '/')->status, 'the shop answers');
        self::assertSame(Stack::TARGET_HOST, (string) Stack::targetDb()->query('SELECT domain FROM ps_shop_url LIMIT 1')?->fetchColumn());

        $gone = $restoring->request('POST', '/', ['action' => 'cleanup', 'csrf' => $restoring->csrf(), 'archives' => '1']);
        self::assertStringContainsString('The tool is gone', $gone['body']);
        self::assertSame([], glob('/shops/target/shop_*.zip.age') ?: [], 'the encrypted parts are deleted on request');
    }
}
