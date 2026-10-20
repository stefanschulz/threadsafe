<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Cli;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Cli\Input;
use ThreadSafe\Cli\OptionSpec;
use ThreadSafe\Cli\UsageError;

final class InputTest extends TestCase
{
    /** @return list<OptionSpec> */
    private static function specs(): array
    {
        return [
            new OptionSpec('root', 'Shop root', takesValue: true, valueName: 'PATH'),
            new OptionSpec('domain', 'Domain mapping', takesValue: true, repeatable: true),
            new OptionSpec('dry-run', 'Plan only'),
            new OptionSpec('verbose', 'More output', short: 'v'),
        ];
    }

    public function testParsesArgumentsFlagsAndValues(): void
    {
        $input = Input::parse(['prestashop9', '--root=/var/www/html', '--dry-run', '-v', 'extra'], self::specs());

        self::assertSame(['prestashop9', 'extra'], $input->arguments());
        self::assertSame('prestashop9', $input->argument(0));
        self::assertNull($input->argument(5));
        self::assertSame('/var/www/html', $input->value('root'));
        self::assertTrue($input->has('dry-run'));
        self::assertTrue($input->has('verbose'), 'A short flag is stored under its long name.');
        self::assertFalse($input->has('domain'));
        self::assertNull($input->value('domain'));
    }

    public function testRepeatableOptionsCollectAllValues(): void
    {
        $input = Input::parse(['--domain=a=b', '--domain=c'], self::specs());

        self::assertSame(['a=b', 'c'], $input->values('domain'));
        self::assertSame('c', $input->value('domain'));
    }

    public function testValueMayContainEqualsSignsAndBeEmpty(): void
    {
        $input = Input::parse(['--root=a=b=c'], self::specs());
        self::assertSame('a=b=c', $input->value('root'));

        $input = Input::parse(['--root='], self::specs());
        self::assertSame('', $input->value('root'));
    }

    public function testDoubleDashEndsOptionParsing(): void
    {
        $input = Input::parse(['--dry-run', '--', '--root=x', '-v'], self::specs());

        self::assertSame(['--root=x', '-v'], $input->arguments());
        self::assertTrue($input->has('dry-run'));
        self::assertFalse($input->has('root'));
    }

    public function testSingleDashIsAnArgument(): void
    {
        self::assertSame(['-'], Input::parse(['-'], self::specs())->arguments());
    }

    /**
     * @param list<string> $tokens
     */
    #[DataProvider('invalid')]
    public function testRejectsWrongOptions(array $tokens, string $messagePart): void
    {
        $this->expectException(UsageError::class);
        $this->expectExceptionMessage($messagePart);

        Input::parse($tokens, self::specs());
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function invalid(): iterable
    {
        yield 'unknown long option' => [['--nope'], 'Unknown option --nope'];
        yield 'unknown short option' => [['-x'], 'Unknown option -x'];
        yield 'value missing' => [['--root'], 'needs a value'];
        yield 'flag with value' => [['--dry-run=yes'], 'does not take a value'];
        yield 'given twice' => [['--root=a', '--root=b'], 'only once'];
        yield 'flag given twice' => [['--dry-run', '--dry-run'], 'only once'];
    }

    public function testOptionSynopsis(): void
    {
        self::assertSame('--root=PATH', trim((new OptionSpec('root', '', takesValue: true, valueName: 'PATH'))->synopsis()));
        self::assertSame('-v, --verbose', (new OptionSpec('verbose', '', short: 'v'))->synopsis());
    }
}
