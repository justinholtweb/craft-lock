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
 * Three details that a naive version gets wrong:
 *
 * - **`LIKE` is a prefilter, not the match.** `%lex@corp.co%` matches `alex@corp.com`, so every
 *   candidate is held to the bounded match in {@see Address} before it counts — and the same
 *   pattern does the rewriting, so nothing is changed that was not found.
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

        $conditions = ['or'];

        foreach (Address::needles($value) as $needle) {
            $conditions[] = ['like', 'es.content', $needle];
        }

        // `LIKE` is only the prefilter: `%lex@corp.co%` also matches `alex@corp.com`. Every
        // candidate row is then held to an exact, bounded match on its stored JSON, which is why
        // the query over-fetches and the limit is applied afterwards.
        $rows = (new Query())
            ->select(['es.elementId', 'es.content'])
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
            ->limit($limit * 5)
            ->all();

        $ids = [];

        foreach ($rows as $row) {
            $id = (int)$row['elementId'];

            if (!isset($ids[$id]) && Address::contains(is_string($row['content']) ? $row['content'] : null, $value)) {
                $ids[$id] = $id;
            }
        }

        return array_slice(array_values($ids), 0, $limit);
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

            if (Address::containsDeep($serialized, $value)) {
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
            $element->setFieldValue($handle, Address::replaceDeep($value, $search, $replacement));
        }

        return \Craft::$app->getElements()->saveElement($element, false);
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
