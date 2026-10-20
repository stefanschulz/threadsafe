<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Generic;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Core\Exception\GuardFailed;
use ThreadSafe\Database\DatabaseSettings;
use ThreadSafe\Generic\RequestBuilder;
use ThreadSafe\Migration\Mode;
use ThreadSafe\Tests\Support\TempDirectory;

final class RequestBuilderTest extends TestCase
{
    use TempDirectory;

    private string $root;

    protected function setUp(): void
    {
        $this->root = (string) realpath($this->tempDirectory());
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    public function testDomainsAreGiven(): void
    {
        $request = (new RequestBuilder())->build(['root' => $this->root, 'from' => 'Old.Test', 'to' => 'new.test']);

        self::assertSame($this->root, $request->root);
        self::assertSame('old.test', $request->domains[0]->from);
        self::assertNull($request->database, 'A generic site may have no database.');
        self::assertSame(Mode::Production, $request->mode);
    }

    public function testTheOldDomainCannotBeFoundOut(): void
    {
        $this->expectException(GuardFailed::class);
        $this->expectExceptionMessage('give the old domain with --from');

        (new RequestBuilder())->build(['root' => $this->root, 'to' => 'new.test']);
    }

    public function testTheDatabaseIsGivenCompletelyAndNeedsNoPrefix(): void
    {
        $request = (new RequestBuilder())->build(['root' => $this->root, 'database' => ['host' => 'h', 'port' => 3306, 'name' => 'n', 'user' => 'u', 'password' => 'secret', 'driver' => 'mysqli']]);

        self::assertEquals(new DatabaseSettings('h', 3306, 'n', 'u', 'secret', '', 'mysqli'), $request->database);
    }

    public function testIncompleteDatabaseIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The database name is not given and cannot be taken from the installation.');

        (new RequestBuilder())->build(['root' => $this->root, 'database' => ['host' => 'h', 'port' => 3306, 'user' => 'u', 'password' => 'p']]);
    }

    public function testSettingsOfOtherWebsiteTypesAreUnknown(): void
    {
        foreach (['ssl' => 'all', 'path' => '/shop/', 'mode' => 'dev', 'finalize' => false, 'rulesDir' => '/x'] as $key => $value) {
            try {
                (new RequestBuilder())->build(['root' => $this->root, $key => $value]);
                self::fail("$key must be unknown to the generic type.");
            } catch (InvalidArgumentException $e) {
                self::assertSame("Unknown setting \"$key\".", $e->getMessage());
            }
        }
    }
}
