<?php

namespace justinholtweb\lock\tests\unit;

use justinholtweb\lock\helpers\Address;
use PHPUnit\Framework\TestCase;

/**
 * Finding one address in free text, and only that one.
 *
 * Every source that searches text — entry content, form submissions, log lines — goes through
 * this, for matching and for rewriting. A substring match here is a disclosure of somebody else's
 * data, or an anonymisation that rewrites the middle of a stranger's address.
 */
class AddressTest extends TestCase
{
    public function testFindsTheAddressInASentence(): void
    {
        self::assertTrue(Address::contains('Please contact lex@corp.co about it.', 'lex@corp.co'));
    }

    public function testDoesNotFindItInsideALongerLocalPart(): void
    {
        self::assertFalse(Address::contains('Please contact alex@corp.co about it.', 'lex@corp.co'));
    }

    public function testDoesNotFindItInsideALongerDomain(): void
    {
        self::assertFalse(Address::contains('Write to lex@corp.com today.', 'lex@corp.co'));
        self::assertFalse(Address::contains('Write to lex@corp.co.uk today.', 'lex@corp.co'));
        self::assertFalse(Address::contains('Write to lex@corp.co-op.org today.', 'lex@corp.co'));
    }

    public function testAFullStopAfterTheAddressIsNotPartOfIt(): void
    {
        self::assertTrue(Address::contains('My address is lex@corp.co.', 'lex@corp.co'));
    }

    public function testIsCaseInsensitive(): void
    {
        self::assertTrue(Address::contains('From: Lex@Corp.CO', 'lex@corp.co'));
    }

    public function testFindsTheJsonEscapedForm(): void
    {
        self::assertTrue(Address::contains('{"a":"o\/b@x.io"}', 'o/b@x.io'));
    }

    public function testReplacesOnlyTheExactAddress(): void
    {
        $text = 'cc alex@corp.co, lex@corp.co and lex@corp.com.';

        self::assertSame(
            'cc alex@corp.co, anon@x.invalid and lex@corp.com.',
            Address::replace($text, 'lex@corp.co', 'anon@x.invalid'),
        );
    }

    public function testReplacementIsLiteral(): void
    {
        self::assertSame('to $1\\0 now', Address::replace('to lex@corp.co now', 'lex@corp.co', '$1\\0'));
    }

    public function testWalksArraysButNotOtherValues(): void
    {
        $value = ['a' => 'lex@corp.co', 'b' => ['alex@corp.co', 'x lex@corp.co'], 'c' => 7];

        self::assertTrue(Address::containsDeep($value, 'lex@corp.co'));
        self::assertSame(
            ['a' => 'P', 'b' => ['alex@corp.co', 'x P'], 'c' => 7],
            Address::replaceDeep($value, 'lex@corp.co', 'P'),
        );
    }

    public function testRegexCharactersInTheAddressAreLiteral(): void
    {
        self::assertFalse(Address::contains('lexXcorp.co', 'lex.corp.co'));
        self::assertTrue(Address::contains('o+tag@corp.co', 'o+tag@corp.co'));
    }
}
