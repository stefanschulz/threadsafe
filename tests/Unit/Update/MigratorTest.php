<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Update;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Update\DataFormat;
use ThreadSafe\Update\Migration;
use ThreadSafe\Update\Migrations;
use ThreadSafe\Update\Migrator;
use ThreadSafe\Update\UpdateException;

/**
 * The format of the data directory and the steps between formats (docs/PHASE7.md 3.6).
 */
final class MigratorTest extends TestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    private function migration(int $to, string $description, ?\Closure $run = null): Migration
    {
        return new class($to, $description, $run) implements Migration {
            public function __construct(private readonly int $to, private readonly string $description, private readonly ?\Closure $run)
            {
            }

            public function to(): int
            {
                return $this->to;
            }

            public function describe(): string
            {
                return $this->description;
            }

            public function run(string $data): void
            {
                if ($this->run !== null) {
                    ($this->run)($data);
                }
                file_put_contents($data . '/ran-' . $this->to, $this->description);
            }
        };
    }

    public function testDataWithoutAVersionFileIsFormatOneAndGetsTheFile(): void
    {
        $data = $this->tempDirectory();

        $ran = (new Migrator([], 1, '0.2.0'))->ensure($data);

        self::assertSame([], $ran);
        self::assertSame(['dataFormat' => 1, 'program' => '0.2.0'], DataFormat::read($data));
    }

    public function testADirectoryThatDoesNotExistYetIsLeftAlone(): void
    {
        $data = $this->tempDirectory() . '/data';

        self::assertSame([], (new Migrator([], 1))->ensure($data));
        self::assertDirectoryDoesNotExist($data);
    }

    public function testOlderDataIsMigratedStepByStepInTheOrderOfTheFormats(): void
    {
        $data = $this->tempDirectory();
        DataFormat::write($data, 1, '0.1.0');
        $order = [];
        $three = $this->migration(3, 'to three', static function () use (&$order): void {
            $order[] = 3;
        });
        $two = $this->migration(2, 'to two', static function () use (&$order): void {
            $order[] = 2;
        });

        $ran = (new Migrator([$three, $two], 3, '0.3.0'))->ensure($data);

        self::assertSame([2, 3], $order);
        self::assertSame(['to two', 'to three'], $ran);
        self::assertSame(['dataFormat' => 3, 'program' => '0.3.0'], DataFormat::read($data));
    }

    public function testAMigrationThatHasRunDoesNotRunAgain(): void
    {
        $data = $this->tempDirectory();
        DataFormat::write($data, 1, '0.1.0');
        $migrator = new Migrator([$this->migration(2, 'to two')], 2, '0.2.0');

        self::assertSame(['to two'], $migrator->ensure($data));
        self::assertSame([], $migrator->ensure($data));
    }

    public function testAFailedStepKeepsTheFormatOfTheStepsBeforeIt(): void
    {
        $data = $this->tempDirectory();
        DataFormat::write($data, 1, '0.1.0');
        $failing = $this->migration(3, 'to three', static function (): void {
            throw new UpdateException('cannot do three');
        });

        try {
            (new Migrator([$this->migration(2, 'to two'), $failing], 3, '0.3.0'))->ensure($data);
            self::fail('expected the failure');
        } catch (UpdateException $e) {
            self::assertSame('cannot do three', $e->getMessage());
        }

        self::assertSame(2, DataFormat::read($data)['dataFormat'] ?? null, 'a second try starts at step three');
    }

    public function testDataOfANewerProgramIsRefusedAndNotTouched(): void
    {
        $data = $this->tempDirectory();
        DataFormat::write($data, 5, '9.0.0');
        file_put_contents($data . '/profiles-of-the-future.json', 'x');

        try {
            (new Migrator([], 2, '0.2.0'))->ensure($data);
            self::fail('expected a refusal');
        } catch (UpdateException $e) {
            self::assertStringContainsString('made by a newer ThreadSafe (data format 5; this program, version 0.2.0, writes 2)', $e->getMessage());
        }

        self::assertSame(['dataFormat' => 5, 'program' => '9.0.0'], DataFormat::read($data));
    }

    public function testAMigrationBeyondTheFormatOfThisProgramIsNotRun(): void
    {
        $data = $this->tempDirectory();

        $ran = (new Migrator([$this->migration(9, 'from the future')], 1, '0.1.0'))->ensure($data);

        self::assertSame([], $ran);
        self::assertFileDoesNotExist($data . '/ran-9');
    }

    public function testADamagedVersionFileCountsAsFormatOne(): void
    {
        $data = $this->tempDirectory();
        file_put_contents($data . '/version.json', '{broken');

        $before = DataFormat::read($data);
        self::assertNull($before);
        self::assertSame([], (new Migrator([], 1, '0.1.0'))->ensure($data));
        $after = DataFormat::read($data);
        self::assertSame(1, $after['dataFormat'] ?? 0);
    }

    public function testThisProgramHasNothingToMigrateYet(): void
    {
        self::assertSame([], Migrations::all());
    }
}
