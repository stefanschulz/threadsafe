<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Integration;

use PDO;
use ThreadSafe\Archive\ZipReader;
use ThreadSafe\Backup\BackupName;
use ThreadSafe\Engine\Lock;
use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Profile\SiteProfile;
use ThreadSafe\Storage\LocalStorage;
use ThreadSafe\Tests\Support\ScratchShop;
use ThreadSafe\Tests\Support\SqlClient;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Tests\Support\UnzipOracle;

/**
 * A backup under hard conditions, run as real processes of `bin/threadsafe`: artificially low limits, a process killed with
 * signal 9 in each phase and continued, a second run while one is going, files the user cannot read, a cell of 40 MB, a
 * "generic" site with a database. Every backup that is disturbed must give the same archive entries as an undisturbed one.
 */
final class BackupResilienceTest extends IntegrationTestCase
{
    use TempDirectory;

    private ScratchShop $shop;

    private string $data;

    private string $storage;

    /** @var list<resource> */
    private array $children = [];

    private ?string $copy = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = ScratchShop::create();
        $base = $this->tempDirectory();
        $this->data = $base . '/data';
        $this->storage = $base . '/storage';
        (new ProfileStore($this->data . '/profiles'))->save(SiteProfile::fromArray([
            'id' => 'shop', 'type' => 'prestashop9', 'path' => $this->shop->root, 'storage' => $this->storage,
        ]));
    }

    protected function tearDown(): void
    {
        foreach ($this->children as $child) {
            if (is_resource($child)) {
                @proc_terminate($child, 9);
                @proc_close($child);
            }
        }
        if ($this->copy !== null) {
            $this->shop->pdo()->exec("DROP DATABASE IF EXISTS `{$this->copy}`");
        }
        $this->shop->destroy();
        $this->cleanUpTempDirectories();
    }

    /** Makes the backup long enough to be caught in the middle: thousands of small files and some large ones. */
    private function enlargeSite(): void
    {
        for ($i = 0; $i < 2000; ++$i) {
            $directory = $this->shop->root . '/modules/bulk/d' . ($i % 40);
            if (!is_dir($directory)) {
                mkdir($directory, 0777, true);
            }
            file_put_contents($directory . '/file' . $i . '.php', "<?php // file $i\n" . str_repeat('x', 200));
        }
        if (!is_dir($this->shop->root . '/img')) {
            mkdir($this->shop->root . '/img');
        }
        for ($i = 0; $i < 2; ++$i) {
            file_put_contents($this->shop->root . "/img/large$i.bin", random_bytes(4_000_000));
        }
        for ($i = 0; $i < 400; ++$i) {
            $this->shop->exec('INSERT INTO ps_note (text) VALUES (?)', [str_repeat("row $i ", 200)]);
        }
    }

    /**
     * @param list<string> $arguments
     * @param list<string> $php       options for the PHP binary, e.g. -d memory_limit=64M
     *
     * @return array{int, string, string}
     */
    private function threadsafe(array $arguments, array $php = []): array
    {
        [$process, $pipes] = $this->spawn($arguments, $php);
        stream_set_blocking($pipes[1], true);
        stream_set_blocking($pipes[2], true);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    /**
     * @param list<string> $arguments
     * @param list<string> $php
     * @param list<string> $prefix a command to put in front (to run as another user)
     *
     * @return array{resource, array<int, resource>}
     */
    private function spawn(array $arguments, array $php = [], array $prefix = []): array
    {
        $env = ['THREADSAFE_DATA' => $this->data, 'PATH' => (string) getenv('PATH'), 'DB_ROOT_PASSWORD' => (string) getenv('DB_ROOT_PASSWORD')];
        $process = proc_open([...$prefix, PHP_BINARY, ...$php, dirname(__DIR__, 2) . '/bin/threadsafe', ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        self::assertIsResource($process);
        $this->children[] = $process;
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return [$process, $pipes];
    }

    /**
     * @return array<string, array{int, int}> entries that are not metadata: name => [CRC32, size]
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

    /** @return list<string> the archives of the shop's storage, oldest first */
    private function archives(): array
    {
        $files = glob($this->storage . '/shop_*.zip') ?: [];
        sort($files);

        return $files;
    }

    /**
     * Waits until a run of the data directory has a state for which $condition says yes.
     *
     * @param callable(array<string, mixed>): bool $condition
     *
     * @return array{string, array<string, mixed>} the run id and its state
     */
    private function waitForRun(callable $condition, int $seconds = 90): array
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            foreach (glob($this->data . '/runs/backup-*.json') ?: [] as $file) {
                $content = @file_get_contents($file);
                $run = $content === false ? null : json_decode($content, true);
                if (is_array($run) && is_array($run['state'] ?? null) && $condition($run['state'])) {
                    return [(string) $run['id'], $run['state']];
                }
            }
            usleep(2000);
        }
        self::fail('The run did not reach the wanted state in time (it finished before, or hangs).');
    }

    private function kill(mixed $process): void
    {
        self::assertIsResource($process);
        proc_terminate($process, 9);
        proc_close($process);
    }

    public function testKillingTheProcessInEachPhaseLeavesAResumableRun(): void
    {
        $this->enlargeSite();
        [$code, $stdout, $stderr] = $this->threadsafe(['backup', 'shop']);
        self::assertSame(0, $code, $stdout . $stderr);
        [$reference] = $this->archives();
        $expected = $this->entries($reference);

        $conditions = [
            'scan' => static fn (array $s): bool => $s['phase'] === 'scan' && ($s['scanFiles'] ?? 0) > 100,
            'dump' => static fn (array $s): bool => $s['phase'] === 'dump' && is_array($s['dump'] ?? null) && ($s['dump']['index'] ?? 0) >= 2,
            'pack' => static fn (array $s): bool => $s['phase'] === 'pack' && is_array($s['pack'] ?? null) && ($s['pack']['files'] ?? 0) > 500,
        ];
        // ticks of 0 seconds catch the short phases; the long one is caught with ticks of a few hundredths of a second
        $ticks = ['scan' => '0', 'dump' => '0', 'pack' => '0.05'];
        foreach ($conditions as $phase => $condition) {
            [$process] = $this->spawn(['backup', 'shop', '--tick-seconds=' . $ticks[$phase]]);
            [$runId] = $this->waitForRun($condition);
            $this->kill($process);   // signal 9: no cleanup of any kind

            [, $listing] = $this->threadsafe(['backups']);
            self::assertStringContainsString("Interrupted run $runId", $listing, "after a kill in the $phase phase");
            self::assertNotSame([], glob($this->storage . '/.work-*') ?: [], 'the work files are still there');

            [$code, $stdout, $stderr] = $this->threadsafe(['backup', "--resume=$runId", '--tick-seconds=0.5']);
            self::assertSame(0, $code, "resume after a kill in the $phase phase: $stdout $stderr");

            $archives = $this->archives();
            $latest = end($archives);
            self::assertNotFalse($latest);
            self::assertTrue(ZipReader::open($latest)->verify()->ok());
            self::assertSame($expected, $this->entries($latest), "killed in the $phase phase and continued: the same entries as an undisturbed run");
            self::assertSame([], glob($this->storage . '/.work-*') ?: [], 'the work directory is gone after the resume');
            self::assertSame([], glob($this->storage . '/*.part') ?: []);
            [$code, $stdout, $stderr] = $this->threadsafe(['verify', 'shop']);
            self::assertSame(0, $code, $stdout . $stderr);
        }
        self::assertCount(4, $this->archives());
    }

    public function testASecondRunIsRefusedWhileOneIsGoing(): void
    {
        $this->enlargeSite();
        [$process] = $this->spawn(['backup', 'shop', '--tick-seconds=0.05']);
        $this->waitForRun(static fn (array $s): bool => $s['phase'] !== 'init');
        self::assertTrue(Lock::isHeld($this->data . '/locks/backup.lock'));

        [$code, , $stderr] = $this->threadsafe(['backup', 'shop']);
        self::assertSame(2, $code);
        self::assertStringContainsString('Another backup is running', $stderr);
        [$code, , $stderr] = $this->threadsafe(['backup', '--all']);
        self::assertSame(2, $code);

        $this->kill($process);
        [$code, $stdout, $stderr] = $this->threadsafe(['backup', 'shop']);
        self::assertSame(0, $code, $stdout . $stderr);
    }

    public function testLowLimitsAndAHugeCellGiveTheSameArchive(): void
    {
        $default = (int) $this->shop->value('SELECT @@global.max_allowed_packet');
        try {
            $this->shop->pdo()->exec('SET GLOBAL max_allowed_packet = ' . (256 * 1048576));
            $creator = new PDO("mysql:host=db;dbname={$this->shop->settings->name};charset=utf8mb4", 'root', $this->shop->settings->password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $creator->exec('CREATE TABLE ps_huge (id INT NOT NULL PRIMARY KEY, data LONGBLOB NULL) ENGINE=InnoDB');
            $creator->exec("INSERT INTO ps_huge VALUES (1, RPAD(_binary '', 41943040, _binary 'abcdefghijklmnopqrstuvwxyz0123456789')), (2, NULL)");
            unset($creator);
        } finally {
            $this->shop->pdo()->exec("SET GLOBAL max_allowed_packet = $default");
        }
        $this->enlargeSite();

        [$code, $stdout, $stderr] = $this->threadsafe(['backup', 'shop', '--report=' . $this->data . '/normal.json']);
        self::assertSame(0, $code, $stdout . $stderr);
        [$code, $stdout, $stderr] = $this->threadsafe(['backup', 'shop', '--tick-seconds=1', '--report=' . $this->data . '/limited.json'], ['-d', 'memory_limit=64M']);
        self::assertSame(0, $code, "with memory_limit=64M and one-second ticks: $stdout $stderr");

        [$normal, $limited] = $this->archives();
        self::assertSame($this->entries($normal), $this->entries($limited));
        self::assertGreaterThan(40, count($this->entries($normal)));
        $report = json_decode((string) file_get_contents($this->data . '/limited.json'), true);
        self::assertSame('table', $report['result']['consistency']);
        self::assertSame('snapshot', json_decode((string) file_get_contents($this->data . '/normal.json'), true)['result']['consistency']);

        // the cell is in the archive in pieces, and the pieces put together are the cell
        $reader = ZipReader::open($limited);
        $entry = null;
        foreach ($reader->entries() as $candidate) {
            if (str_ends_with($candidate->name, '_huge.sql')) {
                $entry = $candidate;
            }
        }
        self::assertNotNull($entry);
        $hash = hash_init('md5');
        $buffer = '';
        $pieces = 0;
        $reader->extract($entry, function (string $chunk) use (&$buffer, $hash, &$pieces): void {
            $buffer .= $chunk;
            while (($newline = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $newline);
                $buffer = substr($buffer, $newline + 1);
                if (preg_match('/^\(1,0x([0-9A-F]+)\);$|^UPDATE `ps_huge` SET `data` = CONCAT\(`data`, 0x([0-9A-F]+)\) WHERE `id` = 1;$/', $line, $m) === 1) {
                    hash_update($hash, (string) hex2bin($m[2] ?? $m[1]));
                    ++$pieces;
                }
            }
        });
        self::assertSame(20, $pieces);
        self::assertSame($this->shop->value('SELECT MD5(data) FROM ps_huge WHERE id = 1'), hash_final($hash));

        [$code, $stdout, $stderr] = $this->threadsafe(['verify', 'shop', '--json']);
        self::assertSame(0, $code, $stdout . $stderr);
        if (40 * 1048576 > $default) {
            self::assertStringContainsString('holds a cell of 40.0 MB', implode("\n", \ThreadSafe\Backup\Manifest::fromArchive($limited)->warnings));
        }
    }

    public function testFilesThatCannotBeReadAreWarningsNotFailures(): void
    {
        exec('command -v setpriv', $found, $code);
        if ($code !== 0 || !function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            self::markTestSkipped('Needs root with setpriv to run the backup as an ordinary user.');
        }
        $site = $this->tempDirectory() . '/site';
        mkdir($site . '/locked', 0777, true);
        mkdir($site . '/open', 0777, true);
        file_put_contents($site . '/open/readable.txt', 'fine');
        file_put_contents($site . '/secret.key', 'nobody may read this');
        file_put_contents($site . '/locked/inside.txt', 'in a closed directory');
        chmod($site . '/secret.key', 0000);
        chmod($site . '/locked', 0000);
        foreach ([dirname($site), $site, $site . '/open', $site . '/open/readable.txt'] as $path) {
            chmod($path, is_dir($path) ? 0755 : 0644);
        }
        $store = new ProfileStore($this->data . '/profiles');
        $store->save(SiteProfile::fromArray(['id' => 'plain', 'type' => 'generic', 'path' => $site, 'storage' => $this->storage . '-plain']));
        mkdir($this->storage . '-plain', 0777, true);
        chmod($this->storage . '-plain', 0777);
        foreach ([$this->data, $this->data . '/profiles'] as $directory) {
            chmod($directory, 0777);
        }
        foreach (glob($this->data . '/profiles/*.json') ?: [] as $file) {
            chmod($file, 0666);
        }

        [$process, $pipes] = $this->spawn(['backup', 'plain', '--report=' . $this->data . '/plain.json'], [], ['setpriv', '--reuid=33', '--regid=33', '--clear-groups']);
        stream_set_blocking($pipes[2], true);
        stream_set_blocking($pipes[1], true);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        $code = proc_close($process);

        self::assertSame(0, $code, $stdout . $stderr);
        self::assertStringContainsString('ok-with-warnings', $stdout);
        $archive = glob($this->storage . '-plain/plain_*.zip')[0] ?? null;
        self::assertNotNull($archive);
        $reader = ZipReader::open($archive);
        $names = array_map(static fn ($e): string => $e->name, iterator_to_array($reader->entries(), false));
        self::assertContains('files/open/readable.txt', $names);
        self::assertContains('files/secret.key', $names, 'A file that cannot be read is stored empty.');
        self::assertSame(0, $reader->find('files/secret.key')?->size);
        self::assertNotContains('files/locked/inside.txt', $names);
        $warnings = implode("\n", \ThreadSafe\Backup\Manifest::fromArchive($archive)->warnings);
        self::assertStringContainsString('/secret.key', $warnings);
        self::assertStringContainsString('/locked', $warnings);
        chmod($site . '/locked', 0755);   // so that the test can clean up
    }

    public function testAGenericSiteWithADatabaseIsBackedUpAndComesBack(): void
    {
        $site = $this->tempDirectory() . '/blog';
        mkdir($site, 0777, true);
        file_put_contents($site . '/index.php', '<?php echo "blog";');
        $this->shop->exec("INSERT INTO ps_note (text) VALUES (?), (NULL)", ["blog post with 'quotes', \\ and 😀"]);
        $password = $this->tempDirectory() . '/pw';
        file_put_contents($password, $this->shop->settings->password . "\n");

        [$code, $stdout, $stderr] = $this->threadsafe([
            'profile', 'add', 'blog', '--type=generic', '--path=' . $site, '--storage=' . $this->storage . '-blog',
            '--db-host=db', '--db-name=' . $this->shop->settings->name, '--db-user=root', '--db-password-file=' . $password, '--prefix=ps_',
        ]);
        self::assertSame(0, $code, $stdout . $stderr);
        [$code, $stdout, $stderr] = $this->threadsafe(['backup', 'blog']);
        self::assertSame(0, $code, $stdout . $stderr);
        self::assertStringContainsString('8 table(s)', $stdout);

        $archive = glob($this->storage . '-blog/blog_*.zip')[0] ?? null;
        self::assertNotNull($archive);
        if (!UnzipOracle::available()) {
            self::markTestSkipped('unzip is needed');
        }
        $target = $this->tempDirectory() . '/restored';
        mkdir($target);
        self::assertTrue(UnzipOracle::extract($archive, $target));
        $this->copy = 'threadsafe_generic_' . bin2hex(random_bytes(4));
        $this->shop->pdo()->exec("CREATE DATABASE `{$this->copy}` CHARACTER SET utf8mb4");
        $files = glob($target . '/db/*.sql') ?: [];
        sort($files);
        foreach ($files as $file) {
            SqlClient::import($this->copy, $file);
        }
        foreach (['ps_note', 'ps_configuration', 'ps_shop_url'] as $table) {
            self::assertSame($this->checksum($this->shop->settings->name, $table), $this->checksum($this->copy, $table), $table);
        }
        self::assertFileEquals($site . '/index.php', $target . '/files/index.php');
        $manifest = \ThreadSafe\Backup\Manifest::fromArchive($archive);
        self::assertSame('Any website', $manifest->application['name']);
        self::assertSame('ps_', $manifest->database['prefix']);
        self::assertStringNotContainsString($this->shop->settings->password, $manifest->toJson() . (string) file_get_contents((new LocalStorage($this->storage . '-blog'))->sidecarPath(BackupName::parse(basename($archive, '.zip')) ?? throw new \LogicException('name'))) . $stdout);
    }

    private function checksum(string $database, string $table): string
    {
        $statement = $this->shop->pdo()->query("CHECKSUM TABLE `$database`.`$table`");
        self::assertNotFalse($statement);
        $row = $statement->fetch(PDO::FETCH_NUM);

        return is_array($row) ? (string) $row[1] : '';
    }
}
