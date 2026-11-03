<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Integration;

use PDO;
use PHPUnit\Framework\Attributes\Group;
use ThreadSafe\Build\BackupBuilder;
use ThreadSafe\Crypto\AgeKeys;
use ThreadSafe\Crypto\Unlock;
use ThreadSafe\Restore\BackupSource;
use ThreadSafe\Tests\Support\BackupToolClient;
use ThreadSafe\Tests\Support\CliProcess;
use ThreadSafe\Tests\Support\ShopCompare;
use ThreadSafe\Tests\Support\Stack;
use ThreadSafe\Tests\Support\UnzipOracle;

/**
 * The backup tool on a real shop (docs/PHASE12.md 4.2): `threadsafe-backup.php` is put into the working shop of the target stack, whose own
 * Apache (5 seconds, 64 MB) serves it, and a person with a browser makes an encrypted backup of the shop in many steps. Then: the archive cannot be
 * fetched through the web server (the probe file and the state of the tool answer 403) while the same kind of file in a directory without
 * `.htaccess` can; the parts are downloaded with the SHA-256 of the list, one of them in two requests with `Range`; the real `age` tool opens them
 * with the key that was shown once, the ZIP inside passes `unzip -t` and holds the shop and none of the tool; `threadsafe restore` puts the backup
 * into a copy whose tables equal those of the live shop; the tool takes itself off the server and leaves nothing but the archive. A last test
 * makes a backup of the same shop in a shell, in one process and one snapshot.
 *
 * The tests use what the earlier ones left, so they run in the order of this file. They need the target shop working (it is, after the encryption group)
 * and leave it as they found it (group `backup-tool`, between the encryption phase and the disaster recovery of tests/stack/run.sh).
 */
#[Group('backup-tool')]
final class BackupToolStackTest extends IntegrationTestCase
{
    private const string SHOP = '/shops/target';

    private const string KEY = 'backup tool key 12345';

    private const string DIRECTORY = '/app/.cache/backup-tool';

    private static ?BackupToolClient $browser = null;

    private static string $keyFile = '';

    private static string $public = '';

    private static int $filesBefore = 0;

    /** @var list<array{file: string, sha: string}> */
    private static array $parts = [];

    private static int $ticks = 0;

    protected function setUp(): void
    {
        parent::setUp();
        if (!is_dir(self::SHOP . '/src/Core')) {
            self::markTestSkipped('The target shop volume is not mounted at ' . self::SHOP . ' or holds no shop.');
        }
        if (self::shell('command -v age && command -v age-keygen && command -v curl')[0] !== 0) {
            self::markTestSkipped('age, age-keygen and curl are needed (the tools image has them).');
        }
    }

    /** @return array{int, string} */
    private static function shell(string $command): array
    {
        exec($command . ' 2>&1', $lines, $code);

        return [$code, implode("\n", $lines)];
    }

    private static function filesInShop(): int
    {
        // the cache and the logs of the shop (var/) change with every request it answers, whatever the tool does
        return (int) self::shell('find ' . self::SHOP . ' -type f -not -path ' . escapeshellarg(self::SHOP . '/.threadsafe-backup/*') . ' -not -path ' . escapeshellarg(self::SHOP . '/var/*') . ' | wc -l')[1];
    }

    private static function putTheToolIntoTheShop(): void
    {
        (new BackupBuilder(dirname(__DIR__, 2)))->build(self::SHOP . '/threadsafe-backup.php');
        file_put_contents(self::SHOP . '/threadsafe-backup-key.php', "<?php return '" . self::KEY . "';");
        chmod(self::SHOP . '/threadsafe-backup.php', 0644);
        chmod(self::SHOP . '/threadsafe-backup-key.php', 0644);
    }

    private static function removeWhatTheToolLeft(): void
    {
        exec('rm -rf ' . escapeshellarg(self::SHOP . '/.threadsafe-backup') . ' ' . escapeshellarg(self::SHOP . '/threadsafe-backup.php') . ' ' . escapeshellarg(self::SHOP . '/threadsafe-backup-key.php') . ' ' . escapeshellarg(self::SHOP . '/probe-open.txt'));
    }

    /** The first group of a pattern that must match. */
    private static function found(string $pattern, string $text): string
    {
        if (preg_match($pattern, $text, $m) !== 1) {
            self::fail("Nothing on the page matches $pattern");
        }

        return $m[1];
    }

    private static function browser(): BackupToolClient
    {
        return self::$browser ?? throw new \LogicException('The first test starts the browser.');
    }

