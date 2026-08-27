<?php

namespace justinholtweb\lock\helpers;

use craft\base\ElementContainerFieldInterface;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use Throwable;

/**
 * Finds an email address inside element content.
 *
 * Most of the personal data on a Craft site is not in a column called `email` — it is a value
 * somebody typed into a field on a form submission, an application, an enquiry. Craft 5 stores
 * all of it in one place, `elements_sites.content`, as JSON, which makes a single indexed-ish
 * `LIKE` the only search that is both complete and affordable.
 *
 * Two details that a naive version gets wrong:
 *
 * - **The needle has to be JSON-escaped.** Craft's `Json::encode()` escapes slashes and quotes,
 *   so an address is stored with the same escaping and a raw `LIKE` misses exactly the values
 *   containing the characters worth escaping.
 * - **The column is keyed by *field layout element* UID**, not by field handle — so a match tells
 *   you an element contains the string, and the field it is in has to be found by asking the
 *   element, never by reading the JSON's keys.
 */
final class ContentScan
{
    /**
     * Element IDs whose content contains the value.
     *
     * @param class-string<ElementInterface> $elementType
     * @return int[]
     */
    public static function matchingIds(string $elementType, string $value, int $limit = 500): array
    {
        $value = trim($value);

        if ($value === '') {
            return [];
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);
        $needles = array_unique([$value, $encoded === false ? $value : substr($encoded, 1, -1)]);

        $conditions = ['or'];

        foreach ($needles as $needle) {
            $conditions[] = ['like', 'es.content', $needle];
        }

        return (new Query())
            ->select(['es.elementId'])
            ->distinct()
            ->from(['es' => Table::ELEMENTS_SITES])
            ->innerJoin(['e' => Table::ELEMENTS], '[[e.id]] = [[es.elementId]]')
            ->where([
                'e.type' => $elementType,
                'e.dateDeleted' => null,
                'e.revisionId' => null,
                'e.draftId' => null,
            ])
            ->andWhere($conditions)
            ->orderBy(['es.elementId' => SORT_DESC])
            ->limit($limit)
            ->column();
    }

    /**
     * Which of an element's custom fields actually contain the value, by handle.
     *
     * @return array<string, string> handle => field name
     */
    public static function matchingFields(ElementInterface $element, string $value): array
    {
        $layout = $element->getFieldLayout();

        if ($layout === null) {
            return [];
        }

        $matches = [];

        foreach ($layout->getCustomFields() as $field) {
            try {
                $serialized = $element->getSerializedFieldValues([$field->handle])[$field->handle] ?? null;
            } catch (Throwable) {
                continue;
            }

            if (self::contains($serialized, $value)) {
                $matches[$field->handle] = $field->name;
            }
        }

        return $matches;
    }

    /**
     * Replaces the value everywhere it appears in an element's fields, and saves.
     *
     * Validation is off deliberately: content edited years ago routinely fails a rule added since,
     * and an anonymisation that a required-field rule can veto is not an anonymisation.
     */
    public static function replace(ElementInterface $element, string $search, string $replacement): bool
    {
        $handles = array_keys(self::matchingFields($element, $search));

        if ($handles === []) {
            return false;
        }

        foreach ($handles as $handle) {
            $value = $element->getSerializedFieldValues([$handle])[$handle] ?? null;
            $element->setFieldValue($handle, self::rewrite($value, $search, $replacement));
        }

        return \Craft::$app->getElements()->saveElement($element, false);
    }

    private static function contains(mixed $value, string $needle): bool
    {
        if (is_string($value)) {
            return stripos($value, $needle) !== false;
        }

        // Only real arrays are containers. Every Craft element is `Traversable` — iterating one
        // yields its attributes, not its children — so `instanceof Traversable` here would walk
        // into an element and quietly search the wrong thing.
        if (is_array($value)) {
            foreach ($value as $item) {
                if (self::contains($item, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function rewrite(mixed $value, string $search, string $replacement): mixed
    {
        if (is_string($value)) {
            return str_ireplace($search, $replacement, $value);
        }

        if (is_array($value)) {
            $out = [];

            foreach ($value as $key => $item) {
                $out[$key] = self::rewrite($item, $search, $replacement);
            }

            return $out;
        }

        return $value;
    }

    /**
     * Whether a field is a container whose children are elements in their own right.
     *
     * Matrix and CKEditor both implement the container interface and serialize completely
     * differently — Matrix to an array of nested entries, CKEditor to an HTML string. Testing the
     * *shape of the value* rather than the interface is what keeps rich text searchable while
     * still leaving nested entries to be scanned as themselves.
     */
    public static function isNestedContainer(mixed $field, mixed $value): bool
    {
        return $field instanceof ElementContainerFieldInterface && is_array($value);
    }
}
