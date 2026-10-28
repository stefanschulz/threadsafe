<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Integration;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use ThreadSafe\Backup\BackupName;
use ThreadSafe\Backup\BackupRun;
use ThreadSafe\Core\ExitCode;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Engine\Executor;
use ThreadSafe\Engine\Run;
use ThreadSafe\Engine\RunStatus;
use ThreadSafe\Engine\RunStore;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Profile\SiteProfile;
use ThreadSafe\Restore\RestoreException;
use ThreadSafe\Restore\RestoreRun;
use ThreadSafe\Storage\BackupCatalog;
use ThreadSafe\Storage\LocalStorage;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Tests\Support\TortureDatabase;

/**
 * The restore run against real database servers, with backups of a database that has every kind of column, a view on a view and
 * a trigger (and of a small shop-like one): files and tables come back, the same in one tick and in many, with both PHP interfaces,
 * with another table prefix, and the requirements keep a restore from starting where it must not.
 */
final class RestoreRunTest extends IntegrationTestCase
{
    use TempDirectory;

    private PDO $admin;

    /** @var list<string> */
    private array $databases = [];

    private string $base = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = new PDO('mysql:host=db;charset=utf8mb4', 'root', self::password(), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->base = $this->tempDirectory();
    }

    protected function tearDown(): void
    {
        foreach ($this->databases as $database) {
            $this->admin->exec("DROP DATABASE IF EXISTS `$database`");
        }
        $this->cleanUpTempDirectories();
    }

    private static function password(): string
    {
        $password = getenv('DB_ROOT_PASSWORD');

        return $password === false || $password === '' ? 'prestashop' : $password;
    }

    private function newDatabase(string $purpose): string
    {
        $name = 'threadsafe_restore_' . $purpose . '_' . bin2hex(random_bytes(3));
        $this->admin->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4");
        $this->databases[] = $name;

        return $name;
    }

    /** The database with everything in it, made by the helper that creates it. */
    private function tortureDatabase(): string
    {
        $name = 'threadsafe_restore_src_' . bin2hex(random_bytes(3));
        $this->databases[] = $name;
        TortureDatabase::create($this->admin, $name);

        return $name;
    }

    /**
     * @return array<string, mixed>
     */
    private function databaseSettings(string $name, string $prefix = '', string $driver = 'pdo'): array
    {
        return ['host' => 'db', 'port' => 3306, 'name' => $name, 'user' => 'root', 'password' => self::password(), 'prefix' => $prefix, 'driver' => $driver];
    }

    /** Backs up a small site with the given database through the engine; returns the first part of the backup. */
    private function backup(string $database, string $prefix = ''): string
    {
        $site = $this->base . '/site';
        @mkdir($site . '/assets', 0777, true);
        file_put_contents($site . '/index.php', '<?php echo 1;');
        file_put_contents($site . '/assets/data.bin', random_bytes(100000));
        $data = $this->base . '/data';
        $profiles = new ProfileStore($data . '/profiles');
        $profiles->save(SiteProfile::fromArray([
            'id' => 'shop', 'type' => 'generic', 'path' => $site, 'storage' => $this->base . '/storage',
            'database' => $this->databaseSettings($database, $prefix),
        ]));
        $executor = new Executor(new RunStore($data . '/runs'), new BackupRun($profiles, PluginRegistry::load(), $data), static fn (): Budget => Budget::unlimited());
        $run = $executor->execute($executor->start(['profile' => 'shop'])->id);
        $name = BackupName::parse((string) ($run->state['result']['backup'] ?? ''));
        self::assertNotNull($name);

        return (new BackupCatalog(new LocalStorage($this->base . '/storage')))->archiveParts($name)[0];
    }

    private function executor(bool $tickPerStep): Executor
    {
        return new Executor(new RunStore($this->base . '/runs'), new RestoreRun(PluginRegistry::load()), static fn (): Budget => $tickPerStep ? new Budget(0.0) : Budget::unlimited());
    }

    /**
     * @param array<string, mixed> $settings
     *
     * @return array<string, mixed>
     */
    private function parameters(string $archive, string $root, array $settings): array
    {
        return ['archive' => $archive, 'root' => $root, 'work' => $this->base . '/work', 'settings' => ['selfTest' => false, ...$settings]];
    }