    /** @return string the storage directory of the tool, as the tools container sees it */
    private static function storage(): string
    {
        $found = glob(self::SHOP . '/.threadsafe-backup/storage-*', GLOB_ONLYDIR) ?: [];
        self::assertCount(1, $found, 'one storage with a random name');

        return $found[0];
    }

    // ------------------------------------------------------------------------------------------------- the wizard

    public function testAnEncryptedBackupOfTheRealShopIsMadeInManyStepsUnderTheLimits(): void
    {
        self::assertSame(200, Stack::get(Stack::TARGET_URL, Stack::TARGET_HOST, '/')->status, 'the target shop works');
        self::removeWhatTheToolLeft();
        exec('rm -rf ' . escapeshellarg(self::DIRECTORY));
        mkdir(self::DIRECTORY, 0777, true);
        self::$filesBefore = self::filesInShop();
        self::putTheToolIntoTheShop();

        self::$browser = $browser = new BackupToolClient(Stack::TARGET_URL);
        $page = $browser->request('GET');
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Access key', $page['body']);
        self::assertStringContainsString("default-src 'none'", $page['headers']['content-security-policy'] ?? '');

        self::assertSame(403, $browser->request('POST', '', ['action' => 'login', 'key' => 'wrong'] + (str_contains($page['body'], 'name="plain"') ? ['plain' => '1'] : []))['status'], 'a wrong key is refused');
        $login = $browser->request('POST', '', ['action' => 'login', 'key' => self::KEY] + (str_contains($page['body'], 'name="plain"') ? ['plain' => '1'] : []));
        self::assertSame(303, $login['status'], $login['body']);

        // the site: the shop is recognised and its database access is read from it; the connection is tested from inside the web server
        $site = $browser->go('GET', '?step=site');
        self::assertStringContainsString('PrestaShop', $site['body']);
        self::assertStringContainsString('read from the site', $site['body']);
        $options = $browser->go('POST', '', ['action' => 'save-site', 'root' => '/var/www/html', 'type' => 'auto']);
        self::assertStringContainsString('Who may open this backup?', $options['body'], $options['body']);

        // the key pair: the secret half is on the page once
        $keys = $browser->request('POST', '', ['action' => 'make-keys', 'mode' => 'pair', 'partMb' => '100', 'exclude' => '', 'note' => '', 'storage' => '']);
        self::assertSame(200, $keys['status']);
        self::$keyFile = self::DIRECTORY . '/key.txt';
        file_put_contents(self::$keyFile, html_entity_decode(strip_tags(self::found('~<pre id="secret-key"><code>(.*?)</code></pre>~s', $keys['body'])), ENT_QUOTES) . "\n");
        chmod(self::$keyFile, 0600);
        [$code, $public] = self::shell('age-keygen -y ' . escapeshellarg(self::$keyFile));
        self::assertSame(0, $code, $public);
        self::$public = trim($public);
        self::assertTrue(AgeKeys::isRecipient(self::$public));
        self::assertStringContainsString(self::$public, $keys['body']);
        $used = $browser->request('POST', '', ['action' => 'use-keys', 'public' => self::$public, 'stored' => '1']);
        self::assertStringContainsString('A key pair is made', $used['body']);
        self::assertStringNotContainsString('AGE-SECRET-KEY', $browser->go('GET', '?step=options')['body'], 'it is never shown again');
        $summary = $browser->go('POST', '', ['action' => 'save-options', 'mode' => 'pair', 'plain' => '', 'partMb' => '100', 'exclude' => '', 'note' => 'stack test', 'storage' => '']);
        self::assertStringContainsString('Back up now', $summary['body'], $summary['body']);
        self::assertStringContainsString('Encrypted for the public key ' . self::$public, $summary['body']);

        // the probe, made by the test as the script of the page would make it: neither the archive nor the directory of the tool is in the web's reach
        $probes = json_decode('[' . self::found('~urls = \[([^\]]+)\]~', $summary['body']) . ']', true);
        self::assertIsArray($probes);
        self::assertCount(2, $probes, 'one file in the directory of the tool, one in the directory of the archive');
        foreach ($probes as $probe) {
            self::assertMatchesRegularExpression('~^\.threadsafe-backup/(storage-[0-9a-f]{32}/)?probe-[0-9a-f]{16}\.txt$~', (string) $probe);
            self::assertSame(403, $browser->stranger((string) $probe)['status'], 'Apache reads the .htaccess of the directory: the probe file cannot be fetched');
        }
        $data = basename((string) (glob(self::SHOP . '/.threadsafe-backup/data-*', GLOB_ONLYDIR)[0] ?? ''));
        self::assertMatchesRegularExpression('~^data-[0-9a-f]{32}$~', $data, 'the data directory has a name that cannot be guessed');
        foreach (['.threadsafe-backup/', ".threadsafe-backup/$data/profiles/site.json", '.threadsafe-backup/access.log'] as $inside) {
            self::assertContains($browser->stranger($inside)['status'], [403, 404], "$inside is not served");
        }
        // and the counter-example: the same kind of file where nothing closes it is served, with its token
        file_put_contents(self::SHOP . '/probe-open.txt', 'ThreadSafe probe 0123');
        $open = $browser->stranger('probe-open.txt');
        self::assertSame(200, $open['status']);
        self::assertStringContainsString('ThreadSafe probe', $open['body']);
        unlink(self::SHOP . '/probe-open.txt');
        self::assertStringContainsString('"recorded":true', $browser->request('POST', '', ['action' => 'probe', 'result' => 'closed'])['body']);

        // the run: many requests of a few seconds each
        $started = $browser->go('POST', '', ['action' => 'start']);
        self::assertStringContainsString('Backing up', $started['body'], $started['body']);
        $deadline = time() + 1500;
        $last = [];
        while (time() < $deadline) {
            $answer = $browser->request('POST', '', ['action' => 'tick', 'format' => 'json']);
            self::assertSame(200, $answer['status'], 'every step is answered: ' . substr($answer['body'], 0, 300));
            $last = json_decode($answer['body'], true);
            self::assertIsArray($last, $answer['body']);
            self::assertNotSame('failed', $last['status'] ?? '', (string) ($last['error'] ?? ''));
            ++self::$ticks;
            if (($last['status'] ?? '') === 'finished') {
                break;
            }
        }
        self::assertSame('finished', $last['status'] ?? '', 'the backup finished in time');
        self::assertGreaterThan(2, self::$ticks, 'a backup of the shop in steps of 3 seconds takes several of them, not one request');

        $done = $browser->go('GET', '?step=done');
        self::assertStringContainsString('The backup is made', $done['body']);
        self::assertStringNotContainsString('not encrypted', $done['body']);
        preg_match_all('~<td><code>([^<]+\.zip\.age)</code></td><td>[^<]*</td><td><code>([0-9a-f]{64})</code></td>~', $done['body'], $rows, PREG_SET_ORDER);
        self::assertGreaterThan(1, count($rows), 'parts of 100 MB: more than one');
        foreach ($rows as $row) {
            self::$parts[] = ['file' => $row[1], 'sha' => $row[2]];
        }
        $storage = self::storage();
        self::assertSame([], glob($storage . '/.work-*') ?: [], 'the work files are gone');
        self::assertSame([], glob($storage . '/*.zip') ?: [], 'no plain part is in the storage');
        self::assertSame([], glob($storage . '/probe-*.txt') ?: [], 'the probe file is gone');
        foreach (self::$parts as $part) {
            self::assertSame($part['sha'], hash_file('sha256', $storage . '/' . $part['file']), $part['file']);
        }
        // the text of the key itself (the code of the tool has the words AGE-SECRET-KEY in it, so those are not what is searched for)
        $secretLine = AgeKeys::secretKeysIn((string) file_get_contents(self::$keyFile))[0];
        self::assertSame('', trim((string) shell_exec('grep -rlF -- ' . escapeshellarg($secretLine) . ' ' . escapeshellarg(self::SHOP . '/.threadsafe-backup') . ' 2>/dev/null')), 'the secret key is in no file of the tool');
    }

