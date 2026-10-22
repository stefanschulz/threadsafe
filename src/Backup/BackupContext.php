<?php

declare(strict_types=1);

namespace ThreadSafe\Backup;

use ThreadSafe\Crypto\Recipient;
use ThreadSafe\Database\Connection;
use ThreadSafe\Profile\ResolvedProfile;
use ThreadSafe\Storage\LocalStorage;

/**
 * What one tick of a backup run works with: the profile as the website type resolves it, the storage, and (opened when
 * first needed) the database connection. Rebuilt in every tick; nothing in it survives between ticks.
 */
final class BackupContext
{
    private ?Connection $connection = null;

    public function __construct(
        public readonly ResolvedProfile $resolved,
        public readonly LocalStorage $storage,
        public readonly ?BackupName $name,
        public readonly ?int $partSize,
        public readonly int $compression,
        /** @var list<Recipient> whom the parts are encrypted for; none = the backup is not encrypted */
        public readonly array $recipients = [],
    ) {
    }

    public function encrypted(): bool
    {
        return $this->recipients !== [];
    }

    /** Where the plain parts of an encrypted backup wait to be sealed. */
    public function plainDirectory(): string
    {
        return $this->workDirectory() . '/parts';
    }

    /** The database of the site; null for a site without one. @throws \ThreadSafe\Database\ConnectionFailed */
    public function db(): ?Connection
    {
        if ($this->resolved->database === null) {
            return null;
        }

        return $this->connection ??= Connection::open($this->resolved->database);
    }

    public function backupName(): BackupName
    {
        return $this->name ?? throw new \LogicException('The backup has no name yet.');
    }

    /** The work directory of this backup inside the storage. */
    public function workDirectory(): string
    {
        return $this->storage->workDirectory($this->backupName());
    }
}
