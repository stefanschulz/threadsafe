<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Support;

use PDO;
use ThreadSafe\Core\Context;
use ThreadSafe\Core\Journal;
use ThreadSafe\Core\NullLogger;
use ThreadSafe\Database\DatabaseSettings;
use ThreadSafe\Migration\MigrationRequest;

/**
 * A small fake PrestaShop 9 for tests: a scratch database on the source database server with just the tables the
 * steps look at, and a directory tree with the files they look at. Created fresh for every test and removed after it.
 */
final class ScratchShop
{
    public readonly string $root;

    public readonly DatabaseSettings $settings;

    private PDO $pdo;

    private function __construct(private readonly PDO $admin, private readonly string $database, string $root)
    {
        $this->root = $root;
        $this->settings = new DatabaseSettings('db', 3306, $database, 'root', self::rootPassword(), 'ps_');
        $this->pdo = new PDO("mysql:host=db;dbname=$database;charset=utf8mb4", 'root', self::rootPassword(), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    public static function create(string $domain = 'old.test'): self
    {
        $admin = new PDO('mysql:host=db;charset=utf8mb4', 'root', self::rootPassword(), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $database = 'threadsafe_scratch_' . bin2hex(random_bytes(4));
        $admin->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");

        $root = sys_get_temp_dir() . '/threadsafe-shop-' . bin2hex(random_bytes(6));
        $shop = new self($admin, $database, $root);
        $shop->createTables($domain);
        $shop->createFiles();

        return $shop;
    }

    public function destroy(): void
    {
        $this->admin->exec("DROP DATABASE IF EXISTS `{$this->database}`");
        $this->removeTree($this->root);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * @param list<scalar|null> $parameters
     */
    public function exec(string $sql, array $parameters = []): void
    {
        $this->pdo->prepare($sql)->execute($parameters);
    }

    /**
     * @param list<scalar|null> $parameters
     *
     * @return list<array<string, mixed>>
     */
    public function rows(string $sql, array $parameters = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param list<scalar|null> $parameters
     */
    public function value(string $sql, array $parameters = []): string
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return (string) $statement->fetchColumn();
    }

    public function writeFile(string $relative, string $content): void
    {
        $path = $this->root . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $content);
    }

    public function context(MigrationRequest $request, Journal $journal, bool $dryRun = false): Context
    {
        return new Context($request, $journal, new NullLogger(), $dryRun);
    }

    private static function rootPassword(): string
    {
        $password = getenv('DB_ROOT_PASSWORD');

        return $password === false || $password === '' ? 'prestashop' : $password;
    }

    private function createTables(string $domain): void
    {
        $engine = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci';
        foreach ([
            "CREATE TABLE ps_configuration (id_configuration INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, id_shop_group INT UNSIGNED NULL, id_shop INT UNSIGNED NULL, name VARCHAR(254) NOT NULL, value MEDIUMTEXT NULL, date_add DATETIME NOT NULL, date_upd DATETIME NOT NULL) $engine",
            "CREATE TABLE ps_shop_url (id_shop_url INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, id_shop INT UNSIGNED NOT NULL, domain VARCHAR(255) NOT NULL, domain_ssl VARCHAR(255) NOT NULL, physical_uri VARCHAR(64) NOT NULL, virtual_uri VARCHAR(64) NOT NULL, main TINYINT(1) NOT NULL, active TINYINT(1) NOT NULL, UNIQUE KEY full_shop_url (domain, physical_uri, virtual_uri)) $engine",
            "CREATE TABLE ps_cms_lang (id_cms INT UNSIGNED NOT NULL, id_lang INT UNSIGNED NOT NULL, content LONGTEXT NULL, meta_title VARCHAR(255) NULL, PRIMARY KEY (id_cms, id_lang)) $engine",
            "CREATE TABLE ps_note (id_note INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, text TEXT NULL) $engine",
            "CREATE TABLE ps_nokey (text TEXT NULL) $engine",
            "CREATE TABLE ps_smarty_cache (id_smarty_cache CHAR(40) NOT NULL PRIMARY KEY, content LONGTEXT NOT NULL) $engine",
            "CREATE TABLE ps_smarty_lazy_cache (template_hash VARCHAR(32) NOT NULL PRIMARY KEY, content LONGTEXT NULL) $engine",
            "CREATE TABLE ps_connections_source (id_connections_source INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, http_referer VARCHAR(255) NULL) $engine",
        ] as $sql) {
            $this->pdo->exec($sql);
        }

        $this->exec("INSERT INTO ps_shop_url (id_shop, domain, domain_ssl, physical_uri, virtual_uri, main, active) VALUES (1, ?, ?, '/', '', 1, 1)", [$domain, $domain]);
        foreach (['PS_SHOP_DOMAIN', 'PS_SHOP_DOMAIN_SSL'] as $name) {
            $this->exec("INSERT INTO ps_configuration (name, value, date_add, date_upd) VALUES (?, ?, NOW(), NOW())", [$name, $domain]);
        }
        $this->exec("INSERT INTO ps_configuration (name, value, date_add, date_upd) VALUES ('PS_SSL_ENABLED', '0', NOW(), NOW())");
    }

    private function createFiles(): void
    {
        $this->writeFile('src/Core/Version.php', "<?php\nfinal class Version\n{\n    public const VERSION = '9.0.2';\n}\n");
        $this->writeFile('app/config/parameters.php', '<?php return ' . var_export(['parameters' => [
            'database_host' => $this->settings->host,
            'database_port' => (string) $this->settings->port,
            'database_name' => $this->settings->name,
            'database_user' => $this->settings->user,
            'database_password' => $this->settings->password,
            'database_prefix' => $this->settings->prefix,
            'secret' => 'FAKE-SECRET',
        ]], true) . ';');
        $this->writeFile('admin-x/themes/new-theme/.keep', '');
        $this->writeFile('var/cache/CACHEDIR.TAG', 'Signature: 8a477f597d28d172789f06886806bc55');
        $this->writeFile('var/cache/index.php', '<?php // protection');
        $this->writeFile('var/cache/prod/appParameters.php', "<?php return ['database_password' => 'old'];");
        $this->writeFile('var/cache/prod/twig/a/b.php', 'x');
        $this->writeFile('var/cache/dev/c.php', 'x');
        $this->writeFile('cache/smarty/cache/one.php', 'x');
        $this->writeFile('cache/smarty/compile/two.php', 'x');
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
