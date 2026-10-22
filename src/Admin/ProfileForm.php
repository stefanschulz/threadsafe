<?php

declare(strict_types=1);

namespace ThreadSafe\Admin;

use ThreadSafe\Database\DatabaseSettings;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Profile\SiteProfile;
use ThreadSafe\Web\Request;

/**
 * The fields of the profile form and the way they become the data of a {@see SiteProfile}. The form holds only text; this turns it
 * into the types the profile wants and says what is wrong in words. The database password is never part of the values that are
 * shown again: an empty password field on an existing profile means "keep the one that is stored".
 */
final class ProfileForm
{
    public const array FIELDS = ['id', 'name', 'type', 'path', 'storage', 'note', 'db_mode', 'db_host', 'db_port', 'db_name', 'db_user', 'db_prefix', 'db_driver', 'exclude', 'structure_only', 'default_filters', 'compression', 'part_size', 'schedule', 'keep', 'max_age_days', 'max_size'];

    /** The fields that a form without the schedule and retention sections does not send; absent, they leave the profile as it is. */
    private const array OPTIONAL = ['schedule', 'keep', 'max_age_days', 'max_size'];

    /**
     * @return array<string, string> the values for a new profile
     */
    public static function defaults(string $type = ''): array
    {
        return ['id' => '', 'name' => '', 'type' => $type, 'path' => '', 'storage' => '', 'note' => '', 'db_mode' => 'site', 'db_host' => 'localhost', 'db_port' => '3306',
            'db_name' => '', 'db_user' => '', 'db_prefix' => '', 'db_driver' => DatabaseSettings::AUTO, 'exclude' => '', 'structure_only' => '', 'default_filters' => '1', 'compression' => '6', 'part_size' => '', 'schedule' => '', 'keep' => '', 'max_age_days' => '', 'max_size' => ''];
    }

    /**
     * @return array<string, string> the values of a stored profile (without the password)
     */
    public static function fromProfile(SiteProfile $profile): array
    {
        $values = self::defaults($profile->type);
        $values['id'] = $profile->id;
        $values['name'] = $profile->name;
        $values['path'] = $profile->path;
        $values['storage'] = $profile->storage;
        $values['note'] = $profile->note;
        $values['exclude'] = implode("\n", $profile->excludePaths);
        $values['structure_only'] = implode("\n", $profile->structureOnlyTables);
        $values['default_filters'] = $profile->useDefaultFilters ? '1' : '0';
        $values['compression'] = (string) $profile->compression;
        $values['part_size'] = $profile->partSize === null ? '' : self::formatSize($profile->partSize);
        $values['schedule'] = $profile->schedule === null ? '' : $profile->schedule->cron;
        $values['keep'] = $profile->retention?->keep === null ? '' : (string) $profile->retention->keep;
        $values['max_age_days'] = $profile->retention?->maxAgeDays === null ? '' : (string) $profile->retention->maxAgeDays;
        $values['max_size'] = $profile->retention?->maxBytes === null ? '' : self::formatSize($profile->retention->maxBytes);
        if ($profile->database !== null) {
            $values['db_mode'] = 'manual';
            $values['db_host'] = $profile->database->host;
            $values['db_port'] = (string) $profile->database->port;
            $values['db_name'] = $profile->database->name;
            $values['db_user'] = $profile->database->user;
            $values['db_prefix'] = $profile->database->prefix;
            $values['db_driver'] = $profile->database->driver;
        }

        return $values;
    }

    /**
     * @return array<string, string> what was sent in the form (a checkbox that was not ticked is "0")
     */
    public static function fromRequest(Request $request): array
    {
        $values = [];
        foreach (self::FIELDS as $field) {
            if (in_array($field, self::OPTIONAL, true) && $request->post($field) === null) {
                continue;
            }
            $values[$field] = str_replace("\r\n", "\n", $request->post($field) ?? '');
        }
        $values['default_filters'] = $request->post('default_filters') === '1' ? '1' : '0';

        return $values;
    }

