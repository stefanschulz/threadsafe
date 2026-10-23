<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Backup;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Archive\ZipWriter;
use ThreadSafe\Backup\BackupStatus;
use ThreadSafe\Backup\Manifest;
use ThreadSafe\Backup\ManifestException;
use ThreadSafe\Backup\PartMarker;
use ThreadSafe\Backup\PartRecord;
use ThreadSafe\Backup\Sidecar;
use ThreadSafe\Tests\Support\TempDirectory;

final class ManifestTest extends TestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    public static function sample(string $id = 'shop_20261020-123456_ab12cd34'): Manifest
    {
        $utc = new DateTimeZone('UTC');

        return new Manifest(
            $id,
            '0.0.0-dev',
            'shop',
            'Webshop (live) ü',
            'prestashop9',
            ['name' => 'PrestaShop', 'version' => '9.0.2'],
            '8.4.2',
            ['server' => 'mariadb', 'version' => '10.11.6', 'driver' => 'pdo', 'name' => 'shop', 'prefix' => 'ps_', 'charset' => 'utf8mb4', 'collations' => ['utf8mb4_general_ci']],
            'snapshot',
            ['adminDirectory' => 'admin-x', 'shopUrls' => [['domain' => 'shop.example', 'main' => true]]],
            ['excludePaths' => ['var/cache'], 'structureOnlyTables' => ['log'], 'literalExcludes' => ['var/backups']],
            ['files' => 10, 'directories' => 3, 'fileBytes' => 1234, 'tables' => 299, 'rows' => 5000, 'databaseBytes' => 99999],
            [['file' => $id . '.zip', 'entries' => 400]],
            new DateTimeImmutable('2026-10-20 12:34:56', $utc),
            new DateTimeImmutable('2026-10-20 12:40:00', $utc),
            ['A cell is big.'],
            [['type' => 'function', 'name' => 'f_one']],
        );
    }

    public function testRoundTrip(): void
    {
        $manifest = self::sample();
        $again = Manifest::fromJson($manifest->toJson());

        self::assertSame($manifest->toArray(), $again->toArray());
        self::assertSame(304, $again->duration());
        self::assertSame('shop', $again->name()?->profile);
        self::assertStringStartsWith("{\n    \"format\": 1,", $manifest->toJson());
        self::assertSame("Webshop (live) ü", $again->profileName);
    }

    public function testABackupFromBeforeTheRenamingOfTheGenericTypeShowsTheNewName(): void
    {
        $data = self::sample()->toArray();
        $data['application'] = ['name' => 'Any website', 'version' => null];

        $manifest = Manifest::fromArray($data);

        self::assertSame('Other website', $manifest->application['name'], 'the old label is read as the new one');
        self::assertSame('Other website', Manifest::fromJson($manifest->toJson())->application['name']);
        $data['application'] = ['name' => 'PrestaShop', 'version' => '9.0.2'];
        self::assertSame('PrestaShop', Manifest::fromArray($data)->application['name'], 'other names are left alone');
    }

    public function testNoApplicationVersionIsAllowed(): void
    {
        $data = self::sample()->toArray();
        $data['application']['version'] = null;
        $data['database'] = [];

        $manifest = Manifest::fromArray($data);

        self::assertNull($manifest->application['version']);
        self::assertSame([], $manifest->database);
    }

    public function testWithParts(): void
    {
        $manifest = self::sample()->withParts([['file' => 'a-p01.zip', 'entries' => 1], ['file' => 'a-p02.zip', 'entries' => 2]]);

        self::assertSame(['a-p01.zip', 'a-p02.zip'], array_column($manifest->parts, 'file'));
        self::assertSame('shop', $manifest->profileId);
    }

    /** @return iterable<string, array{callable(array<string, mixed>): array<string, mixed>}> */
    public static function damage(): iterable
    {
        yield 'wrong format' => [static fn (array $d): array => [...$d, 'format' => 2]];
        yield 'no format' => [static fn (array $d): array => array_diff_key($d, ['format' => 1])];
        yield 'no backup id' => [static fn (array $d): array => array_diff_key($d, ['backupId' => 1])];
        yield 'no profile' => [static fn (array $d): array => array_diff_key($d, ['profile' => 1])];
        yield 'counts not numbers' => [static fn (array $d): array => [...$d, 'counts' => [...$d['counts'], 'files' => '10']]];
        yield 'bad time' => [static fn (array $d): array => [...$d, 'started' => 'yesterday']];
        yield 'filters not strings' => [static fn (array $d): array => [...$d, 'filters' => [...$d['filters'], 'excludePaths' => [5]]]];
        yield 'bad part' => [static fn (array $d): array => [...$d, 'parts' => [['file' => 'x']]]];
        yield 'bad unsupported' => [static fn (array $d): array => [...$d, 'unsupportedObjects' => [['type' => 'x']]]];
        yield 'warnings not a list' => [static fn (array $d): array => [...$d, 'warnings' => 'no']];
        yield 'version a number' => [static fn (array $d): array => [...$d, 'application' => ['name' => 'x', 'version' => 9]]];
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $damage
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('damage')]
    public function testDamagedManifestsAreRefused(callable $damage): void
    {
        $this->expectException(ManifestException::class);

        Manifest::fromArray($damage(self::sample()->toArray()));
    }

    public function testNotJson(): void
    {
        foreach (['{nope', '[1,2]', '"text"', ''] as $json) {
            try {
                Manifest::fromJson($json);
                self::fail("Accepted $json");
            } catch (ManifestException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testTheManifestHoldsNoSecretAndNoServerPath(): void
    {
        $json = self::sample()->toJson();

        foreach (['password', 'host', 'user', '/var/www'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $json);
        }
    }

    public function testSidecarRoundTrip(): void
    {
        $sidecar = new Sidecar(
            self::sample(),
            [new PartRecord('a-p01.zip', 100, str_repeat('a', 64), 3), new PartRecord('a-p02.zip', 50, str_repeat('b', 64), 4)],
            BackupStatus::OkWithWarnings,
        );
        $sidecar = $sidecar->withTransfer(['target' => 'sftp:x', 'time' => '2026-10-20T13:00:00Z', 'success' => false, 'message' => 'refused']);

        $again = Sidecar::fromJson($sidecar->toJson());

        self::assertSame($sidecar->toArray(), $again->toArray());
        self::assertSame(150, $again->size());
        self::assertSame(BackupStatus::OkWithWarnings, $again->status);
        self::assertSame('refused', $again->transfers[0]['message']);
        self::assertSame(BackupStatus::Faulty, $again->withStatus(BackupStatus::Faulty)->status);
    }

    public function testDamagedSidecarsAreRefused(): void
    {
        $good = (new Sidecar(self::sample(), [new PartRecord('a.zip', 1, str_repeat('a', 64), 1)], BackupStatus::Ok))->toArray();

        foreach ([
            [...$good, 'format' => 3],
            [...$good, 'status' => 'great'],
            [...$good, 'parts' => [['file' => 'a.zip', 'size' => 1, 'sha256' => 'short', 'entries' => 1]]],
            [...$good, 'parts' => 'none'],
            [...$good, 'transfers' => [['target' => 'x']]],
            [...$good, 'manifest' => 'x'],
            array_diff_key($good, ['manifest' => 1]),
        ] as $data) {
            try {
                Sidecar::fromJson((string) json_encode($data));
                self::fail('Accepted ' . json_encode(array_keys($data)));
            } catch (ManifestException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testManifestIsReadFromTheLastPartOfAnArchive(): void
    {
        $path = $this->tempDirectory() . '/a.zip';
        $writer = ZipWriter::create($path);
        $writer->addString('files/index.php', '<?php', 1_700_000_000);
        $writer->addString(PartMarker::ENTRY, (new PartMarker('shop_20261020-123456_ab12cd34', 1, false))->toJson(), 1_700_000_000);
        $writer->addString(Manifest::ENTRY, self::sample()->toJson(), 1_700_000_000);
        $writer->close();

        self::assertSame(self::sample()->toArray(), Manifest::fromArchive($path)->toArray());
    }

    public function testAPartWithoutManifestAndAnythingThatIsNoArchive(): void
    {
        $path = $this->tempDirectory() . '/a.zip';
        $writer = ZipWriter::create($path);
        $writer->addString('x', 'y', 1_700_000_000);
        $writer->close();
        file_put_contents($this->tempDirectory() . '/b.zip', 'not a zip');

        foreach ([$path, dirname($path) . '/missing.zip'] as $file) {
            try {
                Manifest::fromArchive($file);
                self::fail("Read $file");
            } catch (ManifestException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testPartMarker(): void
    {
        $marker = PartMarker::fromJson((new PartMarker('shop_20261020-123456_ab12cd34', 2, true))->toJson());

        self::assertSame(['shop_20261020-123456_ab12cd34', 2, true], [$marker->backupId, $marker->index, $marker->split]);
        $this->expectException(ManifestException::class);

        PartMarker::fromJson('{"format":1,"backupId":"x","part":0,"split":false}');
    }
}
