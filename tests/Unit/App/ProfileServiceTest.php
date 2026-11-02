<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\App;

use PHPUnit\Framework\TestCase;
use ThreadSafe\App\ProfileService;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Profile\ProfileException;
use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Profile\SiteProfile;
use ThreadSafe\Schedule\ScheduleState;
use ThreadSafe\Schedule\ScheduleStore;
use ThreadSafe\Tests\Support\JoomlaSite;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * What both front ends do with profiles, without either of them.
 */
final class ProfileServiceTest extends TestCase
{
    use TempDirectory;

    private string $base;

    private ProfileService $service;

    protected function setUp(): void
    {
        $this->base = $this->tempDirectory();
        mkdir($this->base . '/site', 0777, true);
        file_put_contents($this->base . '/site/index.php', '<?php echo 1;');
        $this->service = new ProfileService(new ProfileStore($this->base . '/data/profiles'), PluginRegistry::load(), $this->base . '/data');
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    private function profile(string $id = 'blog', ?string $storage = null): SiteProfile
    {
        return SiteProfile::fromArray(['id' => $id, 'type' => 'generic', 'path' => $this->base . '/site', 'storage' => $storage ?? $this->base . '/storage/' . $id]);
    }

    private function states(): ScheduleStore
    {
        return new ScheduleStore($this->base . '/data/schedule');
    }

    public function testASchedulePutsTheStateOfTheSchedulerAtNowAndAChangeMovesItAndRemovalClearsIt(): void
    {
        self::assertTrue($this->service->store($this->profile()->with(['schedule' => ['every' => 'day', 'at' => '03:00']]), test: false)->stored);
        $first = $this->states()->load('blog');
        self::assertNotNull($first, 'a profile with a schedule has a state');
        self::assertEqualsWithDelta(time(), $first->since, 5, 'its schedule counts from now');

        // the same expression again (any other edit): the state stays as the scheduler left it
        $this->states()->save('blog', new ScheduleState(1000, 2000, ScheduleState::DONE, 1));
        $this->service->store($this->profile()->with(['schedule' => ['every' => 'day', 'at' => '03:00'], 'note' => 'changed']), test: false);
        self::assertEquals(new ScheduleState(1000, 2000, ScheduleState::DONE, 1), $this->states()->load('blog'));

        // another expression: instants before the change do not count
        $this->service->store($this->profile()->with(['schedule' => ['every' => 'day', 'at' => '04:00']]), test: false);
        self::assertEqualsWithDelta(time(), $this->states()->load('blog')?->since, 5);
        self::assertNull($this->states()->load('blog')?->instant);

        // no schedule any more
        $this->service->store($this->profile(), test: false);
        self::assertNull($this->states()->load('blog'));

        $this->service->store($this->profile()->with(['schedule' => ['every' => 'day', 'at' => '03:00']]), test: false);
        self::assertNotNull($this->states()->load('blog'));
        $this->service->remove('blog');
        self::assertNull($this->states()->load('blog'), 'a removed profile leaves no state');
    }

    public function testPausingAndResumingMoveTheStateOfTheSchedulerButASaveWithoutAChangeDoesNot(): void
    {
        $day = ['every' => 'day', 'at' => '03:00'];
        $this->service->store($this->profile()->with(['schedule' => $day]), test: false);
        $this->states()->save('blog', new ScheduleState(1000, 2000, ScheduleState::DONE, 1));

        $this->service->store($this->profile()->with(['schedule' => $day, 'note' => 'again']), test: false);
        self::assertEquals(new ScheduleState(1000, 2000, ScheduleState::DONE, 1), $this->states()->load('blog'), 'nothing changed');

        $this->service->store($this->profile()->with(['schedule' => $day + ['paused' => true]]), test: false);
        self::assertNotNull($this->states()->load('blog'), 'the state is kept while it is paused');

        $this->states()->save('blog', new ScheduleState(1000, 2000, ScheduleState::DONE, 1));
        $this->service->store($this->profile()->with(['schedule' => $day]), test: false);
        self::assertEqualsWithDelta(time(), $this->states()->load('blog')?->since, 5, 'resumed: it counts from now, nothing is made up');
    }

    public function testAProfileWithoutAScheduleLeavesNoStateFile(): void
    {
        $this->service->store($this->profile(), test: false);

        self::assertDirectoryDoesNotExist($this->base . '/data/schedule');
    }

    public function testTheTypesThatCanBeBackedUpAreListedWithTheirTitles(): void
    {
        $types = $this->service->types();

        self::assertArrayHasKey('generic', $types);
        self::assertArrayHasKey('prestashop9', $types);
        self::assertNotSame('', $types['generic']);
    }

    public function testAProfileThatPassesItsTestIsStoredAndListed(): void
    {
        $result = $this->service->store($this->profile());

        self::assertTrue($result->stored);
        self::assertNotNull($result->report);
        self::assertTrue($result->report->passed());
        self::assertFalse($result->rejectedByTest());
        self::assertSame(['blog'], array_map(static fn (SiteProfile $p): string => $p->id, $this->service->listing()['profiles']));
        self::assertTrue($this->service->exists('blog'));
        self::assertSame('blog', $this->service->load('blog')->id);
    }

    public function testAProfileThatFailsItsTestIsNotStoredUnlessTheTestIsSkipped(): void
    {
        file_put_contents($this->base . '/a-file', 'x');
        $bad = $this->profile('blog', $this->base . '/a-file/storage');

        $rejected = $this->service->store($bad);

        self::assertFalse($rejected->stored);
        self::assertTrue($rejected->rejectedByTest());
        self::assertNotNull($rejected->report);
        self::assertFalse($rejected->report->passed());
        self::assertFalse($this->service->exists('blog'));

        $forced = $this->service->store($bad, test: false);
        self::assertTrue($forced->stored);
        self::assertNull($forced->report);
        self::assertTrue($this->service->exists('blog'));
    }

    public function testAFileThatCannotBeWrittenIsAnErrorNotAnException(): void
    {
        file_put_contents($this->base . '/data', 'a file where the data directory should be');

        $result = $this->service->store($this->profile(), test: false);

        self::assertFalse($result->stored);
        self::assertFalse($result->rejectedByTest());
        self::assertNotNull($result->error);
    }

    public function testDamagedFilesAreReportedNextToTheGoodOnes(): void
    {
        $this->service->store($this->profile('good'), test: false);
        file_put_contents($this->base . '/data/profiles/broken.json', '{ nope');

        $listing = $this->service->listing();

        self::assertCount(1, $listing['profiles']);
        self::assertSame(['broken'], $listing['damaged']);
    }

    public function testRemovingAProfileLeavesTheBackupsAlone(): void
    {
        mkdir($this->base . '/storage/blog', 0777, true);
        file_put_contents($this->base . '/storage/blog/blog_20261021.zip', 'archive');
        $this->service->store($this->profile(), test: false);

        $this->service->remove('blog');

        self::assertFalse($this->service->exists('blog'));
        self::assertFileExists($this->base . '/storage/blog/blog_20261021.zip');
        $this->expectException(ProfileException::class);
        $this->service->remove('blog');
    }

    public function testAnIdMustBeValidAndFree(): void
    {
        $this->service->store($this->profile(), test: false);

        foreach (['Blog', '../x', '', str_repeat('a', 41)] as $id) {
            try {
                $this->service->assertFreeId($id);
                self::fail("\"$id\" should be refused");
            } catch (ProfileException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
        try {
            $this->service->assertFreeId('blog');
            self::fail('a taken id should be refused');
        } catch (ProfileException $e) {
            self::assertStringContainsString('exists already', $e->getMessage());
        }
        $this->service->assertFreeId('shop');
        $this->addToAssertionCount(1);
    }

    public function testASiteIsLookedAtWithoutStoringAnything(): void
    {
        $detection = $this->service->detect('generic', $this->base . '/site');

        self::assertSame(realpath($this->base . '/site'), $detection->root);
        self::assertNotSame('', $detection->site->describe());
        self::assertNull($detection->database, 'the generic type reads no database from a site');
        self::assertSame([], $this->service->listing()['profiles']);
    }

    public function testLookingAtSomethingThatIsNotASiteSaysWhy(): void
    {
        foreach ([['generic', $this->base . '/nowhere', 'not a readable directory'], ['nonsense', $this->base . '/site', 'nonsense'], ['prestashop9', $this->base . '/site', 'not an installation']] as [$type, $path, $message]) {
            try {
                $this->service->detect($type, $path);
                self::fail("$type $path should be refused");
            } catch (ProfileException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function testASiteIsRecognisedByItsTypeAndAnythingElseIsAnyWebsite(): void
    {
        JoomlaSite::build($this->base . '/joomla');

        [$type, $detection] = $this->service->autodetect($this->base . '/joomla');
        self::assertSame('joomla', $type);
        self::assertStringContainsString('Joomla', $detection->site->describe());
        self::assertNotNull($detection->database, 'read from the configuration of the site');

        [$type, $detection] = $this->service->autodetect($this->base . '/site');
        self::assertSame('generic', $type);
        self::assertNull($detection->database);
    }

    public function testASiteTheTypeRecognisesButCannotReadIsAnErrorNotAnyWebsite(): void
    {
        JoomlaSite::build($this->base . '/joomla');
        unlink($this->base . '/joomla/configuration.php');
        file_put_contents($this->base . '/joomla/configuration.php', '<?php // not a configuration');

        $this->expectException(ProfileException::class);
        $this->service->autodetect($this->base . '/joomla');
    }

    public function testAutodetectSaysWhyADirectoryCannotBeRead(): void
    {
        $this->expectException(ProfileException::class);
        $this->expectExceptionMessage('not a readable directory');
        $this->service->autodetect($this->base . '/nowhere');
    }
}
