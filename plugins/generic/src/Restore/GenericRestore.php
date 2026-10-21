<?php

declare(strict_types=1);

namespace ThreadSafe\Generic\Restore;

use ThreadSafe\Backup\Manifest;
use ThreadSafe\Plugin\Restorable;
use ThreadSafe\Restore\Field;

/**
 * The restore side of "any website": files and database come back as they were. The person may name the old and the new domain
 * to have it replaced in the database content; configuration files stay as the backup had them (docs/MIGRATE.md section 12).
 */
final class GenericRestore implements Restorable
{
    public function fields(Manifest $manifest): array
    {
        return [
            new Field('from', 'Old domain', Field::TEXT, '', help: 'Only to replace the domain in the database content; empty leaves the content as it is.'),
            new Field('to', 'New domain', Field::TEXT, '', help: 'The domain that replaces the old one.'),
        ];
    }

    public function restoreSettings(array $values): array
    {
        $settings = [];
        foreach (['from', 'to'] as $key) {
            if (($values[$key] ?? '') !== '') {
                $settings[$key] = $values[$key];
            }
        }

        return $settings;
    }

    public function sourceDomain(Manifest $manifest): ?string
    {
        return null;
    }

    public function restoreChecks(Manifest $manifest): array
    {
        return [];
    }
}
