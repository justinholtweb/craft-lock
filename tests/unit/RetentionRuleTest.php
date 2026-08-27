<?php

namespace justinholtweb\lock\tests\unit;

use DateTime;
use justinholtweb\lock\models\RetentionRule;
use PHPUnit\Framework\TestCase;

/**
 * Retention cutoffs.
 *
 * The rules arrive from an editable table, so every value is a string, and half of them may be
 * nonsense. What must never happen is a rule that throws mid-sweep or silently becomes a wider
 * deletion than it was written as.
 */
class RetentionRuleTest extends TestCase
{
    public function testStringsFromTheTableBecomeTypedValues(): void
    {
        $rule = RetentionRule::fromArray([
            'key' => 'carts',
            'scope' => 'commerce:carts',
            'period' => '3',
            'unit' => 'months',
            'mode' => 'erase',
            'enabled' => '1',
            'limit' => '250',
        ]);

        self::assertSame(3, $rule->period);
        self::assertSame(250, $rule->limit);
        self::assertTrue($rule->enabled);
    }

    public function testAnUncheckedCheckboxIsFalseRatherThanTruthy(): void
    {
        // Craft's editable table posts '0' for an unticked checkbox, and '0' is a non-empty string.
        self::assertFalse(RetentionRule::fromArray(['key' => 'k', 'scope' => 's', 'enabled' => '0'])->enabled);
    }

    public function testUnknownUnitsAndModesFallBackRatherThanThrow(): void
    {
        $rule = RetentionRule::fromArray(['key' => 'k', 'scope' => 's', 'unit' => 'fortnights', 'mode' => 'incinerate']);

        self::assertSame(RetentionRule::UNIT_MONTHS, $rule->unit);
        self::assertSame(RetentionRule::MODE_ANONYMISE, $rule->mode);
    }

    public function testTheSafeFallbackIsAnonymiseNotErase(): void
    {
        // A rule whose mode is unreadable must never widen into a deletion.
        self::assertSame(RetentionRule::MODE_ANONYMISE, RetentionRule::fromArray(['key' => 'k', 'scope' => 's'])->mode);
    }

    public function testDaysAreExact(): void
    {
        $rule = RetentionRule::fromArray(['key' => 'k', 'scope' => 's', 'period' => 30, 'unit' => 'days']);

        self::assertSame('2026-05-01', $rule->cutoff(new DateTime('2026-05-31 00:00:00'))->format('Y-m-d'));
    }

    public function testMonthsAreCalendarMonthsNotThirtyDays(): void
    {
        $rule = RetentionRule::fromArray(['key' => 'k', 'scope' => 's', 'period' => 1, 'unit' => 'months']);

        // February is 28 days; a "1 month" rule expressed as 30 days would land in January.
        self::assertSame('2026-02-15', $rule->cutoff(new DateTime('2026-03-15 00:00:00'))->format('Y-m-d'));
    }

    public function testYearsAreCalendarYears(): void
    {
        $rule = RetentionRule::fromArray(['key' => 'k', 'scope' => 's', 'period' => 7, 'unit' => 'years']);

        self::assertSame('2019-05-31', $rule->cutoff(new DateTime('2026-05-31 00:00:00'))->format('Y-m-d'));
    }

    public function testAZeroPeriodMeansEverything(): void
    {
        $rule = RetentionRule::fromArray(['key' => 'k', 'scope' => 's', 'period' => 0, 'unit' => 'days']);
        $now = new DateTime('2026-05-31 12:00:00');

        self::assertSame($now->format('Y-m-d H:i'), $rule->cutoff($now)->format('Y-m-d H:i'));
    }

    public function testTheSourceIsTakenFromTheScopePrefix(): void
    {
        self::assertSame('commerce', RetentionRule::fromArray(['key' => 'k', 'scope' => 'commerce:orders'])->source());
    }

    public function testAPeriodOfOneIsSingular(): void
    {
        self::assertSame('1 month', RetentionRule::fromArray(['key' => 'k', 'scope' => 's', 'period' => 1])->periodLabel());
        self::assertSame('24 months', RetentionRule::fromArray(['key' => 'k', 'scope' => 's', 'period' => 24])->periodLabel());
    }

    public function testARoundTripThroughTheTableShapeIsLossless(): void
    {
        $rule = RetentionRule::fromArray([
            'key' => 'k',
            'label' => 'Submissions',
            'scope' => 'formie:submissions',
            'period' => 24,
            'unit' => 'months',
            'mode' => 'anonymise',
            'enabled' => true,
            'justification' => 'Nobody reads an enquiry from two years ago.',
            'limit' => 500,
        ]);

        self::assertEquals($rule->toArray(), RetentionRule::fromArray($rule->toArray())->toArray());
    }
}
