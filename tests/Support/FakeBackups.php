<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use ThreadSafe\Backup\BackupName;

/**
 * Backups of any age and size in a storage directory, for tests of what is kept and what goes: the sidecar file of one real backup is
 * the template, the parts are sparse files of the size the sidecar claims. Nothing here can be restored; it can be listed, counted,
 * pinned and deleted, which is all that retention and the listings look at.
 */
final class FakeBackups
{
    private int $counter = 0;

    public function __construct(private readonly BackupFixture $fixture)
    {
    }

    /**
     * @param string $status `ok`, `ok-with-warnings`, `unverified` or `faulty`
     */
    public function make(string $time, int $size = 10_000_000, string $status = 'ok', bool $pinned = false, string $profile = 'blog'): BackupName
    {
        $template = $this->template();
        $this->fixture->storage()->prepare();
        $name = BackupName::create($profile, new DateTimeImmutable($time, new DateTimeZone('UTC')), sprintf('%08x', ++$this->counter + 0x1000));
        $part = $name->partFile(1, false);
        $data = $template;
        $data['status'] = $status;
        $data['size'] = $size;
        $data['parts'] = [['file' => $part, 'size' => $size, 'sha256' => str_repeat('a', 64), 'entries' => 1]];
        if ($pinned) {
            $data['pinned'] = true;
        }
        $directory = $this->fixture->storageDirectory;
        file_put_contents($directory . '/' . $name->sidecarFile(), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $handle = fopen($directory . '/' . $part, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('Cannot make the part.');
        }
        ftruncate($handle, max(0, $size));
        fclose($handle);

        return $name;
    }

    /** Whether any file of the backup is still in the storage. */
    public function exists(BackupName $name): bool
    {
        return $this->fixture->storage()->files($name) !== [];
    }

    /** Unfinished files of a run that died: a `.part` file last touched at the time given. */
    public function leftover(string $time, string $profile = 'blog'): BackupName
    {
        $this->fixture->storage()->prepare();
        $name = BackupName::create($profile, new DateTimeImmutable($time, new DateTimeZone('UTC')), sprintf('%08x', ++$this->counter + 0x2000));
        $path = $this->fixture->storageDirectory . '/' . $name->partFile(1, false) . '.part';
        file_put_contents($path, str_repeat('x', 1000));
        touch($path, (new DateTimeImmutable($time, new DateTimeZone('UTC')))->getTimestamp());

        return $name;
    }

    /** @return array<string, mixed> */
    private function template(): array
    {
        static $template = null;
        if ($template === null) {
            $real = $this->fixture->backup();
            $json = (string) file_get_contents($this->fixture->storageDirectory . '/' . $real->sidecarFile());
            $template = json_decode($json, true);
            $this->fixture->storage()->removeBackup($real);
            if (!is_array($template)) {
                throw new \RuntimeException('No template sidecar.');
            }
        }

        /** @var array<string, mixed> $template */
        return $template;
    }
}
