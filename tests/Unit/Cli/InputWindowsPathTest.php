<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Cli;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Cli\Input;
use ThreadSafe\Cli\OptionSpec;
use ThreadSafe\Cli\UsageError;

/**
 * A Windows path that Git Bash has stripped of its backslashes (`C:\sicher\key.txt` arrives as `C:sicherkey.txt`) is refused with a hint on Windows,
 * not used as a path relative to the current directory of the drive.
 */
final class InputWindowsPathTest extends TestCase
{
    /** @return list<OptionSpec> */
    private function specs(): array
    {
        return [
            new OptionSpec('identity-file', 'the key', takesValue: true, valueName: 'FILE'),
            new OptionSpec('root', 'the directory', takesValue: true, valueName: 'DIR'),
            new OptionSpec('note', 'free text', takesValue: true, valueName: 'TEXT'),
        ];
    }

    /** @return iterable<string, array{string, bool}> */
    public static function texts(): iterable
    {
        yield 'a path without its backslashes' => ['C:sicherkey.txt', true];
        yield 'a lower case drive' => ['d:backupsshop', true];
        yield 'a path with backslashes' => ['C:\sicher\key.txt', false];
        yield 'a path with slashes' => ['C:/sicher/key.txt', false];
        yield 'a drive alone' => ['C:', false];
        yield 'a drive with a separator' => ['C:\\', false];
        yield 'an address' => ['https://example.com/x', false];
        yield 'a relative path' => ['sicher/key.txt', false];
        yield 'a name with a colon after more letters' => ['ab:cd', false];
    }

    #[DataProvider('texts')]
    public function testTheShapeOfAPathThatLostItsBackslashes(string $text, bool $lost): void
    {
        self::assertSame($lost, Input::lostBackslashes($text));
    }

    public function testAnOptionWithAPathIsRefusedOnWindows(): void
    {
        try {
            Input::parse(['--identity-file=C:sicherkey.txt'], $this->specs(), true);
            self::fail('The path should have been refused.');
        } catch (UsageError $e) {
            self::assertStringContainsString('--identity-file', $e->getMessage());
            self::assertStringContainsString('C:sicherkey.txt', $e->getMessage());
            self::assertStringContainsString('forward slashes', $e->getMessage());
        }
    }

    public function testAnArgumentIsRefusedOnWindows(): void
    {
        $this->expectException(UsageError::class);
        $this->expectExceptionMessage('The argument "D:backupshop.zip"');

        Input::parse(['D:backupshop.zip'], $this->specs(), true);
    }

    public function testAGoodPathPasses(): void
    {
        $input = Input::parse(['--identity-file=C:/sicher/key.txt', '--root=C:\shop', 'archive.zip'], $this->specs(), true);

        self::assertSame('C:/sicher/key.txt', $input->value('identity-file'));
        self::assertSame('C:\shop', $input->value('root'));
        self::assertSame(['archive.zip'], $input->arguments());
    }

    public function testAnOptionThatIsNoPathIsNotLookedAt(): void
    {
        $input = Input::parse(['--note=A:b'], $this->specs(), true);

        self::assertSame('A:b', $input->value('note'));
    }

    public function testOffWindowsAnythingGoesThrough(): void
    {
        $input = Input::parse(['--identity-file=C:sicherkey.txt'], $this->specs(), false);

        self::assertSame('C:sicherkey.txt', $input->value('identity-file'));
    }
}
