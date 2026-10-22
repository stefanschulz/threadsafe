<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Restore;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Crypto\AgeKeys;
use ThreadSafe\Restore\BackupSource;
use ThreadSafe\Restore\RestoreException;
use ThreadSafe\Tests\Support\BackupFixture;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * Until the restore reads encrypted backups (work package 7), it must say plainly what the file is and what to do, not that the name is wrong.
 */
final class EncryptedSourceTest extends TestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    public function testAnEncryptedPartIsRefusedWithAMessageThatSaysSo(): void
    {
        $fixture = new BackupFixture($this->tempDirectory());
        $fixture->profiles->save($fixture->profiles->load('blog')->with(['encryption' => ['recipients' => [AgeKeys::generate()['public']]]]));
        $name = $fixture->backup();

        try {
            BackupSource::open($fixture->storageDirectory . '/' . $name->base() . '.zip.age');
            self::fail('an encrypted part cannot be restored yet');
        } catch (RestoreException $e) {
            self::assertStringContainsString('is encrypted', $e->getMessage());
            self::assertStringContainsString('age --decrypt', $e->getMessage());
        }
    }
}
