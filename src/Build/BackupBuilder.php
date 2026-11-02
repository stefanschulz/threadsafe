<?php

declare(strict_types=1);

namespace ThreadSafe\Build;

use ThreadSafe\Core\Version;

/**
 * Builds the backup tool, `threadsafe-backup.php` (docs/PHASE12.md 3.1): the same program as the restore tool (the engine of the backup, the website types and the
 * encryption are in it already) behind the stub of its own, which asks for `threadsafe-backup-key.php` and hands over to {@see \ThreadSafe\Web\BackupEntry}.
 */
final class BackupBuilder
{
    private readonly RestoreBuilder $program;

    private readonly string $version;

    /**
     * @param string|null $version the version the tool says it is; default: the one of the running code
     */
    public function __construct(string $root, ?string $version = null)
    {
        $this->program = new RestoreBuilder($root, $version);
        $this->version = $version ?? Version::CURRENT;
    }

    /**
     * @return array{bytes: int, bundleBytes: int, files: int, resources: int}
     *
     * @throws \RuntimeException the file cannot be written, or the sources cannot be bundled
     */
    public function build(string $output): array
    {
        return $this->program->buildWith(ToolStub::backup($this->version), $output);
    }
}