    /** @return iterable<string, array{string, bool}> */
    public static function runs(): iterable
    {
        yield 'pdo, one tick per phase' => ['pdo', false];
        yield 'mysqli, one tick per phase' => ['mysqli', false];
        yield 'pdo, a tick per statement' => ['pdo', true];
        yield 'mysqli, a tick per statement' => ['mysqli', true];
    }

    #[DataProvider('runs')]
    public function testADatabaseWithEverythingInItComesBack(string $driver, bool $tickPerStep): void
    {
        $source = $this->tortureDatabase();
        $archive = $this->backup($source);
        $copy = $this->newDatabase('copy');
        $root = $this->base . '/restored';
        $executor = $this->executor($tickPerStep);

        $run = $executor->start($this->parameters($archive, $root, ['database' => $this->databaseSettings($copy, '', $driver)]));
        $phases = [];
        $final = $executor->execute($run->id, null, static function (Run $r) use (&$phases): void {
            $phases[explode(' ', (string) $r->progress)[0]] = true;
        });

        self::assertSame(RunStatus::Finished, $final->status);
        self::assertSame(['[1/5' => true, '[2/5' => true, '[3/5' => true, '[4/5' => true, '[5/5' => true], $phases);
        if ($tickPerStep) {
            self::assertGreaterThan(40, $final->ticks);
        }
        foreach (['binkey', 'blobs', 'docs', 'items', 'log', 'nopk', 'types'] as $table) {
            self::assertSame($this->checksum($source, $table), $this->checksum($copy, $table), "CHECKSUM TABLE $table");
        }
        self::assertSame(
            $this->value("SELECT GROUP_CONCAT(a ORDER BY a) FROM `$source`.a_dependent_view"),
            $this->value("SELECT GROUP_CONCAT(a ORDER BY a) FROM `$copy`.a_dependent_view"),
            'the view on a view',
        );
        self::assertSame('<?php echo 1;', file_get_contents($root . '/index.php'));
        self::assertDirectoryDoesNotExist($this->base . '/work/db', 'the unpacked SQL files are gone');
        self::assertStringContainsString('f_one', implode(' ', $final->state['result']['warnings']), 'the function that is not backed up is reported');
        self::assertStringNotContainsString(self::password(), (string) file_get_contents($this->base . '/runs/' . $run->id . '.json'), 'the finished state holds no password');
    }

    public function testADatabaseThatIsNotEmptyIsRefusedBeforeAnythingIsWritten(): void
    {
        $source = $this->tortureDatabase();
        $archive = $this->backup($source);
        $copy = $this->newDatabase('copy');
        $this->admin->exec("CREATE TABLE `$copy`.somebody_elses_table (id INT)");
        $root = $this->base . '/restored';
        $executor = $this->executor(false);
        $run = $executor->start($this->parameters($archive, $root, ['database' => $this->databaseSettings($copy)]));

        try {
            $executor->execute($run->id);
            self::fail('The restore should have been refused.');
        } catch (RestoreException $e) {
            self::assertSame(ExitCode::GuardFailed, $e->exitCode);
            self::assertStringContainsString('already has tables: somebody_elses_table', $e->getMessage());
        }
        self::assertDirectoryDoesNotExist($root, 'not even the directory was made');
    }

    public function testADatabaseThatIsNotEmptyIsReplacedWhenThePersonAsksForIt(): void
    {
        $source = $this->tortureDatabase();
        $archive = $this->backup($source);
        $copy = $this->newDatabase('copy');
        $this->admin->exec("CREATE TABLE `$copy`.somebody_elses_table (id INT PRIMARY KEY)");
        $this->admin->exec("CREATE TABLE `$copy`.items (id INT PRIMARY KEY, old_column TEXT)");
        $this->admin->exec("CREATE VIEW `$copy`.somebody_elses_view AS SELECT id FROM `$copy`.somebody_elses_table");
        $root = $this->base . '/restored';
        $executor = $this->executor(true);

        $run = $executor->start($this->parameters($archive, $root, ['database' => $this->databaseSettings($copy)]) + ['dropTables' => true]);
        $labels = [];
        $final = $executor->execute($run->id, null, static function (Run $r) use (&$labels): void {
            $labels[] = (string) $r->progress;
        });

        self::assertSame(RunStatus::Finished, $final->status);
        self::assertStringContainsString('the existing tables are deleted', implode("\n", $labels));
        self::assertSame('0', $this->value("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$copy' AND TABLE_NAME IN ('somebody_elses_table', 'somebody_elses_view')"), 'what was in the way is gone');
        foreach (['binkey', 'blobs', 'docs', 'items', 'log', 'nopk', 'types'] as $table) {
            self::assertSame($this->checksum($source, $table), $this->checksum($copy, $table), "CHECKSUM TABLE $table");
        }
        self::assertContains('[3/5 import] the existing tables are deleted', $labels, 'the deletion is a part of the import phase');
    }

