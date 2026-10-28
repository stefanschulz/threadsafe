<?php

declare(strict_types=1);

namespace ThreadSafe\Plugin;

use ThreadSafe\Backup\Manifest;
use ThreadSafe\Restore\Check;
use ThreadSafe\Restore\Field;

/**
 * What a website type knows that a restore needs, next to {@see WebsiteType} (the adaptation after the restore is a migration)
 * and {@see Backupable}. Internal interface, like those.
 */
interface Restorable
{
    /**
     * What the restore asks beyond the values every restore needs (target directory, database), pre-filled from the manifest of
     * the backup: for PrestaShop the new domain, SSL mode, base path, dev mode.
     *
     * @return list<Field>
     */
    public function fields(Manifest $manifest): array;

    /**
     * The request settings (names of {@see \ThreadSafe\Migration\RequestBuilder}) that follow from the values of the fields.
     *
     * @param array<string, string> $values field name => value; fields left empty are left out
     *
     * @return array<string, mixed>
     */
    public function restoreSettings(array $values): array;

    /**
     * The domain the backed-up site had, the `from` of the domain replacement; null if the manifest does not say.
     */
    public function sourceDomain(Manifest $manifest): ?string;

    /**
     * Checks that only this type can make before anything is written: is the backup of a version it can restore?
     *
     * @return list<Check>
     */
    public function restoreChecks(Manifest $manifest): array;

    /**
     * Where the restored site can be opened, for the buttons on the last page of the wizard: the site itself and, if the type knows it, the
     * login of its administration. Paths from the root of the domain, ending in a slash.
     *
     * @param array<string, string> $values     field name => value, as for {@see self::restoreSettings()}
     * @param string                $servedPath the directory of the domain the wizard is served from ("/" or "/sub/"): where the site is when the
     *                                          person gave no base path and the type has no better idea
     *
     * @return list<array{label: string, path: string}>
     */
    public function links(Manifest $manifest, string $root, array $values, string $servedPath): array;
}
