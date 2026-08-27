<?php

namespace justinholtweb\lock\models;

use craft\base\Model;

/**
 * One decided action against one record.
 *
 * A target is not a query — it names a specific record that a specific collector has already
 * found. That is deliberate. If the plan re-ran the search at execution time, the operator would
 * have approved a preview of a *different* deletion: rows added in between would be swept up
 * silently, and rows the preview listed might be gone. See {@see \justinholtweb\lock\services\Erasure}.
 */
class ErasureTarget extends Model
{
    public const ACTION_ERASE = 'erase';
    public const ACTION_ANONYMISE = 'anonymise';
    public const ACTION_SKIP = 'skip';

    public string $source = '';
    public string $sourceLabel = '';
    public string $key = '';
    public string $label = '';
    public string $action = self::ACTION_ANONYMISE;

    /** @var string|null Why the action is `skip`, or why it was downgraded from erase to anonymise. */
    public ?string $reason = null;

    /** @var string[] Which fields anonymising will overwrite. Shown in the preview so it isn't a surprise. */
    public array $fields = [];

    /**
     * @var string|null Whose record this is, when that is not the plan's subject.
     *
     * A subject erasure has one person and this stays null. A retention sweep has a different
     * person on every row, and each has to get their *own* pseudonym — give ten thousand
     * anonymised orders one shared pseudonym and they become provably the same customer again,
     * which un-anonymises the lot.
     */
    public ?string $subjectEmail = null;

    public function isSkipped(): bool
    {
        return $this->action === self::ACTION_SKIP;
    }

    public function actionLabel(): string
    {
        return match ($this->action) {
            self::ACTION_ERASE => 'Delete',
            self::ACTION_ANONYMISE => 'Anonymise',
            default => 'Keep',
        };
    }
}