    /**
     * @param array<string, string> $values
     * @param string                $password the content of the password field
     *
     * @return array{data: array<string, mixed>, errors: list<string>} the input for {@see SiteProfile::fromArray()} and what is wrong already
     */
    public static function toData(array $values, string $password, ?SiteProfile $current): array
    {
        $errors = [];
        $data = [
            'id' => $current !== null ? $current->id : trim($values['id'] ?? ''),
            'name' => trim($values['name'] ?? ''),
            'type' => trim($values['type'] ?? ''),
            'path' => trim($values['path'] ?? ''),
            'storage' => trim($values['storage'] ?? ''),
            'note' => trim($values['note'] ?? ''),
            'excludePaths' => self::lines($values['exclude'] ?? ''),
            'structureOnlyTables' => self::lines($values['structure_only'] ?? ''),
            'useDefaultFilters' => ($values['default_filters'] ?? '1') === '1',
        ];
        if ($data['name'] === '') {
            $data['name'] = $data['id'];
        }
        // the schedule and the retention come from the form when the form has them; what it does not send, and the encryption, stay as
        // they are: editing a profile must not drop what the form does not show
        if (array_key_exists('schedule', $values)) {
            $cron = trim($values['schedule']);
            $data['schedule'] = $cron === '' ? null : ['cron' => $cron];
        }
        if (array_key_exists('keep', $values) || array_key_exists('max_age_days', $values) || array_key_exists('max_size', $values)) {
            $retention = [];
            foreach (['keep' => ['keep', 'Keep'], 'max_age_days' => ['maxAgeDays', 'Delete after days']] as $field => [$key, $label]) {
                $text = trim($values[$field] ?? '');
                if ($text === '') {
                    continue;
                }
                if (preg_match('/^\d{1,6}$/', $text) !== 1) {
                    $errors[] = "$label is a whole number; leave it empty for no such rule.";
                } else {
                    $retention[$key] = (int) $text;
                }
            }
            $size = trim($values['max_size'] ?? '');
            if ($size !== '') {
                $bytes = Budget::parseBytes($size);
                if ($bytes === null) {
                    $errors[] = 'The size limit is a size such as 500M or 20G; leave it empty for no such rule.';
                } else {
                    $retention['maxBytes'] = $bytes;
                }
            }
            $data['retention'] = $retention === [] ? null : $retention;
        }
        if ($current !== null) {
            $data += array_intersect_key($current->toArray(), array_flip(['schedule', 'retention', 'encryption']));
        }

        $compression = trim($values['compression'] ?? '');
        if (preg_match('/^\d$/', $compression) !== 1) {
            $errors[] = 'Compression is a number from 0 (store) to 9.';
        } else {
            $data['compression'] = (int) $compression;
        }

        $partSize = trim($values['part_size'] ?? '');
        if ($partSize !== '') {
            $bytes = Budget::parseBytes($partSize);
            if ($bytes === null || $bytes < SiteProfile::MIN_PART_SIZE) {
                $errors[] = 'The part size is a size such as 500M or 2G, at least 1M; leave it empty for one archive.';
            } else {
                $data['partSize'] = $bytes;
            }
        }

        $data['database'] = null;
        if (($values['db_mode'] ?? 'site') === 'manual') {
            $port = trim($values['db_port'] ?? '');
            if (preg_match('/^\d{1,5}$/', $port) !== 1) {
                $errors[] = 'The database port is a number.';
                $port = '3306';
            }
            $driver = $values['db_driver'] ?? DatabaseSettings::AUTO;
            if (!in_array($driver, DatabaseSettings::DRIVERS, true)) {
                $errors[] = 'The PHP interface to the database is auto, pdo or mysqli.';
                $driver = DatabaseSettings::AUTO;
            }
            $data['database'] = [
                'host' => trim($values['db_host'] ?? ''),
                'port' => (int) $port,
                'name' => trim($values['db_name'] ?? ''),
                'user' => trim($values['db_user'] ?? ''),
                'password' => $password !== '' ? $password : ($current !== null && $current->database !== null ? $current->database->password : ''),
                'prefix' => trim($values['db_prefix'] ?? ''),
                'driver' => $driver,
            ];
        }

        return ['data' => $data, 'errors' => $errors];
    }

    /**
     * @return list<string>
     */
    private static function lines(string $text): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", $text)), static fn (string $line): bool => $line !== ''));
    }

    private static function formatSize(int $bytes): string
    {
        foreach (['G' => 1073741824, 'M' => 1048576] as $unit => $size) {
            if ($bytes % $size === 0) {
                return ($bytes / $size) . $unit;
            }
        }

        return (string) $bytes;
    }
}
