<?php

namespace justinholtweb\lock\helpers;

use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use DateTimeInterface;

/**
 * Turns whatever a Craft field hands back into something a person can read in a disclosure.
 *
 * Article 15 wants the data "in a concise, transparent, intelligible and easily accessible form".
 * `{"blocks":[{"__assoc__":[...]}]}` is none of those things, and neither is a Matrix field
 * printed with `var_export`. So values are flattened towards strings, with elements reduced to
 * their titles and dates to ISO-8601.
 */
final class Readable
{
    /** How deep to walk before giving up and describing the shape instead. */
    private const MAX_DEPTH = 4;

    /** Longest single value included verbatim. Beyond this it is truncated and said to be. */
    private const MAX_LENGTH = 4000;

    public static function value(mixed $value, int $depth = 0): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if ($depth >= self::MAX_DEPTH) {
            return '…';
        }

        // Element queries are lazy; resolving one is the only way to see what it holds. Checking
        // for the query rather than for `Traversable` matters: every Craft element is itself
        // traversable, and iterating one yields its *attributes*, not anything useful.
        if ($value instanceof ElementQueryInterface) {
            $value = $value->all();
        }

        if ($value instanceof ElementInterface) {
            return self::element($value);
        }

        if (is_array($value)) {
            $out = [];

            foreach ($value as $key => $item) {
                $out[$key] = self::value($item, $depth + 1);
            }

            return $out;
        }

        if (is_object($value)) {
            if (method_exists($value, 'toArray')) {
                return self::value($value->toArray(), $depth + 1);
            }

            if (method_exists($value, '__toString')) {
                return self::string((string)$value);
            }

            return get_class($value);
        }

        return self::string((string)$value);
    }

    public static function element(ElementInterface $element): string
    {
        $title = (string)$element;

        return $title !== '' ? $title : sprintf('%s #%s', $element::displayName(), $element->id);
    }

    public static function string(string $value): string
    {
        $value = trim($value);

        if (mb_strlen($value) <= self::MAX_LENGTH) {
            return $value;
        }

        return mb_substr($value, 0, self::MAX_LENGTH) . '… [truncated]';
    }

    /**
     * Whether a value is worth putting in a disclosure at all.
     *
     * Empty is not "nothing held" — but a page of empty rows makes the rows that matter harder to
     * find, and the covering note already says which sources were searched.
     */
    public static function isEmpty(mixed $value): bool
    {
        if ($value === null || $value === '' || $value === []) {
            return true;
        }

        return is_array($value) && array_filter($value, static fn($v) => !self::isEmpty($v)) === [];
    }

    /**
     * Flattens a nested value into `parent → child` keys, one level of prefixing per depth.
     *
     * @return array<string, mixed>
     */
    public static function flatten(array $value, string $prefix = ''): array
    {
        $flat = [];

        foreach ($value as $key => $item) {
            $label = $prefix === '' ? (string)$key : "$prefix → $key";

            if (is_array($item) && $item !== [] && !array_is_list($item)) {
                $flat += self::flatten($item, $label);
                continue;
            }

            if (is_array($item) && array_is_list($item)) {
                $scalars = array_filter($item, static fn($v) => is_scalar($v));

                if (count($scalars) === count($item)) {
                    $flat[$label] = implode(', ', array_map('strval', $item));
                    continue;
                }
            }

            $flat[$label] = $item;
        }

        return $flat;
    }
}
