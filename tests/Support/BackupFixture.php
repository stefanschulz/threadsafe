<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Support;

use ThreadSafe\Backup\BackupName;
use ThreadSafe\Backup\BackupRun;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Engine\Executor;
use ThreadSafe\Engine\RunStore;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Profile\SiteProfile;
use ThreadSafe\Storage\LocalStorage;

/**
 * A small site without a database, a profile for it ("any website"), and real backups made of it through the engine - the
 * material for tests of everything that works on finished backups. Needs a base directory the test cleans up.
 */
final class BackupFixture
{
    public readonly string $site;

    public readonly string $storageDirectory;

    public readonly string $data;

    public readonly ProfileStore $profiles;

    public readonly RunStore $runs;

    public function __construct(string $base, string $profileId = 'blog')
    {
        $this->site = $base . '/site';
        $this->storageDirectory = $base . '/storage';
        $this->data = $base . '/data';
        $this->profiles = new ProfileStore($this->data . '/profiles');
        $this->runs = new RunStore($this->data . '/runs');

        mkdir($this->site . '/assets', 0777, true);
        file_put_contents($this->site . '/index.php', '<?php echo 1;');
        file_put_contents($this->site . '/assets/big.bin', random_bytes(700_000));
        file_put_contents($this->site . '/assets/more.bin', random_bytes(600_000));
        file_put_contents($this->site . '/assets/text.txt', str_repeat("some text\n", 5000));
        $this->profiles->save(SiteProfile::fromArray(['id' => $profileId, 'type' => 'generic', 'path' => $this->site, 'storage' => $this->storageDirectory]));
    }

    public function storage(): LocalStorage
    {
        return new LocalStorage($this->storageDirectory);
    }

    public function task(): BackupRun
    {
        return new BackupRun($this->profiles, PluginRegistry::load(), $this->data);
    }

    /**
     * Makes a backup in one tick.
     *
     * @param array<string, mixed> $parameters more parameters for the run (partSize ...)
     */
    public function backup(array $parameters = [], string $profileId = 'blog'): BackupName
    {
        $executor = new Executor($this->runs, $this->task(), static fn (): Budget => Budget::unlimited());
        $run = $executor->execute($executor->start(['profile' => $profileId, ...$parameters])->id);
        $result = $run->state['result'] ?? null;
        if (!is_array($result) || !is_string($result['backup'] ?? null)) {
            throw new \RuntimeException('The backup did not finish.');
        }
        $this->runs->delete($run->id);

        return BackupName::parse($result['backup']) ?? throw new \RuntimeException('Not a backup name: ' . $result['backup']);
    }

    /** Flips one bit in the middle of the data of the first big entry of a part. */
    public function damage(string $partPath): void
    {
        $reader = \ThreadSafe\Archive\ZipReader::open($partPath);
        $entry = $reader->find('files/assets/big.bin') ?? $reader->find('files/assets/text.txt');
        if ($entry === null) {
            throw new \RuntimeException('Nothing to damage in ' . $partPath);
        }
        $offset = $entry->localOffset + 30 + strlen($entry->name) + 20 + intdiv($entry->compressedSize, 2);
        $handle = fopen($partPath, 'r+b');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open ' . $partPath);
        }
        fseek($handle, $offset);
        $byte = (string) fread($handle, 1);
        fseek($handle, $offset);
        fwrite($handle, chr(ord($byte) ^ 0x10));
        fclose($handle);
    }
}
