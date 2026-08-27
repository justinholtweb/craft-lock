<?php

namespace justinholtweb\lock\models;

use craft\base\Model;

/**
 * A sweep a collector knows how to offer: "orders abandoned before X", "submissions older than X".
 *
 * Scopes exist so that a retention rule can be written as policy — a period and a treatment —
 * without the person writing it having to know a table name. The collector that owns the data
 * owns the definition of what "old" means for it, because only it knows which date column is the
 * one that matters.
 */
class RetentionScope extends Model
{
    /** @var string Collector-qualified, e.g. `commerce:carts`. Unique across the site. */
    public string $key = '';

    public string $label = '';
    public string $description = '';

    /** @var string Handle of the collector that owns it. */
    public string $source = '';

    /** @var string Which date the period is measured from, in words: "last updated", "order placed". */
    public string $measuredFrom = '';

    /** @var bool Whether records in this scope can be deleted outright. */
    public bool $erasable = true;

    /** @var bool Whether they can be anonymised in place instead. */
    public bool $anonymisable = true;

    /** @var int|null A sensible default period in months, for the rule builder's placeholder. */
    public ?int $suggestedMonths = null;

    /** @var string|null The usual reason for keeping this data as long as it is kept. */
    public ?string $rationale = null;
}
