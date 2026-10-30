<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Support;

use PDO;
use ThreadSafe\Core\Context;
use ThreadSafe\Core\NullJournal;
use ThreadSafe\Core\NullLogger;
use ThreadSafe\Database\Connection;
use ThreadSafe\Database\DatabaseSettings;
use ThreadSafe\Migration\MigrationRequest;
use ThreadSafe\WordPress\SourceState;
use ThreadSafe\WordPress\State;

/**
 * A small fake WordPress for the tests of the steps that need a database: a scratch database on the source database server with the tables
 * the steps look at (the real columns and keys of `options`, `usermeta`, `users`, `posts` and `postmeta`), and a directory tree with the files they
 * look at. Created fresh for every test and removed after it.
 */
final class ScratchWordPress
{
    public readonly string $root;

    public readonly DatabaseSettings $settings;

    private PDO $pdo;

    private function __construct(private readonly PDO $admin, private readonly string $database, string $root, string $prefix)
    {
        $this->root = $root;
        $this->settings = new DatabaseSettings('db', 3306, $database, 'root', self::rootPassword(), $prefix);
        $this->pdo = new PDO("mysql:host=db;dbname=$database;charset=utf8mb4", 'root', self::rootPassword(), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    /**
     * @param string $prefix the prefix of the tables (the one the run uses)
     */
    public static function create(string $prefix = 'wp_', bool $htaccess = false): self
    {
        $admin = new PDO('mysql:host=db;charset=utf8mb4', 'root', self::rootPassword(), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $database = 'threadsafe_scratch_wp_' . bin2hex(random_bytes(4));
        $admin->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $root = (string) realpath(sys_get_temp_dir()) . '/threadsafe-wp-' . bin2hex(random_bytes(6));
        mkdir($root, 0777, true);
        WordPressSite::build($root, $htaccess);
        $site = new self($admin, $database, $root, $prefix);
        $site->createTables();

        return $site;
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

    public function table(string $name): string
    {
        return $this->settings->prefix . $name;
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

    public function option(string $name, string $value): void
    {
        $this->exec('INSERT INTO ' . $this->table('options') . ' (option_name, option_value, autoload) VALUES (?, ?, ?)', [$name, $value, 'yes']);
    }

    public function writeFile(string $relative, string $content): void
    {
        $path = $this->root . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $content);
    }

    /**
     * The context of a run: the request, the state `ReadWordPressState` would have found (the configuration file says `$oldPrefix`, the run
     * uses the prefix of this site), and the open connection.
     */
    public function context(MigrationRequest $request, string $oldPrefix = 'wp_', string $homeUrl = 'http://old.example'): Context
    {
        $configured = new DatabaseSettings('db', 3306, 'configured_db', 'configured_user', 'configured-password', $oldPrefix);
        $source = new SourceState($this->root, '6.9.4', $configured, $this->settings, $this->root . '/wp-config.php', $homeUrl, $homeUrl, false, false, '10.11.0-MariaDB');
        $context = new Context($request, new NullJournal(), new NullLogger(), false);
        State::store($context, $source, Connection::open($this->settings));

        return $context;
    }

    private static function rootPassword(): string
    {
        $password = getenv('DB_ROOT_PASSWORD');

        return $password === false || $password === '' ? 'prestashop' : $password;
    }

    private function createTables(): void
    {
        $engine = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $p = $this->settings->prefix;
        foreach ([
            "CREATE TABLE {$p}options (option_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, option_name VARCHAR(191) NOT NULL DEFAULT '', option_value LONGTEXT NOT NULL, autoload VARCHAR(20) NOT NULL DEFAULT 'yes', UNIQUE KEY option_name (option_name)) $engine",
            "CREATE TABLE {$p}usermeta (umeta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL DEFAULT 0, meta_key VARCHAR(255) NULL, meta_value LONGTEXT NULL) $engine",
            "CREATE TABLE {$p}users (ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, user_login VARCHAR(60) NOT NULL DEFAULT '', user_url VARCHAR(100) NOT NULL DEFAULT '') $engine",
            "CREATE TABLE {$p}posts (ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, post_title TEXT NOT NULL, post_content LONGTEXT NOT NULL, guid VARCHAR(255) NOT NULL DEFAULT '') $engine",
            "CREATE TABLE {$p}postmeta (meta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, post_id BIGINT UNSIGNED NOT NULL DEFAULT 0, meta_key VARCHAR(255) NULL, meta_value LONGTEXT NULL) $engine",
            "CREATE TABLE {$p}wc_webhooks (webhook_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, status VARCHAR(200) NOT NULL, name TEXT NOT NULL, delivery_url TEXT NOT NULL, topic VARCHAR(200) NOT NULL DEFAULT '') $engine",
            "CREATE TABLE {$p}woocommerce_sessions (session_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, session_key CHAR(32) NOT NULL, session_value LONGTEXT NOT NULL) $engine",
        ] as $sql) {
            $this->pdo->exec($sql);
        }
    }

    private function removeTree(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);

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
