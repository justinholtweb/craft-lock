<?php

namespace justinholtweb\lock\models;

use craft\base\Model;

/**
 * Everything one collector found about one subject, plus how the search went.
 *
 * A collector that failed produces a bundle with an error on it rather than throwing, because
 * "the orders table was unreachable" must appear *in* the dossier. A disclosure assembled from
 * nine sources out of ten, with no note saying so, is worse than no disclosure — it looks
 * complete.
 */
class Bundle extends Model
{
    public string $source = '';
    public string $sourceLabel = '';

    /** @var DataRecord[] */
    public array $records = [];

    /** @var string[] Things worth saying about the search — what was scanned, what was skipped. */
    public array $notes = [];

    /** @var string[] Things that went wrong. Their presence makes the dossier partial. */
    public array $errors = [];

    /** @var float Seconds the collector took. */
    public float $duration = 0.0;

    /** @var bool Whether the source was searched at all — false when it's disabled or absent. */
    public bool $searched = true;

    /** @var string|null Why it wasn't searched. */
    public ?string $skipReason = null;

    public function count(): int
    {
        return count($this->records);
    }

    public function isEmpty(): bool
    {
        return $this->records === [];
    }

    public function hasErrors($attribute = null): bool
    {
        return $this->errors !== [] || parent::hasErrors($attribute);
    }

    /** @return string[] */
    public function categories(): array
    {
        $categories = [];

        foreach ($this->records as $record) {
            foreach ($record->categories as $category) {
                $categories[$category] = true;
            }
        }

        return array_keys($categories);
    }
}
