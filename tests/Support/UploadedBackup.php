<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Support;

use PHPUnit\Framework\Assert;
use ThreadSafe\Backup\BackupName;
use ThreadSafe\Backup\BackupRun;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Engine\Executor;
use ThreadSafe\Engine\RunStore;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Profile\SiteProfile;
use ThreadSafe\Storage\BackupCatalog;
use ThreadSafe\Storage\LocalStorage;

/**
 * A backup of a small site with a database, made through the engine and "uploaded" into a directory, next to where a restore tool
 * would be.
 */
final class UploadedBackup
{
    /**
     * @param string $work     a scratch directory for the site, the profile and the storage of the backup
     * @param string $upload   where the parts of the backup are copied to
     * @param (callable(string): void)|null $prepare puts more into the site (given its directory) before it is backed up
     * @param int|null $partSize split the archive into parts of this many bytes
     *
     * @return list<string> the files in $upload
     */
    public static function make(string $work, string $upload, string $database, string $password, ?callable $prepare = null, ?int $partSize = null): array
    {
        $site = $work . '/site';
        mkdir($site, 0777, true);
        file_put_contents($site . '/index.php', '<?php echo 1;');
        if ($prepare !== null) {
            $prepare($site);
        }
        $data = $work . '/data';
        $profiles = new ProfileStore($data . '/profiles');
        $profiles->save(SiteProfile::fromArray([
            'id' => 'shop', 'type' => 'generic', 'path' => $site, 'storage' => $work . '/storage',
            'database' => ['host' => 'db', 'port' => 3306, 'name' => $database, 'user' => 'root', 'password' => $password, 'prefix' => '', 'driver' => 'pdo'],
        ]));
        $executor = new Executor(new RunStore($data . '/runs'), new BackupRun($profiles, PluginRegistry::load(), $data), static fn (): Budget => Budget::unlimited());
        $run = $executor->execute($executor->start(['profile' => 'shop'] + ($partSize === null ? [] : ['partSize' => $partSize]))->id);
        $name = BackupName::parse((string) ($run->state['result']['backup'] ?? ''));
        Assert::assertNotNull($name);

        $files = [];
        foreach ((new BackupCatalog(new LocalStorage($work . '/storage')))->archiveParts($name) as $part) {
            copy($part, $upload . '/' . basename($part));
            $files[] = $upload . '/' . basename($part);
        }

        return $files;
    }
}
