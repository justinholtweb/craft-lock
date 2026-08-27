<?php

namespace justinholtweb\lock\tests\unit;

use DateTimeImmutable;
use justinholtweb\lock\helpers\Cadence;
use PHPUnit\Framework\TestCase;

/**
 * Schedule arithmetic.
 *
 * The model is *occurrences*, not elapsed time. A schedule is due when the most recent occurrence
 * is newer than the last run — the "has 24 hours passed" alternative drifts a little later every
 * day and eventually lands a 3am purge in the middle of the working day.
 */
class CadenceTest extends TestCase
{
    public function testDailyIsDueOncePerDay(): void
    {
        $now = new DateTimeImmutable('2026-05-20 09:00:00');

        self::assertTrue(Cadence::isDue('daily', 3, 1, 1, new DateTimeImmutable('2026-05-19 23:00:00'), $now));
        self::assertFalse(Cadence::isDue('daily', 3, 1, 1, new DateTimeImmutable('2026-05-20 03:00:01'), $now));
    }

    public function testNeverRunIsDue(): void
    {
        self::assertTrue(Cadence::isDue('daily', 3, 1, 1, null, new DateTimeImmutable('2026-05-20 09:00:00')));
    }

    public function testBeforeTheHourTheOccurrenceIsYesterdays(): void
    {
        $now = new DateTimeImmutable('2026-05-20 02:00:00');

        self::assertSame(
            '2026-05-19 03:00:00',
            Cadence::lastOccurrence('daily', 3, 1, 1, $now)->format('Y-m-d H:i:s'),
        );
    }

    public function testWeeklyWalksBackToTheWantedDay(): void
    {
        // Wednesday the 20th, wanting Mondays.
        $now = new DateTimeImmutable('2026-05-20 09:00:00');

        self::assertSame(
            '2026-05-18 03:00:00',
            Cadence::lastOccurrence('weekly', 3, 1, 1, $now)->format('Y-m-d H:i:s'),
        );
    }

    public function testMonthlyDoesNotOverflowIntoTheWrongMonth(): void
    {
        // The day of month is capped at 28 precisely so "-1 month" can never skip February.
        $now = new DateTimeImmutable('2026-03-05 09:00:00');

        self::assertSame(
            '2026-02-28 03:00:00',
            Cadence::lastOccurrence('monthly', 3, 1, 31, $now)->format('Y-m-d H:i:s'),
        );
    }

    public function testNextIsAlwaysAfterNow(): void
    {
        $now = new DateTimeImmutable('2026-05-20 09:00:00');

        foreach (['daily', 'weekly', 'monthly'] as $frequency) {
            self::assertGreaterThan($now, Cadence::nextOccurrence($frequency, 3, 1, 1, $now), $frequency);
        }
    }

    public function testAnOutOfRangeHourIsClamped(): void
    {
        $now = new DateTimeImmutable('2026-05-20 09:00:00');

        self::assertSame('23', Cadence::lastOccurrence('daily', 99, 1, 1, $now)->format('H'));
    }
}