    public function testThePartsAreDownloadedWithTheirSha256AndOneInTwoRequestsWithRange(): void
    {
        self::assertNotSame([], self::$parts, 'the first test made the backup');
        $browser = self::browser();
        $dir = self::DIRECTORY . '/download';
        mkdir($dir);

        foreach (self::$parts as $i => $part) {
            if ($i === 0) {
                continue;
            }
            [$status] = $browser->download($part['file'], $dir . '/' . $part['file']);
            self::assertSame(200, $status, $part['file']);
            self::assertSame($part['sha'], hash_file('sha256', $dir . '/' . $part['file']), $part['file'] . ' arrived whole');
        }

        // the first part in two requests: a browser that was cut off, and the rest
        $first = self::$parts[0];
        $size = (int) filesize(self::storage() . '/' . $first['file']);
        self::assertGreaterThan(5_000_000, $size);
        [$status, $headers] = $browser->download($first['file'], $dir . '/head', '0-2999999');
        self::assertSame(206, $status);
        self::assertStringContainsString('Content-Range: bytes 0-2999999/' . $size, $headers);
        [$status, $headers] = $browser->download($first['file'], $dir . '/tail', '3000000-');
        self::assertSame(206, $status);
        self::assertStringContainsString('Content-Range: bytes 3000000-' . ($size - 1) . '/' . $size, $headers);
        file_put_contents($dir . '/' . $first['file'], (string) file_get_contents($dir . '/head') . (string) file_get_contents($dir . '/tail'));
        self::assertSame($first['sha'], hash_file('sha256', $dir . '/' . $first['file']), 'the two requests give the part');

        // a stranger gets no part: without the cookie it is the key page
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'follow_location' => 0]]);
        $stranger = (string) @file_get_contents(Stack::TARGET_URL . '/threadsafe-backup.php?step=file&part=' . rawurlencode($first['file']), false, $context);
        self::assertStringContainsString('Access key', $stranger);
        self::assertStringNotContainsString('age-encryption.org', $stranger);
        self::assertSame(404, $browser->request('GET', '?step=file&part=' . rawurlencode('../../app/config/parameters.php'))['status']);
    }

    public function testTheRealAgeToolOpensTheDownloadedPartsWithTheKeyThatWasShownOnce(): void
    {
        self::assertNotSame([], self::$parts, 'the first test made the backup');
        $dir = self::DIRECTORY . '/download';
        $names = [];
        foreach (self::$parts as $part) {
            $plain = self::DIRECTORY . '/' . basename($part['file'], '.age');
            [$code, $output] = self::shell('age -d -i ' . escapeshellarg(self::$keyFile) . ' -o ' . escapeshellarg($plain) . ' ' . escapeshellarg($dir . '/' . $part['file']));
            self::assertSame(0, $code, $output);
            [$ok, $result] = UnzipOracle::test($plain);
            self::assertTrue($ok, $result);
            $names = [...$names, ...explode("\n", trim(self::shell('unzip -Z1 ' . escapeshellarg($plain))[1]))];
            unlink($plain);
        }

        self::assertContains('files/app/config/parameters.php', $names, 'the shop is in it');
        self::assertContains('files/src/Core/Version.php', $names);
        self::assertSame([], array_values(array_filter($names, static fn (string $n): bool => str_contains($n, 'threadsafe-backup') || str_contains($n, '.threadsafe-backup'))), 'none of the tool is in it');
        self::assertNotSame([], array_filter($names, static fn (string $n): bool => preg_match('~^db/\d{4}_.+\.sql$~', $n) === 1), 'the database is in it: one SQL file per table in db/');
    }

    public function testTheBackupRestoresIntoACopyWhoseTablesEqualThoseOfTheLiveShop(): void
    {
        self::assertNotSame([], self::$parts, 'the first test made the backup');
        $rootPassword = getenv('DB_ROOT_PASSWORD') ?: 'prestashop';
        $admin = new PDO('mysql:host=target-db;charset=utf8mb4', 'root', $rootPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $admin->exec('DROP DATABASE IF EXISTS prestashop_toolcheck');
        $admin->exec('CREATE DATABASE prestashop_toolcheck CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
        file_put_contents(self::DIRECTORY . '/db-password', $rootPassword . "\n");
        $copy = self::DIRECTORY . '/copy';
        exec('rm -rf ' . escapeshellarg($copy));

        putenv('THREADSAFE_DATA=' . self::DIRECTORY . '/data');
        try {
            [$code, $out, $err] = CliProcess::run([
                'restore', self::DIRECTORY . '/download/' . self::$parts[0]['file'], '--root=' . $copy, '--to=' . Stack::TARGET_HOST,
                '--db-host=target-db', '--db-name=prestashop_toolcheck', '--db-user=root', '--db-password-file=' . self::DIRECTORY . '/db-password',
                '--identity-file=' . self::$keyFile, '--no-self-test', '--rules-dir=' . dirname(__DIR__) . '/fixtures/rules', '--yes',
            ], 1800);
        } finally {
            putenv('THREADSAFE_DATA');
        }
        self::assertSame(0, $code, $err . $out);

        $restored = new PDO('mysql:host=target-db;dbname=prestashop_toolcheck;charset=utf8mb4', 'root', $rootPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $secret = AgeKeys::secretKeysIn((string) file_get_contents(self::$keyFile))[0];
        $manifest = BackupSource::open(self::storage() . '/' . self::$parts[0]['file'], Unlock::keys([$secret]))->manifest;
        $differences = ShopCompare::differences(Stack::targetDb(), 'ps_', $restored, 'ps_', Stack::TARGET_HOST, array_map(static fn (string $t): string => 'ps_' . $t, $manifest->filters['structureOnlyTables']));
        self::assertSame([], $differences, 'every table of the copy equals the live shop');
        self::assertFileExists($copy . '/app/config/parameters.php');
        $different = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::SHOP . '/src/Core', \FilesystemIterator::SKIP_DOTS)) as $path => $info) {
            if ($info->isFile() && md5_file((string) $path) !== md5_file($copy . '/src/Core' . substr((string) $path, strlen(self::SHOP . '/src/Core')))) {
                $different[] = $path;
            }
        }
        self::assertSame([], array_slice($different, 0, 5), 'the files of the core are those of the live shop');
        self::assertFileDoesNotExist($copy . '/threadsafe-backup.php');

        $admin->exec('DROP DATABASE IF EXISTS prestashop_toolcheck');
        exec('rm -rf ' . escapeshellarg($copy));
    }

    public function testTheToolTakesItselfOffTheServerAndTheArchiveStays(): void
    {
        self::assertNotSame([], self::$parts, 'the first test made the backup');
        $browser = self::browser();
        $storage = self::storage();

        $gone = $browser->request('POST', '', ['action' => 'cleanup']);

        self::assertStringContainsString('The tool is gone', $gone['body']);
        self::assertFileDoesNotExist(self::SHOP . '/threadsafe-backup.php');
        self::assertFileDoesNotExist(self::SHOP . '/threadsafe-backup-key.php');
        self::assertSame(array_map(static fn (array $p): string => $p['file'], self::$parts), array_map('basename', glob($storage . '/*.zip.age') ?: []), 'the archive stays');
        self::assertSame([basename($storage)], array_values(array_diff(scandir(self::SHOP . '/.threadsafe-backup') ?: [], ['.', '..'])), 'only the storage is left of the state directory');
        self::assertStringNotContainsString('Access key', $browser->stranger('threadsafe-backup.php')['body'], 'the address of the tool does not answer as the tool any more');
        self::assertContains($browser->stranger('.threadsafe-backup/' . basename($storage) . '/' . self::$parts[0]['file'])['status'], [403, 404], 'the archive left behind is not served');

        exec('rm -rf ' . escapeshellarg(self::SHOP . '/.threadsafe-backup'));
        self::assertSame(self::$filesBefore, self::filesInShop(), 'the shop is as it was: no file of the tool is left');
        self::assertSame(200, Stack::get(Stack::TARGET_URL, Stack::TARGET_HOST, '/')->status, 'the shop still answers');
    }

    // ------------------------------------------------------------------------------------------------- the shell

    public function testInAShellTheSameShopIsBackedUpInOneProcessAndOneSnapshot(): void
    {
        self::removeWhatTheToolLeft();
        (new BackupBuilder(dirname(__DIR__, 2)))->build(self::SHOP . '/threadsafe-backup.php');
        chmod(self::SHOP . '/threadsafe-backup.php', 0644);
        $report = self::DIRECTORY . '/shell-report.json';

        [$code, $out] = self::shell(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::SHOP . '/threadsafe-backup.php') . ' backup --recipient=' . escapeshellarg(self::$public) . ' --part-size=200M --report=' . escapeshellarg($report));

        self::assertSame(0, $code, $out);
        self::assertStringContainsString('(prestashop9)', $out, 'the kind of the site came from the site');
        $result = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('snapshot', $result['result']['consistency'], 'one consistent snapshot of the database');
        self::assertTrue($result['result']['encrypted']);
        $storage = self::storage();
        $parts = glob($storage . '/*.zip.age') ?: [];
        self::assertNotSame([], $parts);
        self::assertSame([], glob($storage . '/*.zip') ?: []);
        $names = [];
        foreach ($parts as $part) {
            $plain = self::DIRECTORY . '/shell-' . basename($part, '.age');
            [$code, $output] = self::shell('age -d -i ' . escapeshellarg(self::$keyFile) . ' -o ' . escapeshellarg($plain) . ' ' . escapeshellarg($part));
            self::assertSame(0, $code, $output);
            [$ok, $text] = UnzipOracle::test($plain);
            self::assertTrue($ok, $text);
            $names = [...$names, ...explode("\n", trim(self::shell('unzip -Z1 ' . escapeshellarg($plain))[1]))];
            unlink($plain);
        }
        self::assertContains('files/app/config/parameters.php', $names);
        self::assertSame([], array_values(array_filter($names, static fn (string $n): bool => str_contains($n, 'threadsafe-backup'))));
        self::assertSame([], glob('/tmp/threadsafe-backup-*') ?: [], 'the temporary data directory is gone');

        self::removeWhatTheToolLeft();
        self::assertSame(self::$filesBefore, self::filesInShop(), 'the shop is as it was');
    }
}
