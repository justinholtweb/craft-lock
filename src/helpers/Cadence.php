<?php

namespace justinholtweb\lock\helpers;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Works out when a scheduled retention run was last supposed to run, and when it next will.
 *
 * Deliberately free of Craft: it takes numbers and dates and returns dates, which is what makes
 * "does a monthly schedule fire correctly on the 1st when the last run was on the 31st" a unit
 * test rather than a thing you find out in production.
 *
 * The model throughout is *occurrences*. A schedule is due when the most recent occurrence is
 * newer than the last run — not when "a week has passed", which drifts a little later every week
 * and eventually lands a 3am purge in the middle of the working day.
 */
final class Cadence
{
    public const DAILY = 'daily';
    public const WEEKLY = 'weekly';
    public const MONTHLY = 'monthly';

    /**
     * The most recent scheduled moment at or before `$now`.
     *
     * @param int $hour 0–23
     * @param int $weekday 1 (Monday) – 7 (Sunday), ISO-8601
     * @param int $dayOfMonth 1–28
     */
    public static function lastOccurrence(
        string $frequency,
        int $hour,
        int $weekday,
        int $dayOfMonth,
        DateTimeInterface $now,
    ): DateTimeImmutable {
        $now = self::immutable($now);
        $hour = max(0, min(23, $hour));

        return match ($frequency) {
            self::DAILY => self::lastDaily($hour, $now),
            self::MONTHLY => self::lastMonthly($hour, max(1, min(28, $dayOfMonth)), $now),
            default => self::lastWeekly($hour, max(1, min(7, $weekday)), $now),
        };
    }

    /**
     * The next scheduled moment strictly after `$now`.
     */
    public static function nextOccurrence(
        string $frequency,
        int $hour,
        int $weekday,
        int $dayOfMonth,
        DateTimeInterface $now,
    ): DateTimeImmutable {
        $last = self::lastOccurrence($frequency, $hour, $weekday, $dayOfMonth, $now);

        return match ($frequency) {
            self::DAILY => $last->modify('+1 day'),
            self::MONTHLY => $last->modify('+1 month'),
            default => $last->modify('+7 days'),
        };
    }

    /**
     * whether a run is owed, given when one last ran.
     *
     * A null `$lastRun` is due: a schedule that has never run should run at the first opportunity
     * rather than waiting for its second occurrence.
     */
    public static function isDue(
        string $frequency,
        int $hour,
        int $weekday,
        int $dayOfMonth,
        ?DateTimeInterface $lastRun,
        DateTimeInterface $now,
    ): bool {
        if ($lastRun === null) {
            return true;
        }

        $occurrence = self::lastOccurrence($frequency, $hour, $weekday, $dayOfMonth, $now);

        return self::immutable($lastRun) < $occurrence;
    }

    private static function lastDaily(int $hour, DateTimeImmutable $now): DateTimeImmutable
    {
        $today = $now->setTime($hour, 0, 0);

        return $today <= $now ? $today : $today->modify('-1 day');
    }

    private static function lastWeekly(int $hour, int $weekday, DateTimeImmutable $now): DateTimeImmutable
    {
        $candidate = $now->setTime($hour, 0, 0);

        // Walk back to the wanted weekday. At most seven steps, and no reliance on strtotime's
        // "last Monday", which means something different depending on what day it already is.
        $steps = ((int)$candidate->format('N') - $weekday + 7) % 7;
        $candidate = $candidate->modify("-$steps days");

        return $candidate <= $now ? $candidate : $candidate->modify('-7 days');
    }

    private static function lastMonthly(int $hour, int $dayOfMonth, DateTimeImmutable $now): DateTimeImmutable
    {
        $candidate = $now->setDate((int)$now->format('Y'), (int)$now->format('n'), $dayOfMonth)->setTime($hour, 0, 0);

        if ($candidate <= $now) {
            return $candidate;
        }

        // `-1 month` from a date capped at the 28th can never overflow into the wrong month,
        // which is exactly why the setting is capped at 28.
        $previous = $now->modify('first day of last month');

        return $previous->setDate((int)$previous->format('Y'), (int)$previous->format('n'), $dayOfMonth)->setTime($hour, 0, 0);
    }

    private static function immutable(DateTimeInterface $date): DateTimeImmutable
    {
        return $date instanceof DateTimeImmutable
            ? $date
            : DateTimeImmutable::createFromFormat('U', (string)$date->getTimestamp(), new DateTimeZone(date_default_timezone_get()))
                ->setTimezone($date->getTimezone());
    }
}