    public function testTheTablesAreDeletedOnlyAfterTheFilesAreUnpacked(): void
    {
        $source = $this->tortureDatabase();
        $archive = $this->backup($source);
        $copy = $this->newDatabase('copy');
        $this->admin->exec("CREATE TABLE `$copy`.keep_me (id INT)");
        // a file where the restore wants a directory: the extraction fails
        $root = $this->base . '/restored';
        mkdir($root, 0777, true);
        file_put_contents($root . '/assets', 'a file where the backup has a directory');
        $executor = $this->executor(false);
        $run = $executor->start($this->parameters($archive, $root, ['database' => $this->databaseSettings($copy)]) + ['dropTables' => true, 'allowLeftovers' => true]);

        try {
            $executor->execute($run->id);
        } catch (RestoreException) {
            // the directory is in the way: the preflight or the extraction refuses
        }

        self::assertSame('1', $this->value("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$copy' AND TABLE_NAME = 'keep_me'"), 'a restore that fails before the import leaves the database alone');
    }

    public function testWithoutTheOptionADatabaseThatIsNotEmptyStaysRefusedAndTheMessageNamesTheWay(): void
    {
        $source = $this->tortureDatabase();
        $archive = $this->backup($source);
        $copy = $this->newDatabase('copy');
        $this->admin->exec("CREATE TABLE `$copy`.somebody_elses_table (id INT)");
        $executor = $this->executor(false);
        $run = $executor->start($this->parameters($archive, $this->base . '/restored', ['database' => $this->databaseSettings($copy)]));

        try {
            $executor->execute($run->id);
            self::fail('The restore should have been refused.');
        } catch (RestoreException $e) {
            self::assertStringContainsString('--drop-tables', $e->getMessage());
            self::assertStringContainsString('delete the existing tables first', $e->getMessage());
        }
        self::assertSame('1', $this->value("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$copy'"), 'nothing was deleted');
    }

    public function testTheTableDropperDeletesOnlyTheObjectsThatBeginWithThePrefix(): void
    {
        $name = $this->newDatabase('drop');
        foreach (['a_one (id INT PRIMARY KEY)', 'a_two (id INT, one_id INT, FOREIGN KEY (one_id) REFERENCES a_one (id))', 'aXnot (id INT)', 'b_other (id INT)'] as $definition) {
            $this->admin->exec("CREATE TABLE `$name`.$definition");
        }
        $this->admin->exec("CREATE VIEW `$name`.a_view AS SELECT id FROM `$name`.a_one");
        $db = \ThreadSafe\Database\Connection::open(\ThreadSafe\Database\DatabaseSettings::fromArray($this->databaseSettings($name, 'a_')));

        self::assertSame(3, \ThreadSafe\Restore\TableDropper::count($db, 'a_'), 'two tables and a view; the underscore of the prefix means itself, so aXnot is not counted');
        $left = \ThreadSafe\Restore\TableDropper::drop($db, 'a_', new Budget(null));

        self::assertSame(0, $left);
        self::assertSame('aXnot,b_other', $this->value("SELECT GROUP_CONCAT(TABLE_NAME ORDER BY TABLE_NAME) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$name'"), 'the tables of other names stay (the foreign key did not stop the deletion)');
    }

    public function testAWrongPasswordIsFoundInThePreflight(): void
    {
        $source = $this->tortureDatabase();
        $archive = $this->backup($source);
        $copy = $this->newDatabase('copy');
        $executor = $this->executor(false);
        $database = $this->databaseSettings($copy);
        $database['password'] = 'definitely-wrong';
        $run = $executor->start($this->parameters($archive, $this->base . '/restored', ['database' => $database]));

        $this->expectException(RestoreException::class);
        $this->expectExceptionMessage('Cannot connect to database');
        $executor->execute($run->id);
    }

