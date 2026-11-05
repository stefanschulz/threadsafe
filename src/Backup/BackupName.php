<?php

declare(strict_types=1);

namespace ThreadSafe\Backup;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use ThreadSafe\Profile\SiteProfile;

/**
 * The name of a backup, from which all its file names follow:
 *
 * ```
 * <profile>_<YYYYMMDD-HHMMSS>_<random8>.zip         one archive
 * <profile>_<YYYYMMDD-HHMMSS>_<random8>-p01.zip     the parts of a split archive (-p02, ...)
 * <profile>_<YYYYMMDD-HHMMSS>_<random8>.zip.age        an encrypted part (the `age` format around the ZIP part; -p01.zip.age ...)
 * <profile>_<YYYYMMDD-HHMMSS>_<random8>.json        the sidecar file
 * ```
 *
 * The time is UTC. The random part makes the name unguessable and unique within a second. A name is built only from the
 * profile id (validated) and generated characters, never from anything else.
 */
final readonly class BackupName
{
    private const string PATTERN = '/^(?<profile>[a-z0-9][a-z0-9_-]{0,39})_(?<time>\d{8}-\d{6})_(?<random>[0-9a-f]{8})$/';

    private const string FILE_PATTERN = '/^(?<base>.+?)(?:-p(?<part>\d{2,}))?\.(?<extension>zip\.age|zip|json)$/';

    private function __construct(public string $profile, public DateTimeImmutable $time, public string $random)
    {
    }

    public static function create(string $profileId, DateTimeImmutable $now, ?string $random = null): self
    {
        if (preg_match(SiteProfile::ID_PATTERN, $profileId) !== 1) {
            throw new InvalidArgumentException("\"$profileId\" is not a valid profile id.");
        }
        $random ??= bin2hex(random_bytes(4));
        if (preg_match('/^[0-9a-f]{8}$/D', $random) !== 1) {
            throw new InvalidArgumentException('The random part of a backup name is 8 hexadecimal digits.');
        }

        return new self($profileId, $now->setTimezone(new DateTimeZone('UTC')), $random);
    }

    /** The name from `<profile>_<time>_<random>`; null if the text is not one. */
    public static function parse(string $base): ?self
    {
        if (preg_match(self::PATTERN, $base, $m) !== 1) {
            return null;
        }
        $time = DateTimeImmutable::createFromFormat('!Ymd-His', $m['time'], new DateTimeZone('UTC'));

        // createFromFormat rolls "month 13" over into the next year: only a time that reads back the same is a time
        return $time === false || $time->format('Ymd-His') !== $m['time'] ? null : new self($m['profile'], $time, $m['random']);
    }

    /**
     * Which backup a file in the storage directory belongs to.
     *
     * @return array{name: self, part: int|null, extension: string}|null null for a file that is not part of a backup
     */
    public static function fromFile(string $fileName): ?array
    {
        if (preg_match(self::FILE_PATTERN, $fileName, $m) !== 1) {
            return null;
        }
        $name = self::parse($m['base']);
        if ($name === null) {
            return null;
        }
        $part = $m['part'] === '' ? null : (int) $m['part'];
        if ($part !== null && ($part < 1 || $m['extension'] === 'json')) {
            return null;
        }

        return ['name' => $name, 'part' => $part, 'extension' => $m['extension']];
    }

    /** `shop_20261020-123456_ab12cd34` */
    public function base(): string
    {
        return $this->profile . '_' . $this->time->format('Ymd-His') . '_' . $this->random;
    }

    /**
     * The file name of a part: a backup that is split always numbers its parts, even if it ends up with one.
     *
     * @param bool $sealed the name of the encrypted part (`.zip.age`) instead of the ZIP part itself
     */
    public function partFile(int $index, bool $split, bool $sealed = false): string
    {
        if ($index < 1) {
            throw new InvalidArgumentException('Parts are numbered from 1.');
        }

        return $this->base() . ($split ? sprintf('-p%02d', $index) : '') . ($sealed ? '.zip.age' : '.zip');
    }

    public function sidecarFile(): string
    {
        return $this->base() . '.json';
    }
}
