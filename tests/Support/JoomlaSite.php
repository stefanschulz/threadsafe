<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Support;

use ThreadSafe\Core\Context;
use ThreadSafe\Core\NullJournal;
use ThreadSafe\Core\NullLogger;
use ThreadSafe\Database\Connection;
use ThreadSafe\Database\DatabaseSettings;
use ThreadSafe\Joomla\Config\JoomlaConfigFile;
use ThreadSafe\Joomla\SourceState;
use ThreadSafe\Joomla\State;
use ThreadSafe\Migration\MigrationRequest;
use ThreadSafe\Replace\DomainMapping;

/**
 * A Joomla installation on disk and the context of a run on it, for the tests of the steps of the Joomla type: the real
 * `configuration.php` of Joomla 6.2 (test values) and, if asked for, its `htaccess.txt` as `.htaccess`.
 */
final class JoomlaSite
{
    /** @param bool $htaccess also make the `.htaccess` (a copy of Joomla's `htaccess.txt`) */
    public static function build(string $root, bool $htaccess = false): void
    {
        $fixtures = dirname(__DIR__) . '/fixtures/joomla';
        mkdir($root . '/libraries/src', 0777, true);
        mkdir($root . '/administrator', 0777, true);
        file_put_contents($root . '/libraries/src/Version.php', "<?php\nfinal class Version\n{\n    public const MAJOR_VERSION = 6;\n    public const MINOR_VERSION = 2;\n    public const PATCH_VERSION = 0;\n    public const EXTRA_VERSION = '';\n}\n");
        copy($fixtures . '/configuration-6.2.php', $root . '/configuration.php');
        if ($htaccess) {
            copy($fixtures . '/htaccess-6.2.txt', $root . '/.htaccess');
        }
    }

    /**
     * The context of a run: the request, the state `ReadJoomlaState` would have found, and the connection.
     *
     * @param list<DomainMapping> $domains
     */
    public static function context(string $root, array $domains = [], ?DatabaseSettings $database = null, ?string $path = null, ?Connection $connection = null, ?string $serverPath = null, bool $selfTest = true, ?string $selfTestUrl = null): Context
    {
        $request = new MigrationRequest($root, $domains, $database, $path, serverPath: $serverPath, selfTest: $selfTest, selfTestUrl: $selfTestUrl);
        $config = JoomlaConfigFile::fromPath($root . '/configuration.php');
        $configured = $config->database();
        $source = new SourceState(
            $root,
            '6.2.0',
            $configured,
            $database ?? $configured,
            (string) $config->get('dbtype'),
            (string) $config->get('live_site'),
            (string) $config->get('log_path'),
            (string) $config->get('tmp_path'),
            '10.11.0-MariaDB',
        );
        $context = new Context($request, new NullJournal(), new NullLogger(), false);
        State::store($context, $source, $connection ?? FakeConnection::with([], 'joom_'));

        return $context;
    }

    private function __construct()
    {
    }
}