    public function testMissingDatabaseSettingsAreRefused(): void
    {
        $source = $this->tortureDatabase();
        $archive = $this->backup($source);
        $executor = $this->executor(false);
        $run = $executor->start($this->parameters($archive, $this->base . '/restored', []));

        $this->expectException(RestoreException::class);
        $this->expectExceptionMessage('no database settings were given');
        $executor->execute($run->id);
    }

    public function testViewsAndTriggersStopAPrefixChangeInThePreflight(): void
    {
        $source = $this->tortureDatabase();
        $archive = $this->backup($source);
        $copy = $this->newDatabase('copy');
        $executor = $this->executor(false);
        $run = $executor->start($this->parameters($archive, $this->base . '/restored', ['database' => $this->databaseSettings($copy, 'xy_')]));

        try {
            $executor->execute($run->id);
            self::fail('The prefix change should have been refused.');
        } catch (RestoreException $e) {
            self::assertStringContainsString('prefix cannot be changed', $e->getMessage());
            self::assertStringContainsString('views or triggers', $e->getMessage());
        }
        self::assertDirectoryDoesNotExist($this->base . '/restored');
    }

    public function testAShopLikeDatabaseComesBackWithAnotherPrefixAndAnotherDomain(): void
    {
        $source = $this->newDatabase('shop');
        $this->admin->exec("CREATE TABLE `$source`.ps_cms (id INT NOT NULL PRIMARY KEY, content TEXT NULL, meta VARCHAR(100) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->admin->exec("CREATE TABLE `$source`.ps_note (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, text TEXT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        for ($i = 1; $i <= 30; ++$i) {
            $this->admin->prepare("INSERT INTO `$source`.ps_cms VALUES (?, ?, ?)")->execute([$i, "see <a href=\"http://old.example/p$i\">page</a>", $i % 2 === 0 ? 'http://old.example/' : 'plain']);
            $this->admin->prepare("INSERT INTO `$source`.ps_note (text) VALUES (?)")->execute([serialize(['u' => "https://www.old.example/n$i", 'i' => $i])]);
        }
        $archive = $this->backup($source, 'ps_');
        $copy = $this->newDatabase('copy');
        $executor = $this->executor(true);

        $run = $executor->start($this->parameters($archive, $this->base . '/restored', [
            'database' => $this->databaseSettings($copy, 'xy_'),
            'from' => 'old.example',
            'to' => 'new.example',
            'chunkSize' => 7,
        ]));
        $final = $executor->execute($run->id);

        self::assertSame(RunStatus::Finished, $final->status);
        self::assertSame(['xy_cms', 'xy_note'], $this->column("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$copy' ORDER BY 1"));
        self::assertSame('30', $this->value("SELECT COUNT(*) FROM `$copy`.xy_cms WHERE content LIKE '%http://new.example/p%'"));
        self::assertSame('0', $this->value("SELECT COUNT(*) FROM `$copy`.xy_cms WHERE content LIKE '%old.example%' OR meta LIKE '%old.example%'"));
        self::assertSame('0', $this->value("SELECT COUNT(*) FROM `$copy`.xy_note WHERE text LIKE '%old.example%'"));
        $unserialized = unserialize((string) $this->value("SELECT text FROM `$copy`.xy_note WHERE id = 3"));
        self::assertSame(['u' => 'https://new.example/n3', 'i' => 3], $unserialized, 'the serialized value stays valid');
        self::assertSame(60, $final->state['result']['report']['appliedChanges'] ?? -1, 'rows changed: 30 of ps_cms and 30 of ps_note');
        self::assertSame($this->value("SELECT COUNT(*) FROM `$source`.ps_cms WHERE content LIKE '%old.example%'"), '30', 'the source is untouched');
    }

    // ------------------------------------------------------------------------------------------------------ helpers

    private function checksum(string $database, string $table): string
    {
        $statement = $this->admin->query("CHECKSUM TABLE `$database`.`$table`");
        self::assertNotFalse($statement);
        $row = $statement->fetch(PDO::FETCH_NUM);

        return is_array($row) ? (string) $row[1] : '';
    }

    private function value(string $sql): string
    {
        $statement = $this->admin->query($sql);
        self::assertNotFalse($statement);

        return (string) $statement->fetchColumn();
    }

    /** @return list<mixed> */
    private function column(string $sql): array
    {
        $statement = $this->admin->query($sql);
        self::assertNotFalse($statement);

        return array_values($statement->fetchAll(PDO::FETCH_COLUMN));
    }
}
