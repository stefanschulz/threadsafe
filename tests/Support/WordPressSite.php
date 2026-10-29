<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Support;

use ThreadSafe\Core\Context;
use ThreadSafe\Core\NullJournal;
use ThreadSafe\Core\NullLogger;
use ThreadSafe\Database\Connection;
use ThreadSafe\Database\DatabaseSettings;
use ThreadSafe\Migration\MigrationRequest;
use ThreadSafe\Replace\DomainMapping;
use ThreadSafe\WordPress\Config\WordPressConfigFile;
use ThreadSafe\WordPress\SourceState;
use ThreadSafe\WordPress\State;

/**
 * A WordPress installation on disk and the context of a run on it, for the tests of the steps of the WordPress type: the real
 * `wp-config.php` of the spikes (test values) and the real block of `.htaccess` that WordPress writes.
 */
final class WordPressSite
{
    /** What WordPress writes into `.htaccess` for pretty permalinks. */
    public const string HTACCESS = "# BEGIN WordPress\n\nRewriteEngine On\nRewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]\nRewriteBase /\nRewriteRule ^index\\.php\$ - [L]\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteCond %{REQUEST_FILENAME} !-d\nRewriteRule . /index.php [L]\n\n# END WordPress\n";

    /**
     * @param bool        $htaccess      also make the `.htaccess`
     * @param string|null $configuration the content of `wp-config.php`; null: the real file of the spikes; '': none
     */
    public static function build(string $root, bool $htaccess = false, ?string $configuration = null): void
    {
        $fixtures = dirname(__DIR__) . '/fixtures/wordpress';
        foreach (['wp-includes', 'wp-admin', 'wp-content/plugins', 'wp-content/themes'] as $directory) {
            mkdir($root . '/' . $directory, 0777, true);
        }
        copy($fixtures . '/version-6.9.4.php', $root . '/wp-includes/version.php');
        file_put_contents($root . '/wp-load.php', '<?php // loads WordPress');
        $configuration ??= (string) file_get_contents($fixtures . '/wp-config-6.9.4-plugin-added-constants.php');
        if ($configuration !== '') {
            file_put_contents($root . '/wp-config.php', $configuration);
        }
        if ($htaccess) {
            file_put_contents($root . '/.htaccess', self::HTACCESS);
        }
    }

    /**
     * The context of a run: the request, the state `ReadWordPressState` would have found, and the connection.
     *
     * @param list<DomainMapping>  $domains
     * @param array<string, mixed> $options what only the website type knows (`keepDropIns`)
     */
    public static function context(
        string $root,
        array $domains = [],
        ?DatabaseSettings $database = null,
        ?string $path = null,
        ?Connection $connection = null,
        ?string $serverPath = null,
        bool $selfTest = true,
        ?string $selfTestUrl = null,
        array $options = [],
        string $homeUrl = 'http://old.example',
    ): Context {
        $request = new MigrationRequest($root, $domains, $database, $path, serverPath: $serverPath, selfTest: $selfTest, selfTestUrl: $selfTestUrl, options: $options);
        $config = WordPressConfigFile::fromPath($root . '/wp-config.php');
        $configured = $config->database();
        $source = new SourceState(
            $root,
            '6.9.4',
            $configured,
            $database ?? $configured,
            $root . '/wp-config.php',
            $homeUrl,
            $homeUrl,
            $config->has('WP_HOME'),
            $config->has('WP_SITEURL'),
            '10.11.0-MariaDB',
        );
        $context = new Context($request, new NullJournal(), new NullLogger(), false);
        State::store($context, $source, $connection ?? FakeConnection::with([], 'wp_'));

        return $context;
    }

    private function __construct()
    {
    }
}
