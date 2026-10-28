<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Profile;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Profile\CheckLevel;
use ThreadSafe\Profile\ConnectionTest;
use ThreadSafe\Profile\SiteProfile;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * The check of the storage directory in the test of a profile, in particular on a host that lets PHP use only some directories (open_basedir).
 */
final class ConnectionTestStorageTest extends TestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    /**
     * @return list<\ThreadSafe\Profile\Check>
     */
    private function storage(string $storage, ?string $openBasedir): array
    {
        $base = $this->tempDirectory();
        $profile = SiteProfile::fromArray(['id' => 'blog', 'name' => 'Blog', 'type' => 'generic', 'path' => $base, 'storage' => $storage]);

        return (new ConnectionTest(PluginRegistry::load(), $base . '/data'))->storage($profile, null, $openBasedir);
    }

    public function testAStorageOutsideOpenBasedirIsNamedAsThatAndNothingElse(): void
    {
        $checks = $this->storage('/srv/other/backups/blog', '/home/u/web/site:/tmp');

        self::assertCount(1, $checks);
        self::assertSame(CheckLevel::Failure, $checks[0]->level);
        self::assertStringContainsString('"/srv/other/backups/blog" is outside the directories that this host lets PHP use', $checks[0]->message);
        self::assertStringContainsString('open_basedir: /home/u/web/site, /tmp', $checks[0]->message);
        self::assertStringContainsString('a "private" directory next to the document root', $checks[0]->message);
        self::assertStringNotContainsString('is not a directory', $checks[0]->message, 'not the misleading message about "/"');
    }

    public function testAStorageInsideAnAllowedDirectoryCanBeMade(): void
    {
        $allowed = $this->tempDirectory();

        $checks = $this->storage($allowed . '/private/backups/blog', $allowed);

        self::assertSame(CheckLevel::Ok, $checks[0]->level);
        self::assertStringContainsString('does not exist yet and can be created', $checks[0]->message);
    }

    public function testWithoutARestrictionTheCheckIsAsItWas(): void
    {
        $allowed = $this->tempDirectory();

        $checks = $this->storage($allowed . '/backups/blog', '');

        self::assertSame(CheckLevel::Ok, $checks[0]->level);
    }

    public function testOpenBasedirIsANoteAndNeverAWarningBecauseThePathsAreCheckedAgainstItAbove(): void
    {
        // field test: "open_basedir is set (...); the site path and the storage must be inside it." came with every backup, although they were
        $base = $this->tempDirectory();
        $test = new ConnectionTest(PluginRegistry::load(), $base . '/data');

        $restricted = array_filter($test->environment(false, $base . ':/tmp'), static fn ($check): bool => str_contains($check->message, 'open_basedir'));
        $free = array_filter($test->environment(false, ''), static fn ($check): bool => str_contains($check->message, 'open_basedir'));

        self::assertCount(1, $restricted);
        self::assertSame(CheckLevel::Info, array_values($restricted)[0]->level, 'no warning: it would be repeated at the end of every backup');
        self::assertSame([], $free, 'without a restriction there is nothing to say');
        self::assertSame([], array_filter($test->environment(false, $base), static fn ($check): bool => $check->level === CheckLevel::Warning && $check->subject === 'PHP'));
    }}
