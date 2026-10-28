<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Profile;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Profile\ProfileException;
use ThreadSafe\Profile\ScheduleSettings;

/**
 * The schedule of a profile in plain settings (docs/PHASE8.md 3.1, 3.2): what it reads and writes, the cron expression the engine gets from it,
 * its words and its text form for the command line.
 */
final class ScheduleSettingsTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>, string, string, string}> file form, cron, words, text */
    public static function schedules(): iterable
    {
        yield 'every hour' => [['every' => 'hours', 'interval' => 1, 'minute' => 15], '15 * * * *', 'Every hour at :15', 'hourly at :15'];
        yield 'every six hours' => [['every' => 'hours', 'interval' => 6, 'minute' => 0], '0 */6 * * *', 'Every 6 hours at :00', 'every 6 hours at :00'];
        yield 'every day' => [['every' => 'day', 'at' => '03:30'], '30 3 * * *', 'Every day at 03:30', 'daily at 03:30'];
        yield 'one weekday' => [['every' => 'week', 'on' => ['mon'], 'at' => '02:15'], '15 2 * * mon', 'Every Monday at 02:15', 'weekly on mon at 02:15'];
        yield 'two weekdays' => [['every' => 'week', 'on' => ['mon', 'thu'], 'at' => '02:15'], '15 2 * * mon,thu', 'Every Monday and Thursday at 02:15', 'weekly on mon,thu at 02:15'];
        yield 'three weekdays' => [['every' => 'week', 'on' => ['mon', 'wed', 'fri'], 'at' => '22:00'], '0 22 * * mon,wed,fri', 'Every Monday, Wednesday and Friday at 22:00', 'weekly on mon,wed,fri at 22:00'];
        yield 'every day of the week' => [['every' => 'week', 'on' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], 'at' => '01:00'], '0 1 * * mon,tue,wed,thu,fri,sat,sun', 'Every day at 01:00', 'weekly on mon,tue,wed,thu,fri,sat,sun at 01:00'];
        yield 'the first of the month' => [['every' => 'month', 'day' => 1, 'at' => '04:00'], '0 4 1 * *', 'On the 1st of every month at 04:00', 'monthly on 1 at 04:00'];
        yield 'the 22nd' => [['every' => 'month', 'day' => 22, 'at' => '23:45'], '45 23 22 * *', 'On the 22nd of every month at 23:45', 'monthly on 22 at 23:45'];
        yield 'the 13th' => [['every' => 'month', 'day' => 13, 'at' => '00:05'], '5 0 13 * *', 'On the 13th of every month at 00:05', 'monthly on 13 at 00:05'];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[DataProvider('schedules')]
    public function testASettingIsReadWrittenAndExplained(array $data, string $cron, string $words, string $text): void
    {
        $schedule = ScheduleSettings::fromArray($data);

        self::assertNotNull($schedule);
        self::assertSame($data, $schedule->toArray(), 'the file form comes back as it was');
        self::assertSame($cron, $schedule->cron());
        self::assertSame($cron, $schedule->expression()->expression(), 'the engine takes the expression as it is');
        self::assertSame($words, $schedule->describe());
        self::assertSame($text, $schedule->text());
        self::assertEquals($schedule, ScheduleSettings::parse($text), 'the text form of the command line reads back to the same');
        self::assertFalse($schedule->paused);
        self::assertTrue($schedule->active());
    }

    public function testAWeekdayListIsPutInTheOrderOfTheWeekAndWithoutDoubles(): void
    {
        $schedule = ScheduleSettings::weekly(['sun', 'mon', 'sun'], 3, 0);

        self::assertSame(['mon', 'sun'], $schedule->on);
        self::assertSame('0 3 * * mon,sun', $schedule->cron());
    }

    public function testNothingToScheduleIsNull(): void
    {
        self::assertNull(ScheduleSettings::fromArray(null));
        self::assertNull(ScheduleSettings::fromArray([]));
        self::assertNull(ScheduleSettings::fromArray(['every' => null]));
    }

    public function testAPausedScheduleKeepsItsSettings(): void
    {
        $schedule = ScheduleSettings::fromArray(['every' => 'day', 'at' => '03:30', 'paused' => true]);

        self::assertNotNull($schedule);
        self::assertTrue($schedule->paused);
        self::assertFalse($schedule->active());
        self::assertSame(['every' => 'day', 'at' => '03:30', 'paused' => true], $schedule->toArray());
        self::assertSame('30 3 * * *', $schedule->cron());
        self::assertSame(['every' => 'day', 'at' => '03:30'], $schedule->withPaused(false)->toArray(), 'resumed: no flag in the file');
        self::assertTrue($schedule->sameTimes($schedule->withPaused(false)));
        self::assertFalse($schedule->sameTimes(ScheduleSettings::daily(3, 31)));
    }

    public function testTheEngineGetsAnExpressionThatRunsAtTheRightTimes(): void
    {
        $zone = new DateTimeZone('Europe/Berlin');
        $after = new DateTimeImmutable('2026-10-28 12:00:00', $zone);

        self::assertSame('2026-10-28 12:15', ScheduleSettings::hours(6, 15)->expression()->next($after)->format('Y-m-d H:i'));
        self::assertSame('2026-10-29 03:30', ScheduleSettings::daily(3, 30)->expression()->next($after)->format('Y-m-d H:i'));
        self::assertSame('2026-10-29 02:15', ScheduleSettings::weekly(['thu'], 2, 15)->expression()->next($after)->format('Y-m-d H:i'), '28 October 2026 is a Wednesday');
        self::assertSame('2026-11-01 04:00', ScheduleSettings::monthly(1, 4, 0)->expression()->next($after)->format('Y-m-d H:i'));
    }

    /** @return iterable<string, array{string, string}> text, what the person is told */
    public static function texts(): iterable
    {
        yield 'hourly' => ['hourly', 'Every hour at :00'];
        yield 'words that are left out' => ['every 4 hours :30', 'Every 4 hours at :30'];
        yield 'capitals and spaces' => ['  Daily   AT 3:05 ', 'Every day at 03:05'];
        yield 'full names of the days' => ['weekly on Monday, thursday at 02:15', 'Every Monday and Thursday at 02:15'];
        yield 'no words at all' => ['weekly mon 02:15', 'Every Monday at 02:15'];
        yield 'monthly without words' => ['monthly 15 12:00', 'On the 15th of every month at 12:00'];
    }

    #[DataProvider('texts')]
    public function testTheCommandLineTextIsReadLeniently(string $text, string $words): void
    {
        self::assertSame($words, ScheduleSettings::parse($text)->describe());
    }

    /** @return iterable<string, array{string, string}> */
    public static function badTexts(): iterable
    {
        yield 'a cron expression' => ['30 3 * * *', 'is not a schedule'];
        yield 'nothing' => ['', 'is not a schedule'];
        yield 'hours that do not divide a day' => ['every 5 hours', '"schedule.interval" must be one of 1, 2, 3, 4, 6, 8, 12'];
        yield 'a day that is no day' => ['weekly on funday at 03:00', '"funday" is not a day of the week'];
        yield 'a month day that months lack' => ['monthly on 30 at 03:00', 'from 1 to 28'];
        yield 'an hour that does not exist' => ['daily at 24:00', 'The hour of "schedule.at"'];
        yield 'a minute that does not exist' => ['every 2 hours at :75', '0 to 59'];
    }

    #[DataProvider('badTexts')]
    public function testATextThatIsNoScheduleIsRefusedWithAHint(string $text, string $message): void
    {
        $this->expectException(ProfileException::class);
        $this->expectExceptionMessage($message);

        ScheduleSettings::parse($text);
    }

    public function testTheValuesOfTheFileAreChecked(): void
    {
        $this->expectException(ProfileException::class);
        $this->expectExceptionMessage('"schedule.minute" must be a whole number');

        ScheduleSettings::fromArray(['every' => 'hours', 'interval' => 6, 'minute' => '15']);
    }

    public function testTheWeekdaysOfTheFileMustBeAList(): void
    {
        $this->expectException(ProfileException::class);
        $this->expectExceptionMessage('"schedule.on" must be a list of days of the week');

        ScheduleSettings::fromArray(['every' => 'week', 'on' => 'mon', 'at' => '03:00']);
    }

    public function testAnUnknownDayOfTheWeekIsNamedInTheError(): void
    {
        $this->expectException(ProfileException::class);
        $this->expectExceptionMessage('"schedule.on" must list days of the week');

        ScheduleSettings::fromArray(['every' => 'week', 'on' => ['monday'], 'at' => '03:00']);
    }
}
