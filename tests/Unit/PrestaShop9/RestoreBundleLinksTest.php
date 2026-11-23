<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\PrestaShop9;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Core\Context;
use ThreadSafe\Core\NullJournal;
use ThreadSafe\Core\NullLogger;
use ThreadSafe\Database\DatabaseSettings;
use ThreadSafe\Migration\MigrationRequest;
use ThreadSafe\PrestaShop9\SourceState;
use ThreadSafe\PrestaShop9\State;
use ThreadSafe\PrestaShop9\Steps\RestoreBundleLinks;
use ThreadSafe\Tests\Support\FakeConnection;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * The links `<admin>/bundles/<name>` that a backup does not keep (field test of the PrestaShop module, docs/PHASE15.md 8): a restore makes them again.
 */
final class RestoreBundleLinksTest extends TestCase
{
    use TempDirectory;

    private const string FOS = 'vendor/friendsofsymfony/jsrouting-bundle/Resources/public';

    private const string API = 'vendor/api-platform/core/src/Symfony/Bundle/Resources/public';

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    private function shop(bool $withApi = true): string
    {
        $root = (string) realpath($this->tempDirectory());
        mkdir($root . '/admin1/themes/new-theme', 0777, true);
        mkdir($root . '/admin1/bundles');
        mkdir($root . '/' . self::FOS . '/js', 0777, true);
        file_put_contents($root . '/' . self::FOS . '/js/router.min.js', 'routes');
        if ($withApi) {
            mkdir($root . '/' . self::API, 0777, true);
            file_put_contents($root . '/' . self::API . '/init.js', 'api');
        }

        return $root;
    }

    private function context(string $root, ?string $admin = 'admin1'): Context
    {
        $database = new DatabaseSettings('db', 3306, 'shop', 'u', 'p', 'ps_');
        $context = new Context(new MigrationRequest($root, [], null, null), new NullJournal(), new NullLogger(), false);
        State::store($context, new SourceState($root, '9.0.2', $admin, $database, $database, '10.11.0-MariaDB', []), FakeConnection::with([], 'ps_'));

        return $context;
    }

    public function testTheMissingLinksAreMadeRelativeAndTheyWork(): void
    {
        $root = $this->shop();
        $context = $this->context($root);
        $step = new RestoreBundleLinks();

        $plan = $step->plan($context);
        $result = $step->apply($context, $plan);

        self::assertSame(2, $plan->changeCount());
        self::assertSame(2, $result->applied);
        self::assertSame('../../' . self::FOS, readlink($root . '/admin1/bundles/fosjsrouting'));
        self::assertSame('routes', file_get_contents($root . '/admin1/bundles/fosjsrouting/js/router.min.js'));
        self::assertSame('api', file_get_contents($root . '/admin1/bundles/apiplatform/init.js'));
        self::assertFalse($step->plan($context)->hasChanges(), 'idempotent: a second plan is empty');
    }

    public function testNothingIsMadeWhereTheTargetIsNotThere(): void
    {
        $root = $this->shop(false);
        $context = $this->context($root);
        $step = new RestoreBundleLinks();

        $plan = $step->plan($context);
        $step->apply($context, $plan);

        self::assertSame(1, $plan->changeCount());
        self::assertFalse(file_exists($root . '/admin1/bundles/apiplatform') || is_link($root . '/admin1/bundles/apiplatform'));
        self::assertTrue(is_link($root . '/admin1/bundles/fosjsrouting'));
    }

    public function testAnExistingLinkOrDirectoryIsLeftAlone(): void
    {
        $root = $this->shop();
        mkdir($root . '/admin1/bundles/fosjsrouting');
        file_put_contents($root . '/admin1/bundles/fosjsrouting/mine.js', 'mine');
        $context = $this->context($root);
        $step = new RestoreBundleLinks();

        $plan = $step->plan($context);
        $step->apply($context, $plan);

        self::assertSame(1, $plan->changeCount());
        self::assertFalse(is_link($root . '/admin1/bundles/fosjsrouting'));
        self::assertSame('mine', file_get_contents($root . '/admin1/bundles/fosjsrouting/mine.js'));
    }

    public function testTheDirectoryBundlesIsMadeWhenItIsMissing(): void
    {
        $root = $this->shop();
        rmdir($root . '/admin1/bundles');
        $context = $this->context($root);
        $step = new RestoreBundleLinks();

        $step->apply($context, $step->plan($context));

        self::assertTrue(is_link($root . '/admin1/bundles/fosjsrouting'));
    }

    public function testWithoutABackOfficeThereIsNothingToDo(): void
    {
        $root = $this->shop();

        self::assertFalse((new RestoreBundleLinks())->plan($this->context($root, null))->hasChanges());
    }
}
