<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Integration;

use PDO;
use ThreadSafe\Archive\ZipReader;
use ThreadSafe\Backup\BackupName;
use ThreadSafe\Backup\BackupRun;
use ThreadSafe\Backup\Manifest;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Engine\Executor;
use ThreadSafe\Engine\RunStatus;
use ThreadSafe\Engine\RunStore;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Profile\SiteProfile;
use ThreadSafe\Storage\LocalStorage;
use ThreadSafe\Tests\Support\ScratchShop;
use ThreadSafe\Tests\Support\SqlClient;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Tests\Support\UnzipOracle;

/**
 * A whole backup of a scratch PrestaShop 9 (files and database) through the profile, the engine and the archive, then the
 * backup taken apart with the plain tools: `unzip` for the files, the `mariadb` client for the SQL.
 */
final class BackupRunTest extends IntegrationTestCase
{
    use TempDirectory;

    private ScratchShop $shop;

    private string $data;

    private string $storage;

    private ?string $copy = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = ScratchShop::create();
        $base = $this->tempDirectory();
        $this->data = $base . '/data';
        $this->storage = $base . '/storage';
        $this->shop->exec("INSERT INTO ps_note (text) VALUES ('first'), (?), (NULL)", ["quote ' and \\ and 😀 " . str_repeat('x', 5000)]);
        $this->shop->exec("INSERT INTO ps_nokey (text) VALUES ('a'), ('a'), ('b')");
        $this->shop->exec("INSERT INTO ps_smarty_cache (id_smarty_cache, content) VALUES ('abc', 'cached junk')");
        $this->shop->exec("INSERT INTO ps_connections_source (http_referer) VALUES ('https://example.test/')");
        $this->shop->writeFile('img/p/1.jpg', random_bytes(3000));
        $this->shop->writeFile('modules/x/.git/config', 'left out');
        $this->shop->writeFile('admin-x/autoupgrade/backup/old.zip', 'left out');
        $this->shop->writeFile('themes/classic/theme.yml', "name: classic\n");
        (new ProfileStore($this->data . '/profiles'))->save(SiteProfile::fromArray([
            'id' => 'shop', 'type' => 'prestashop9', 'path' => $this->shop->root, 'storage' => $this->storage,
        ]));
    }

    protected function tearDown(): void
    {
        if ($this->copy !== null) {
            $this->shop->pdo()->exec("DROP DATABASE IF EXISTS `{$this->copy}`");
        }
        $this->shop->destroy();
        $this->cleanUpTempDirectories();
    }

    private function task(): BackupRun
    {
        return new BackupRun(new ProfileStore($this->data . '/profiles'), PluginRegistry::load(), $this->data);
    }

    /**
     * @return array<string, mixed>
     */
    private function backup(bool $oneTick, int $stopAfter = 0): array
    {
        $store = new RunStore($this->data . '/runs');
        $executor = new Executor($store, $this->task(), $oneTick ? static fn (): Budget => Budget::unlimited() : static fn (): Budget => new Budget(0.0));
        $run = $executor->start(['profile' => 'shop', 'snapshot' => $oneTick]);
        $run = $executor->execute($run->id, $stopAfter > 0 ? $stopAfter : null);
        if ($stopAfter > 0) {
            // the process dies; a new one (new objects, new connections) goes on
            foreach (glob($this->storage . '/*.part') ?: [] as $part) {
                file_put_contents($part, 'JUNK', FILE_APPEND);
            }
            $run = (new Executor($store, $this->task(), static fn (): Budget => new Budget(0.0)))->resume($run->id);
        }
        self::assertSame(RunStatus::Finished, $run->status);
        self::assertIsArray($run->state['result'] ?? null);

        return $run->state['result'];
    }

    public function testTheBackupHoldsTheShopAndItsDatabase(): void
    {
        $result = $this->backup(oneTick: true);

        self::assertSame('snapshot', $result['consistency']);
        self::assertSame('ok', $result['status'], (string) json_encode($result));
        $archive = $this->storage . '/' . $result['parts'][0];
        $reader = ZipReader::open($archive);
        self::assertTrue($reader->verify()->ok());
        $names = array_map(static fn ($e): string => $e->name, iterator_to_array($reader->entries(), false));

        foreach (['files/app/config/parameters.php', 'files/src/Core/Version.php', 'files/admin-x/themes/new-theme/.keep', 'files/img/p/1.jpg', 'files/themes/classic/theme.yml', 'db/0001_cms_lang.sql'] as $expected) {
            self::assertContains($expected, $names, $expected);
        }
        foreach ($names as $name) {
            foreach (['files/var/cache', 'files/cache/smarty/cache', 'files/cache/smarty/compile', '.git', 'autoupgrade/backup'] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $name, "$name must have been left out by the default filters");
            }
        }
        self::assertSame(['db/0001_cms_lang.sql', 'db/0002_configuration.sql'], array_slice(array_values(array_filter($names, static fn (string $n): bool => str_starts_with($n, 'db/'))), 0, 2));

        // structure-only tables have no rows in the dump, the others have
        $smarty = (string) $reader->contents($reader->find('db/0007_smarty_cache.sql') ?? throw new \LogicException('smarty file'), 100_000);
        self::assertStringContainsString('CREATE TABLE', $smarty);
        self::assertStringNotContainsString('INSERT', $smarty);
        self::assertStringContainsString('INSERT', (string) $reader->contents($reader->find('db/0002_configuration.sql') ?? throw new \LogicException('configuration file'), 100_000));

        $manifest = Manifest::fromArchive($archive);
        self::assertSame('PrestaShop', $manifest->application['name']);
        self::assertSame('9.0.2', $manifest->application['version']);
        self::assertSame('ps_', $manifest->database['prefix']);
        self::assertSame($this->shop->settings->name, $manifest->database['name']);
        self::assertContains($manifest->database['server'], ['mariadb', 'mysql']);
        self::assertSame('admin-x', $manifest->extras['adminDirectory']);
        self::assertSame('old.test', $manifest->extras['shopUrls'][0]['domain']);
        self::assertSame(8, $manifest->counts['tables']);
        foreach (["\"password\"", "\"user\"", "\"host\"", $this->shop->root] as $secret) {
            self::assertStringNotContainsString($secret, $manifest->toJson(), "No access data or server path in the manifest: $secret");
        }
        self::assertContains('smarty_cache', $manifest->filters['structureOnlyTables']);
        self::assertSame([], glob($this->storage . '/.work-*') ?: [], 'The dump files are not left behind.');
        if (UnzipOracle::available()) {
            [$ok, $output] = UnzipOracle::test($archive);
            self::assertTrue($ok, $output);
        }
    }

    public function testUnzipAndImportGiveBackTheShop(): void
    {
        $result = $this->backup(oneTick: true);
        $archive = $this->storage . '/' . $result['parts'][0];
        $target = $this->tempDirectory() . '/restored';
        mkdir($target);
        if (!UnzipOracle::available()) {
            self::markTestSkipped('unzip is needed');
        }
        self::assertTrue(UnzipOracle::extract($archive, $target));

        // files: the same tree, minus what the filters leave out
        foreach (['app/config/parameters.php', 'src/Core/Version.php', 'themes/classic/theme.yml', 'img/p/1.jpg'] as $file) {
            self::assertFileEquals($this->shop->root . '/' . $file, $target . '/files/' . $file);
        }
        self::assertDirectoryDoesNotExist($target . '/files/var/cache');
        self::assertDirectoryExists($target . '/files/admin-x/themes/new-theme');

        // database: the SQL files imported with the plain client give the same tables
        $this->copy = 'threadsafe_restore_' . bin2hex(random_bytes(4));
        $this->shop->pdo()->exec("CREATE DATABASE `{$this->copy}` CHARACTER SET utf8mb4");
        $files = glob($target . '/db/*.sql') ?: [];
        sort($files);
        self::assertCount(8, $files);
        foreach ($files as $file) {
            SqlClient::import($this->copy, $file);
        }
        $structureOnly = ['ps_smarty_cache', 'ps_smarty_lazy_cache', 'ps_connections_source'];
        foreach (['ps_configuration', 'ps_shop_url', 'ps_cms_lang', 'ps_note', 'ps_nokey'] as $table) {
            self::assertSame($this->checksum($this->shop->settings->name, $table), $this->checksum($this->copy, $table), $table);
        }
        foreach ($structureOnly as $table) {
            self::assertSame('0', $this->shop->value("SELECT COUNT(*) FROM `{$this->copy}`.`$table`"), "$table has its structure but no rows");
        }
        self::assertSame('1', $this->shop->value('SELECT COUNT(*) FROM ps_smarty_cache'), 'The source still has its rows.');
    }

    public function testCutIntoTicksAndInterruptedTheResultIsTheSame(): void
    {
        $reference = $this->backup(oneTick: true);
        $ticked = $this->backup(oneTick: false, stopAfter: 9);

        self::assertSame('table', $ticked['consistency'], 'Several ticks: per table, and the manifest says so.');
        $archive = fn (array $result): array => $this->entries($this->storage . '/' . $result['parts'][0]);
        self::assertSame($archive($reference), $archive($ticked));
        self::assertTrue(ZipReader::open($this->storage . '/' . $ticked['parts'][0])->verify()->ok());
        self::assertSame($reference['tables'], $ticked['tables']);
        self::assertSame($reference['rows'], $ticked['rows']);
    }

    public function testTheCommandLineMakesAndListsABackup(): void
    {
        $run = $this->threadsafe(...);


        [$code, $stdout, $stderr] = $run(['profile', 'test', 'shop']);
        self::assertSame(0, $code, $stdout . $stderr);
        self::assertStringContainsString('PrestaShop 9.0.2', $stdout);

        [$code, $stdout, $stderr] = $run(['backup', 'shop']);
        self::assertSame(0, $code, $stdout . $stderr);
        self::assertStringContainsString('8 table(s)', $stdout);
        self::assertStringContainsString('in one consistent snapshot', $stdout);
        self::assertCount(1, glob($this->storage . '/shop_*.zip') ?: []);
        self::assertTrue((new LocalStorage($this->storage))->isProtected());
        $name = (new LocalStorage($this->storage))->names('shop')[0] ?? null;
        self::assertInstanceOf(BackupName::class, $name);
        self::assertSame($name->base(), (new LocalStorage($this->storage))->readSidecar($name)->manifest->backupId);

        [$code, $stdout, $stderr] = $run(['backup', 'shop', '--tick-seconds=0', '--part-size=1M']);
        self::assertSame(0, $code, $stdout . $stderr);
        self::assertStringContainsString('table by table', $stdout);

        [$code, $stdout, $stderr] = $run(['backups', 'shop']);
        self::assertSame(0, $code, $stderr);
        self::assertSame(2, substr_count($stdout, 'PrestaShop 9.0.2'));
        [$code, $stdout, $stderr] = $run(['verify', 'shop']);
        self::assertSame(0, $code, $stdout . $stderr);
        self::assertStringContainsString('is intact (full check', $stdout);

        // the first backup, the one in one piece (both were made within a second, so the order of the names is not the order of the backups)
        $archive = array_values(array_filter(glob($this->storage . '/shop_*.zip') ?: [], static fn (string $f): bool => preg_match('/-p[0-9]+\.zip$/', $f) !== 1))[0] ?? null;
        self::assertNotNull($archive);
        $bytes = (string) file_get_contents($archive);
        file_put_contents($archive, substr_replace($bytes, chr(ord($bytes[1000]) ^ 1), 1000, 1));
        [$code, , $stderr] = $run(['verify', 'shop', '--quick']);
        self::assertSame(0, $code, 'Verify looks at the newest backup, which is the split one.');
        $name = basename($archive, '.zip');
        [$code, , $stderr] = $run(['verify', 'shop', $name]);
        self::assertSame(3, $code);
        self::assertStringContainsString('NOT intact', $stderr);
    }

    /**
     * Runs `bin/threadsafe` as a process of its own, with a data directory of its own.
     *
     * @param list<string> $arguments
     *
     * @return array{int, string, string} exit code, stdout, stderr
     */
    private function threadsafe(array $arguments): array
    {
        $env = ['THREADSAFE_DATA' => $this->data, 'PATH' => (string) getenv('PATH'), 'DB_ROOT_PASSWORD' => (string) getenv('DB_ROOT_PASSWORD')];
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/bin/threadsafe', ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    /**
     * @return array<string, array{int, int}> the entries of an archive that are not metadata: name => [CRC32, size]
     */
    private function entries(string $archive): array
    {
        $entries = [];
        foreach (ZipReader::open($archive)->entries() as $entry) {
            if (!str_starts_with($entry->name, 'threadsafe')) {
                $entries[$entry->name] = [$entry->crc32, $entry->size];
            }
        }
        ksort($entries);

        return $entries;
    }

    private function checksum(string $database, string $table): string
    {
        $statement = $this->shop->pdo()->query("CHECKSUM TABLE `$database`.`$table`");
        self::assertNotFalse($statement);
        $row = $statement->fetch(PDO::FETCH_NUM);

        return is_array($row) ? (string) $row[1] : '';
    }
}
