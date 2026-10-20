<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Integration;

use ThreadSafe\Core\Context;
use ThreadSafe\Core\FileJournal;
use ThreadSafe\Core\NoticeLevel;
use ThreadSafe\Core\NullJournal;
use ThreadSafe\Core\Plan;
use ThreadSafe\Core\Step;
use ThreadSafe\Database\DatabaseSettings;
use ThreadSafe\Migration\MigrationRequest;
use ThreadSafe\PrestaShop9\ParametersFile;
use ThreadSafe\PrestaShop9\Steps\EnsureSkeleton;
use ThreadSafe\PrestaShop9\Steps\ReadSourceState;
use ThreadSafe\PrestaShop9\Steps\RewriteHostBoundFiles;
use ThreadSafe\PrestaShop9\Steps\RewriteHtaccess;
use ThreadSafe\PrestaShop9\Steps\WriteParameters;
use ThreadSafe\Replace\DomainMapping;
use ThreadSafe\Tests\Support\ScratchShop;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * The steps that change files, against a fake shop (the same fixtures as the database steps).
 */
final class FileStepsTest extends IntegrationTestCase
{
    use TempDirectory;

    private ScratchShop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = ScratchShop::create('old.test');
    }

    protected function tearDown(): void
    {
        $this->shop->destroy();
        $this->cleanUpTempDirectories();
    }

    private function context(MigrationRequest $request, bool $dryRun = true): Context
    {
        $context = $this->shop->context($request, $dryRun ? new NullJournal() : new FileJournal($this->tempDirectory()), $dryRun);
        (new ReadSourceState())->plan($context);

        return $context;
    }

    private function apply(Step $step, MigrationRequest $request): int
    {
        $context = $this->context($request, dryRun: false);

        return $step->apply($context, $step->plan($context))->applied;
    }

    private function plan(Step $step, MigrationRequest $request): Plan
    {
        return $step->plan($this->context($request));
    }

    private function moved(?DatabaseSettings $database = null): MigrationRequest
    {
        return new MigrationRequest($this->shop->root, [new DomainMapping('old.test', 'new.test')], database: $database);
    }

    // ---------------------------------------------------------------------------------------------- WriteParameters

    private function otherDatabase(): DatabaseSettings
    {
        return new DatabaseSettings('db', 3306, $this->shop->settings->name, 'root', $this->shop->settings->password, 'ps_');
    }

    public function testParametersNeedNoChangeWhenTheDatabaseIsTheConfiguredOne(): void
    {
        self::assertFalse($this->plan(new WriteParameters(), $this->moved())->hasChanges());
    }

    public function testParametersArePointedToTheNewDatabase(): void
    {
        // The request names another user and password than the file; both reach the same scratch database as root.
        $database = new DatabaseSettings('db', 3306, $this->shop->settings->name, 'root', $this->shop->settings->password, 'ps_');
        $this->shop->writeFile('app/config/parameters.php', '<?php return ' . var_export(['parameters' => [
            'database_host' => 'old-host', 'database_port' => '3306', 'database_name' => 'old_db', 'database_user' => 'old_user',
            'database_password' => 'old-secret', 'database_prefix' => 'xx_',
            'secret' => 'FAKE-SECRET', 'cookie_key' => 'FAKE-COOKIE',
        ]], true) . ';');
        chmod($this->shop->root . '/app/config/parameters.php', 0640);
        $before = (string) file_get_contents($this->shop->root . '/app/config/parameters.php');
        $request = $this->moved(database: $database);

        $plan = $this->plan(new WriteParameters(), $request);

        $targets = array_map(static fn ($c): string => $c->target, $plan->changes());
        self::assertSame([
            'file app/config/parameters.php database_host',
            'file app/config/parameters.php database_name',
            'file app/config/parameters.php database_user',
            'file app/config/parameters.php database_password',
            'file app/config/parameters.php database_prefix',
        ], $targets);
        self::assertSame($before, (string) file_get_contents($this->shop->root . '/app/config/parameters.php'), 'The dry run writes nothing.');
        $password = array_values(array_filter($plan->changes(), static fn ($c): bool => str_ends_with($c->target, 'database_password')))[0];
        self::assertTrue($password->secret);

        $journalDirectory = $this->tempDirectory();
        $context = $this->shop->context($request, new FileJournal($journalDirectory), false);
        (new ReadSourceState())->plan($context);
        $step = new WriteParameters();
        $step->apply($context, $step->plan($context));

        $file = ParametersFile::fromPath($this->shop->root . '/app/config/parameters.php');
        self::assertSame($database->host, $file->get('database_host'));
        self::assertSame($database->name, $file->get('database_name'));
        self::assertSame('FAKE-SECRET', $file->get('secret'));
        self::assertSame('FAKE-COOKIE', $file->get('cookie_key'));
        self::assertSame(0640, fileperms($this->shop->root . '/app/config/parameters.php') & 0777);
        $backups = glob($journalDirectory . '/files/*.orig') ?: [];
        self::assertCount(1, $backups);
        self::assertSame($before, file_get_contents($backups[0]), 'The original is kept in the journal.');
    }

    public function testParametersStepIsIdempotent(): void
    {
        $this->shop->writeFile('app/config/parameters.php', '<?php return ' . var_export(['parameters' => [
            'database_host' => 'old-host', 'database_port' => '3306', 'database_name' => 'old_db', 'database_user' => 'old_user',
            'database_password' => 'old-secret', 'database_prefix' => 'ps_',
        ]], true) . ';');
        $request = $this->moved(database: $this->otherDatabase());
        $this->apply(new WriteParameters(), $request);

        // From now on the file names the database that works, so it is the "configured" one as well.
        self::assertFalse($this->plan(new WriteParameters(), $this->moved())->hasChanges());
    }

    // ---------------------------------------------------------------------------------------------- RewriteHtaccess

    private function installHtaccess(): string
    {
        $original = (string) file_get_contents(dirname(__DIR__) . '/fixtures/htaccess/ps9.0.2-localhost-8190.htaccess');
        $this->shop->writeFile('.htaccess', $original);

        return $original;
    }

    public function testHtaccessIsAdaptedAndTheOriginalIsKept(): void
    {
        $original = $this->installHtaccess();
        $request = new MigrationRequest($this->shop->root, [new DomainMapping('localhost:8190', 'shop.example')], physicalUri: '/shop/');
        $this->shop->exec("UPDATE ps_shop_url SET domain = 'localhost:8190', domain_ssl = 'localhost:8190'");

        $plan = $this->plan(new RewriteHtaccess(), $request);
        self::assertSame(12, $plan->changeCount());
        self::assertSame($original, file_get_contents($this->shop->root . '/.htaccess'), 'A dry run writes nothing.');

        $journalDirectory = $this->tempDirectory();
        $context = $this->shop->context($request, new FileJournal($journalDirectory), false);
        (new ReadSourceState())->plan($context);
        $step = new RewriteHtaccess();
        $step->apply($context, $step->plan($context));

        $new = (string) file_get_contents($this->shop->root . '/.htaccess');
        self::assertStringContainsString('#Domain: shop.example', $new);
        self::assertStringContainsString('REWRITEBASE:/shop/]', $new);
        self::assertSame($original, file_get_contents((glob($journalDirectory . '/files/*.orig') ?: [''])[0]));
    }

    public function testMissingHtaccessIsNotAnError(): void
    {
        $plan = $this->plan(new RewriteHtaccess(), $this->moved());

        self::assertFalse($plan->hasChanges());
        self::assertSame(NoticeLevel::Info, $plan->notices()[0]->level);
    }

    // ---------------------------------------------------------------------------------------- RewriteHostBoundFiles

    private function installHostBoundFiles(): void
    {
        $this->shop->writeFile('php.ini', "error_log = /kunden/homepages/22/d123/htdocs/shop/var/logs/php-errors.log;\nmemory_limit = 512M\n");
        $this->shop->writeFile('robots.txt', "User-agent: *\nSitemap: http://old.test/1_index_sitemap.xml\n");
        $this->shop->writeFile('1_index_sitemap.xml', '<sitemapindex><sitemap><loc>http://old.test/1_de_0_sitemap.xml</loc></sitemap></sitemapindex>');
        $this->shop->writeFile('mails/en/order_conf.html', '<img src="http://old.test///mails/themes/a.png"><a href="mailto:shop@old.test">x</a>');
        $this->shop->writeFile('mails/en/other.txt', 'nothing');
        $this->shop->writeFile('mails/en/binary.html', "http://old.test/\0");
        $this->shop->writeFile('modules/ps_emailalerts/mails/de/new_order.html', '<a href="https://old.test/x">x</a>');
    }

    public function testHostBoundFilesAreRewrittenAndBackedUp(): void
    {
        $this->installHostBoundFiles();
        $request = new MigrationRequest($this->shop->root, [new DomainMapping('old.test', 'new.test')], serverPath: '/srv/shop');

        $plan = $this->plan(new RewriteHostBoundFiles(), $request);
        $targets = array_map(static fn ($c): string => $c->target, $plan->changes());
        self::assertContains('file php.ini error_log', $targets);
        self::assertContains('files robots.txt', $targets);
        self::assertContains('files *_sitemap.xml', $targets);
        self::assertContains('files mails/**', $targets);
        self::assertContains('files modules/*/mails/**', $targets);
        self::assertStringContainsString('old.test', (string) file_get_contents($this->shop->root . '/robots.txt'), 'A dry run writes nothing.');

        $journalDirectory = $this->tempDirectory();
        $context = $this->shop->context($request, new FileJournal($journalDirectory), false);
        (new ReadSourceState())->plan($context);
        $step = new RewriteHostBoundFiles();
        $applied = $step->apply($context, $step->plan($context))->applied;

        self::assertSame(1 + 1 + 1 + 1 + 1, $applied);
        self::assertStringContainsString('error_log = /srv/shop/var/logs/php-errors.log;', (string) file_get_contents($this->shop->root . '/php.ini'));
        self::assertStringContainsString('Sitemap: http://new.test/1_index_sitemap.xml', (string) file_get_contents($this->shop->root . '/robots.txt'));
        self::assertStringContainsString('<loc>http://new.test/1_de_0_sitemap.xml</loc>', (string) file_get_contents($this->shop->root . '/1_index_sitemap.xml'));
        self::assertSame('<img src="http://new.test///mails/themes/a.png"><a href="mailto:shop@old.test">x</a>', file_get_contents($this->shop->root . '/mails/en/order_conf.html'));
        self::assertStringContainsString('https://new.test/x', (string) file_get_contents($this->shop->root . '/modules/ps_emailalerts/mails/de/new_order.html'));
        self::assertSame("http://old.test/\0", file_get_contents($this->shop->root . '/mails/en/binary.html'), 'Binary files are skipped.');
        self::assertSame('nothing', file_get_contents($this->shop->root . '/mails/en/other.txt'));
        self::assertCount(5, glob($journalDirectory . '/files/*.orig') ?: [], 'Every rewritten file is kept in the journal.');
    }

    public function testHostBoundFilesStepIsIdempotent(): void
    {
        $this->installHostBoundFiles();
        $request = new MigrationRequest($this->shop->root, [new DomainMapping('old.test', 'new.test')], serverPath: '/srv/shop');
        $this->apply(new RewriteHostBoundFiles(), $request);

        self::assertFalse($this->plan(new RewriteHostBoundFiles(), $request)->hasChanges());
    }

    public function testRestoreInPlaceStillFixesServerPaths(): void
    {
        $this->installHostBoundFiles();
        $request = new MigrationRequest($this->shop->root, serverPath: '/srv/shop');

        $plan = $this->plan(new RewriteHostBoundFiles(), $request);

        self::assertSame(['file php.ini error_log'], array_map(static fn ($c): string => $c->target, $plan->changes()));
    }

    public function testChangingTheBasePathWarnsAboutRobotsTxt(): void
    {
        $this->installHostBoundFiles();
        $request = new MigrationRequest($this->shop->root, [new DomainMapping('old.test', 'new.test')], physicalUri: '/shop/');

        $plan = $this->plan(new RewriteHostBoundFiles(), $request);

        $warnings = array_filter($plan->notices(), static fn ($n): bool => $n->level === NoticeLevel::Warning && $n->target === 'robots.txt');
        self::assertCount(1, $warnings);
    }

    // ---------------------------------------------------------------------------------------------- EnsureSkeleton

    public function testMissingDirectoriesAreCreatedWithTheirParents(): void
    {
        $plan = $this->plan(new EnsureSkeleton(), $this->moved());
        $targets = array_map(static fn ($c): string => $c->target, $plan->changes());
        self::assertContains('dir var/logs', $targets);
        self::assertContains('dir var/sessions', $targets);
        self::assertContains('dir img/tmp', $targets);
        self::assertDirectoryDoesNotExist($this->shop->root . '/var/logs');

        $this->apply(new EnsureSkeleton(), $this->moved());

        foreach (['var/logs', 'var/sessions', 'img/tmp'] as $directory) {
            self::assertDirectoryExists($this->shop->root . '/' . $directory);
            self::assertTrue(is_writable($this->shop->root . '/' . $directory));
        }
        self::assertFalse($this->plan(new EnsureSkeleton(), $this->moved())->hasChanges(), 'The second run has nothing to do.');
    }

    public function testNewDirectoriesInheritTheModeOfTheirParent(): void
    {
        chmod($this->shop->root . '/var', 0750);

        $this->apply(new EnsureSkeleton(), $this->moved());

        self::assertSame(0750, fileperms($this->shop->root . '/var/logs') & 0777);
    }

    public function testDirectoryWithoutOwnerWriteBitIsFixed(): void
    {
        mkdir($this->shop->root . '/upload', 0555);

        $plan = $this->plan(new EnsureSkeleton(), $this->moved());
        self::assertContains('dir upload', array_map(static fn ($c): string => $c->target, $plan->changes()));

        $this->apply(new EnsureSkeleton(), $this->moved());

        self::assertSame(0755, fileperms($this->shop->root . '/upload') & 0777);
    }
}
