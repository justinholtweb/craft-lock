<?php

namespace justinholtweb\lock\helpers;

/**
 * Finding one email address inside free text, and only that address.
 *
 * A database `LIKE '%lex@corp.co%'` matches `alex@corp.com`, and so does `stripos()`. In a
 * disclosure that hands one person's enquiry to somebody else; in an anonymisation it rewrites
 * the middle of a stranger's address and leaves `a` + pseudonym + `m` behind. So every source
 * that searches text does it in two steps: `LIKE` as a cheap prefilter, then this — an exact,
 * case-insensitive match that refuses to start or end inside a longer address.
 *
 * Matching and rewriting share the one pattern, so a value is only ever changed where it was
 * found, and only ever found where it would be changed.
 */
final class Address
{
    /**
     * The pattern for one address, bounded on both sides.
     *
     * Before it: nothing that could be part of a local part. After it: nothing that could continue
     * the domain — a letter, a digit, a hyphen, or a dot followed by more domain. A dot followed by
     * a space or the end of the text is a full stop, and an address at the end of a sentence is
     * still that address.
     */
    public static function pattern(string $email): string
    {
        return '/(?<![A-Za-z0-9._%+-])' . preg_quote($email, '/') . '(?![A-Za-z0-9-]|\.[A-Za-z0-9])/i';
    }

    /**
     * The forms an address can be stored in: as typed, and JSON-escaped.
     *
     * Craft's `Json::encode()` escapes slashes and quotes, so a raw needle misses a JSON column's
     * copy of exactly the addresses that contain them.
     *
     * @return string[]
     */
    public static function needles(string $email): array
    {
        $email = trim($email);

        if ($email === '') {
            return [];
        }

        $encoded = json_encode($email, JSON_UNESCAPED_UNICODE);
        $inner = $encoded === false ? $email : substr($encoded, 1, -1);

        return array_values(array_unique([$email, $inner]));
    }

    /** Whether the text contains this exact address, in either stored form. */
    public static function contains(?string $text, string $email): bool
    {
        if ($text === null || $text === '') {
            return false;
        }

        foreach (self::needles($email) as $needle) {
            if (preg_match(self::pattern($needle), $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /** Replaces this exact address, in either stored form, and nothing that merely contains it. */
    public static function replace(string $text, string $email, string $replacement): string
    {
        foreach (self::needles($email) as $needle) {
            $text = preg_replace(self::pattern($needle), addcslashes($replacement, '\\$'), $text) ?? $text;
        }

        return $text;
    }

    /** Whether anything inside a value — a string, or an array of them at any depth — contains it. */
    public static function containsDeep(mixed $value, string $email): bool
    {
        if (is_string($value)) {
            return self::contains($value, $email);
        }

        // Only real arrays are containers. Every Craft element is `Traversable`, and walking one
        // yields its attributes rather than its children.
        if (is_array($value)) {
            foreach ($value as $item) {
                if (self::containsDeep($item, $email)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** {@see replace()}, through arrays at any depth. Anything that is not text is left alone. */
    public static function replaceDeep(mixed $value, string $email, string $replacement): mixed
    {
        if (is_string($value)) {
            return self::replace($value, $email, $replacement);
        }

        if (is_array($value)) {
            $out = [];

            foreach ($value as $key => $item) {
                $out[$key] = self::replaceDeep($item, $email, $replacement);
            }

            return $out;
        }

        return $value;
    }
}
