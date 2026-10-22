<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Schedule;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Schedule\CronExpression;
use ThreadSafe\Schedule\ScheduleException;

/**
 * The cron arithmetic, including the two daylight saving time cases that PHP alone resolves differently per zone: a time that does
 * not exist runs at the first instant after the gap, a time that exists twice runs once, at its first occurrence.
 */
final class CronExpressionTest extends TestCase
{
    private static function at(string $time, string $zone = 'UTC'): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone($zone));
    }

    /** an instant given in UTC, shown on the clock of a zone */
    private static function inZone(string $utc, string $zone): DateTimeImmutable
    {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($zone));
    }

    /** an instant as UTC text, so that expectations do not depend on the zone of the computation */
    private static function utc(?DateTimeImmutable $instant): ?string
    {
        return $instant?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i');
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function nextInstants(): iterable
    {
        yield 'daily, the instant itself is not "after"' => ['30 3 * * *', '2026-10-22 03:30:00', '2026-10-23 03:30'];
        yield 'every 15 minutes' => ['*/15 * * * *', '2026-10-22 10:07:00', '2026-10-22 10:15'];
        yield 'leap day' => ['0 0 29 2 *', '2026-03-01 00:00:00', '2028-02-29 00:00'];
        yield 'working days' => ['0 12 * * 1-5', '2026-10-23 12:00:00', '2026-10-26 12:00'];
        yield 'both day fields: either (Friday the 23rd is a Friday)' => ['0 0 13 * 5', '2026-10-22 00:00:00', '2026-10-23 00:00'];
        yield 'both day fields: either (the 13th)' => ['0 0 13 * 5', '2026-10-23 00:00:00', '2026-10-30 00:00'];
        yield 'weekly is Sunday' => ['@weekly', '2026-10-22 00:00:00', '2026-10-25 00:00'];
        yield 'monthly' => ['@monthly', '2026-10-22 00:00:00', '2026-11-01 00:00'];
        yield 'hourly' => ['@hourly', '2026-10-22 10:00:00', '2026-10-22 11:00'];
        yield 'list of days across the year' => ['0 9 1,15 * *', '2026-12-15 09:00:00', '2027-01-01 09:00'];
        yield 'names of months and days' => ['5 4 * jan,jul mon', '2026-06-30 00:00:00', '2026-07-06 04:05'];
        yield 'Sunday as 7' => ['0 6 * * 7', '2026-10-22 00:00:00', '2026-10-25 06:00'];
        yield 'a step from a start' => ['5/20 * * * *', '2026-10-22 10:06:00', '2026-10-22 10:25'];
        yield 'a step on a day field that starts with a star is a restriction' => ['0 0 */2 * *', '2026-10-02 00:00:00', '2026-10-03 00:00'];
        yield 'the end of the year' => ['59 23 31 12 *', '2026-12-31 23:59:00', '2027-12-31 23:59'];
    }

    #[DataProvider('nextInstants')]
    public function testTheNextInstant(string $expression, string $after, string $expected): void
    {
        self::assertSame($expected, self::utc(CronExpression::parse($expression)->next(self::at($after))));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function previousInstants(): iterable
    {
        yield 'the instant itself counts' => ['30 3 * * *', '2026-10-22 03:30:00', '2026-10-22 03:30'];
        yield 'a minute before it' => ['30 3 * * *', '2026-10-22 03:29:00', '2026-10-21 03:30'];
        yield 'seconds do not matter' => ['30 3 * * *', '2026-10-22 03:30:59', '2026-10-22 03:30'];
        yield 'across a month' => ['0 0 1 * *', '2026-03-15 12:00:00', '2026-03-01 00:00'];
        yield 'across a year' => ['0 0 1 1 *', '2026-03-15 12:00:00', '2026-01-01 00:00'];
        yield 'leap day' => ['0 0 29 2 *', '2026-03-01 00:00:00', '2024-02-29 00:00'];
        yield 'working days' => ['0 12 * * 1-5', '2026-10-25 12:00:00', '2026-10-23 12:00'];
        yield 'both day fields: either' => ['0 0 13 * 5', '2026-10-29 00:00:00', '2026-10-23 00:00'];
        yield 'the last day of February' => ['0 0 28 2 *', '2027-03-01 00:00:00', '2027-02-28 00:00'];
    }

    #[DataProvider('previousInstants')]
    public function testThePreviousInstant(string $expression, string $at, string $expected): void
    {
        self::assertSame($expected, self::utc(CronExpression::parse($expression)->previous(self::at($at))));
    }

    /** @return iterable<int, array{string}> */
    public static function acceptedExpressions(): iterable
    {
        yield ['@daily'];
        yield ['  0   3 * * *  '];
        yield ['0 0 * * 7'];
        yield ['*/15 9-17 * * mon-fri'];
        yield ['5/10 * * * *'];
        yield ['0 0 1,15 jan,jul *'];
        yield ['* * * * *'];
        yield ['0 0 29 2 *'];
    }

    #[DataProvider('acceptedExpressions')]
    public function testAnExpressionIsAccepted(string $expression): void
    {
        self::assertSame(trim($expression), CronExpression::parse($expression)->expression());
    }

    /** @return iterable<string, array{string, string}> */
    public static function refusedExpressions(): iterable
    {
        yield 'nothing' => ['', 'five fields'];
        yield 'four fields' => ['* * * *', 'five fields'];
        yield 'six fields' => ['* * * * * *', 'five fields'];
        yield 'an unknown shortcut' => ['@yearly', 'five fields'];
        yield 'minute out of range' => ['60 * * * *', 'out of range'];
        yield 'hour out of range' => ['* 24 * * *', 'out of range'];
        yield 'day zero' => ['* * 0 * *', 'out of range'];
        yield 'month 13' => ['* * * 13 *', 'out of range'];
        yield 'weekday 8' => ['* * * * 8', 'out of range'];
        yield 'a step of zero' => ['*/0 * * * *', 'step'];
        yield 'a step that is not a number' => ['*/x * * * *', 'step'];
        yield 'not a number' => ['a * * * *', 'not a number'];
        yield 'a decimal' => ['1.5 * * * *', 'not a number'];
        yield 'an empty list item' => ['1,,2 * * * *', 'not a number'];
        yield 'a backwards range' => ['5-1 * * * *', 'out of range'];
        yield 'a range with three ends' => ['1-2-3 * * * *', 'range'];
        yield 'an unknown name' => ['* * * foo *', 'not a number or a name'];
        yield 'a name in a field without names' => ['mon * * * *', 'not a number'];
        yield 'a date that does not exist' => ['0 0 31 2 *', 'never matches'];
        yield 'a day that does not exist in April' => ['0 0 31 4 *', 'never matches'];
    }

    #[DataProvider('refusedExpressions')]
    public function testAnExpressionIsRefused(string $expression, string $because): void
    {
        $this->expectException(ScheduleException::class);
        $this->expectExceptionMessage($because);
        CronExpression::parse($expression);
    }

    /** @return iterable<string, array{string, string, string, string, string}> zone, expression, after, next, the one after that (UTC) */
    public static function daylightSavingTime(): iterable
    {
        // the clock jumps forward: 02:30 does not exist; the run happens at the first instant after the gap
        yield 'Berlin, spring: the gap' => ['Europe/Berlin', '30 2 * * *', '2026-03-28 03:00:00', '2026-03-29 01:00', '2026-03-30 00:30'];
        yield 'New York, spring: the gap' => ['America/New_York', '30 2 * * *', '2026-03-07 12:00:00', '2026-03-08 07:00', '2026-03-09 06:30'];
        yield 'Lord Howe (30 minutes), spring: the gap' => ['Australia/Lord_Howe', '15 2 * * *', '2026-10-03 12:00:00', '2026-10-03 15:30', '2026-10-04 15:15'];
        // the clock goes back: 02:30 (Berlin) exists twice; the run happens once, at the first occurrence
        yield 'Berlin, autumn: twice' => ['Europe/Berlin', '30 2 * * *', '2026-10-24 03:00:00', '2026-10-25 00:30', '2026-10-26 01:30'];
        yield 'New York, autumn: twice' => ['America/New_York', '30 1 * * *', '2026-10-31 12:00:00', '2026-11-01 05:30', '2026-11-02 06:30'];
        yield 'Lord Howe, autumn: twice' => ['Australia/Lord_Howe', '45 1 * * *', '2026-04-04 12:00:00', '2026-04-04 14:45', '2026-04-05 15:15'];
        // nothing special on an ordinary day, and a zone without daylight saving time
        yield 'Berlin, an ordinary day' => ['Europe/Berlin', '30 2 * * *', '2026-06-01 03:00:00', '2026-06-02 00:30', '2026-06-03 00:30'];
        yield 'Kolkata (30 minutes, no daylight saving time)' => ['Asia/Kolkata', '30 2 * * *', '2026-06-01 03:00:00', '2026-06-01 21:00', '2026-06-02 21:00'];
    }

    #[DataProvider('daylightSavingTime')]
    public function testDaylightSavingTime(string $zone, string $expression, string $after, string $first, string $second): void
    {
        $cron = CronExpression::parse($expression);

        $one = $cron->next(self::at($after, $zone));
        $two = $cron->next($one);

        self::assertSame($first, self::utc($one));
        self::assertSame($second, self::utc($two));
        self::assertSame($zone, $one->getTimezone()->getName());
    }

    public function testTheHourThatExistsTwiceRunsOnceInAnHourlyExpression(): void
    {
        $cron = CronExpression::parse('0 * * * *');

        $second = $cron->next(self::inZone('2026-10-25 00:00:00', 'Europe/Berlin'));   // 02:00 CEST, the first pass

        // 02:00 CET (01:00 UTC) is the second pass of the same wall clock time: it is skipped, 03:00 CET follows
        self::assertSame('2026-10-25 02:00', self::utc($second));
    }

    public function testADailyExpressionRunsOncePerDayAroundEveryTransition(): void
    {
        foreach (['Europe/Berlin' => ['2026-03-25', '2026-10-22'], 'America/New_York' => ['2026-03-05', '2026-10-29'], 'Australia/Lord_Howe' => ['2026-04-01', '2026-10-01']] as $zoneName => $starts) {
            foreach (['30 2 * * *', '0 3 * * *', '30 1 * * *'] as $expression) {
                $cron = CronExpression::parse($expression);
                foreach ($starts as $start) {
                    $instant = self::at($start . ' 12:00:00', $zoneName);
                    $days = [];
                    for ($i = 0; $i < 14; ++$i) {
                        $instant = $cron->next($instant);
                        $days[] = $instant->format('Y-m-d');
                        self::assertSame(self::utc($instant), self::utc($cron->previous($instant)), "$zoneName $expression: previous() of an instant that is due is that instant");
                    }
                    self::assertSame($days, array_values(array_unique($days)), "$zoneName $expression from $start: one run per calendar day");
                    self::assertCount(14, $days);
                }
            }
        }
    }

    public function testNextAndPreviousAgreeAroundEveryTransition(): void
    {
        $windows = ['Europe/Berlin' => ['2026-03-28 12:00', '2026-10-24 12:00'], 'America/New_York' => ['2026-03-07 12:00', '2026-10-31 12:00'], 'Australia/Lord_Howe' => ['2026-04-04 12:00', '2026-10-03 12:00']];
        foreach ($windows as $zoneName => $starts) {
            foreach (['*/10 * * * *', '30 2 * * *', '0 * * * *', '15 1,2 * * *'] as $expression) {
                $cron = CronExpression::parse($expression);
                foreach ($starts as $start) {
                    $first = self::inZone($start . ':00', 'UTC')->setTimezone(new DateTimeZone($zoneName))->getTimestamp();
                    for ($offset = 0; $offset < 3 * 86400; $offset += 421) {
                        $at = (new DateTimeImmutable('@' . ($first + $offset)))->setTimezone(new DateTimeZone($zoneName));
                        $next = $cron->next($at);
                        $previous = $cron->previous($at);
                        $label = "$zoneName '$expression' at " . $at->format('c');
                        self::assertGreaterThan($at, $next, $label);
                        self::assertSame($next->getTimestamp(), $cron->previous($next)?->getTimestamp(), "$label: an instant that is due is its own previous");
                        self::assertNotNull($previous, $label);
                        self::assertLessThanOrEqual($at, $previous, $label);
                        self::assertGreaterThan($at, $cron->next($previous), "$label: nothing is due between the previous instant and the instant");
                    }
                }
            }
        }
    }

    public function testThePreviousInstantAtTheTransitions(): void
    {
        $cron = CronExpression::parse('30 2 * * *');

        // the gap: the instant itself is the transition (03:00 CEST = 01:00 UTC); a minute earlier the day before counts
        self::assertSame('2026-03-29 01:00', self::utc($cron->previous(self::inZone('2026-03-29 01:00:00', 'Europe/Berlin'))));
        self::assertSame('2026-03-28 01:30', self::utc($cron->previous(self::inZone('2026-03-29 00:59:00', 'Europe/Berlin'))));
        // the hour that exists twice: in its second pass the previous run is still the first pass
        self::assertSame('2026-10-25 00:30', self::utc($cron->previous(self::inZone('2026-10-25 01:40:00', 'Europe/Berlin'))));
    }

    public function testAnExpressionDoesNotDependOnTheZoneOfThePhpDefault(): void
    {
        $before = date_default_timezone_get();
        date_default_timezone_set('Pacific/Auckland');
        try {
            self::assertSame('2026-10-23 03:30', self::utc(CronExpression::parse('30 3 * * *')->next(self::at('2026-10-22 03:30:00'))));
        } finally {
            date_default_timezone_set($before);
        }
    }

    public function testAChainOfManyInstantsIsFast(): void
    {
        $cron = CronExpression::parse('*/5 * * * *');
        $instant = self::at('2026-01-01 00:00:00', 'Europe/Berlin');
        $started = microtime(true);
        for ($i = 0; $i < 20000; ++$i) {
            $instant = $cron->next($instant);
        }

        self::assertLessThan(5.0, microtime(true) - $started);
        self::assertSame('2026-03-11 10:40', $instant->format('Y-m-d H:i'));
    }
}
