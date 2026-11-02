<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Web;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Archive\ZipReader;
use ThreadSafe\Backup\BackupName;
use ThreadSafe\Backup\BackupRun;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Engine\Executor;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Profile\ProfileException;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Web\BackupTarget;
use ThreadSafe\Web\BackupWorkspace;

/**
 * Which directory the backup tool may back up, what it leaves out of the archive (itself), and that the engine runs on the profile it makes.
 */
final class BackupTargetTest extends TestCase
{
    use TempDirectory;

    private string $site;

    protected function setUp(): void
    {
        $this->site = $this->tempDirectory() . '/site';
        mkdir($this->site . '/tools', 0777, true);
        mkdir($this->site . '/other');
        file_put_contents($this->site . '/index.php', '<?php echo 1;');
        file_put_contents($this->site . '/other/page.php', '<?php echo 2;');
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    public function testTheDirectoryOfTheToolAndTheOnesAboveItMayBeBackedUp(): void
    {
        $target = new BackupTarget($this->site . '/tools');

        self::assertNull($target->problem($this->site . '/tools'));
        self::assertNull($target->problem($this->site));
        self::assertNull($target->problem($this->site . '/tools/../../site'), 'a path that leads back to the directory is the directory');
        self::assertSame(realpath($this->site . '/tools'), $target->defaultRoot());
    }

    public function testNoOtherDirectoryMay(): void
    {
        $target = new BackupTarget($this->site . '/tools');
        mkdir(dirname($this->site) . '/elsewhere');

        foreach ([$this->site . '/other', dirname($this->site) . '/elsewhere'] as $path) {
            self::assertStringContainsString('or one above it', (string) $target->problem($path), $path);
        }
        self::assertStringContainsString('no such directory', (string) $target->problem($this->site . '/missing'));
    }

    public function testAToolWhoseDirectoryIsASymlinkIsMeasuredByTheRealPath(): void
    {
        $link = dirname($this->site) . '/link';
        symlink($this->site . '/other', $link);
        $target = new BackupTarget($link);

        self::assertNull($target->problem($this->site . '/other'));
        self::assertNull($target->problem($this->site));
        self::assertNotNull($target->problem($this->site . '/tools'));
    }

    public function testResolveReturnsTheRealPathOrSaysWhy(): void
    {
        $target = new BackupTarget($this->site . '/tools');

        self::assertSame(realpath($this->site), $target->resolve($this->site . '/tools/..'));
        $this->expectException(ProfileException::class);
        $this->expectExceptionMessage('or one above it');
        $target->resolve($this->site . '/other');
    }

    public function testTheOpenBasedirIsRespected(): void
    {
        $target = new BackupTarget($this->site . '/tools');

        self::assertStringContainsString('open_basedir', (string) $target->problem($this->site, '/nowhere'));
    }

    public function testSelfExcludesForAToolAtTheRootOfTheSite(): void
    {
        $target = new BackupTarget($this->site);

        self::assertSame(
            ['/threadsafe-backup.php', '/threadsafe-backup-key.php', '/.threadsafe-backup/', '/.threadsafe-restore/'],
            $target->selfExcludes($this->site),
        );
    }

    public function testSelfExcludesForAToolBelowTheRootNameItsOwnPlace(): void
    {
        $target = new BackupTarget($this->site . '/tools');

        self::assertSame(
            ['/tools/threadsafe-backup.php', '/tools/threadsafe-backup-key.php', '/tools/.threadsafe-backup/', '/tools/.threadsafe-restore/'],
            $target->selfExcludes($this->site),
        );
        self::assertSame([], $target->selfExcludes($this->site . '/other'), 'a directory the tool is not in has nothing of the tool in it');
    }

    public function testAFileNameWithAPatternCharacterIsWrittenSoThatItMatchesOnlyItself(): void
    {
        $target = new BackupTarget($this->site, 'tool*[1]?.php', 'key\\file.php');

        self::assertSame('/tool?[1]?.php', $target->selfExcludes($this->site)[0]);
        self::assertSame('/key?file.php', $target->selfExcludes($this->site)[1]);
    }

    public function testTheProfileIsTheOneSiteWithItsStorageAndTheDefaults(): void
    {
        $target = new BackupTarget($this->site . '/tools');
        $profile = $target->profile($this->site, 'generic', '/storage/here', exclude: ['cache/', '*.log']);

        self::assertSame('site', $profile->id);
        self::assertSame('generic', $profile->type);
        self::assertSame($this->site, $profile->path);
        self::assertSame('/storage/here', $profile->storage);
        self::assertNull($profile->database, 'read from the application');
        self::assertSame(BackupTarget::PART_SIZE, $profile->partSize);
        self::assertSame(['/tools/threadsafe-backup.php', '/tools/threadsafe-backup-key.php', '/tools/.threadsafe-backup/', '/tools/.threadsafe-restore/', 'cache/', '*.log'], $profile->excludePaths);
        self::assertNull($profile->encryption);
    }

    public function testAPatternThatIsNotValidIsRefused(): void
    {
        $this->expectException(ProfileException::class);
        (new BackupTarget($this->site))->profile($this->site, 'generic', '/storage', exclude: ['../outside']);
    }

    public function testTheEngineRunsOnTheProfileAndLeavesTheToolOutOfTheArchive(): void
    {
        file_put_contents($this->site . '/tools/threadsafe-backup.php', '<?php // the tool');
        file_put_contents($this->site . '/tools/threadsafe-backup-key.php', "<?php return 'secret';");
        file_put_contents($this->site . '/threadsafe-backup.php', '<?php // another file of that name, at the root');
        mkdir($this->site . '/tools/.threadsafe-restore');
        file_put_contents($this->site . '/tools/.threadsafe-restore/state.json', '{}');
        mkdir($this->site . '/.threadsafe-restore');
        file_put_contents($this->site . '/.threadsafe-restore/state.json', '{}');
        $workspace = BackupWorkspace::in($this->site . '/tools');
        $target = new BackupTarget($this->site . '/tools');
        $workspace->profiles()->save($target->profile($target->resolve($this->site), 'generic', $workspace->storage()));
        file_put_contents($workspace->directory() . '/bundle-1.php', '<?php // the unpacked program');

        $task = new BackupRun($workspace->profiles(), PluginRegistry::load(), $workspace->data());
        $executor = new Executor($workspace->runs(), $task, static fn (): Budget => Budget::unlimited());
        $run = $executor->execute($executor->start(['profile' => 'site'])->id);

        $result = $run->state['result'] ?? null;
        self::assertIsArray($result, 'the backup finished');
        $backup = BackupName::parse((string) $result['backup']);
        self::assertNotNull($backup);
        $reader = ZipReader::open($workspace->storage() . '/' . $backup->partFile(1, true));
        $inside = [];
        foreach ($reader->entries() as $entry) {
            $inside[] = $entry->name;
        }

        self::assertContains('files/index.php', $inside);
        self::assertContains('files/other/page.php', $inside);
        self::assertContains('files/threadsafe-backup.php', $inside, "a file of that name elsewhere is the site's");
        self::assertContains('files/.threadsafe-restore/state.json', $inside, 'the restore directory of the site, not next to the tool, is the site\'s');
        foreach ($inside as $name) {
            self::assertStringNotContainsString('tools/threadsafe-backup', $name);
            self::assertStringNotContainsString('.threadsafe-backup', $name);
            self::assertStringNotContainsString('tools/.threadsafe-restore', $name);
        }
    }
}
