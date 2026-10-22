<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Backup;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Backup\Sidecar;
use ThreadSafe\Tests\Support\BackupFixture;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * The two optional marks of a sidecar file, `pinned` and `source`: written only when set, read when present, and a file from before
 * phase 5 reads as it did.
 */
final class SidecarMarksTest extends TestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    private function sidecar(): Sidecar
    {
        $fixture = new BackupFixture($this->tempDirectory());
        $name = $fixture->backup();

        return $fixture->storage()->readSidecar($name);
    }

    public function testNeitherMarkIsWrittenUnlessSet(): void
    {
        $plain = new Sidecar($this->sidecar()->manifest, $this->sidecar()->parts, $this->sidecar()->status);

        $data = $plain->toArray();

        self::assertArrayNotHasKey('pinned', $data);
        self::assertArrayNotHasKey('source', $data);
        self::assertFalse(Sidecar::fromJson($plain->toJson())->pinned);
        self::assertSame('', Sidecar::fromJson($plain->toJson())->source);
    }

    public function testThePinAndTheSourceSurviveAWriteAndRead(): void
    {
        $sidecar = $this->sidecar();
        $marked = (new Sidecar($sidecar->manifest, $sidecar->parts, $sidecar->status, [], false, 'schedule'))->withPinned(true);

        $read = Sidecar::fromJson($marked->toJson());

        self::assertTrue($read->pinned);
        self::assertSame('schedule', $read->source);
        self::assertTrue($read->withStatus($read->status)->pinned, 'a copy keeps the marks');
        self::assertSame('schedule', $read->withTransfer(['target' => 'x', 'time' => 't', 'success' => true, 'message' => ''])->source);
        self::assertFalse($read->withPinned(false)->pinned);
    }

    public function testAnUnknownSourceReadsAsNone(): void
    {
        $data = $this->sidecar()->toArray();
        $data['source'] = 'carrier pigeon';
        $data['pinned'] = 'yes';

        $read = Sidecar::fromJson((string) json_encode($data));

        self::assertSame('', $read->source);
        self::assertFalse($read->pinned, 'only true is a pin');
    }

    public function testABackupRecordsWhoAskedForIt(): void
    {
        $fixture = new BackupFixture($this->tempDirectory());

        self::assertSame('manual', $fixture->storage()->readSidecar($fixture->backup())->source);
        self::assertSame('schedule', $fixture->storage()->readSidecar($fixture->backup(['source' => 'schedule']))->source);
        self::assertSame('manual', $fixture->storage()->readSidecar($fixture->backup(['source' => 'whatever']))->source);
    }
}
