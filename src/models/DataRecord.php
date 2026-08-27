<?php

namespace justinholtweb\lock\models;

use craft\base\Model;
use DateTime;

/**
 * One thing the site holds about a person.
 *
 * Deliberately flat. A dossier that mirrors the site's own schema is a dossier only its developer
 * can read, and Article 15 asks for the data "in a concise, transparent, intelligible and easily
 * accessible form" — which rules out handing somebody a database dump and calling it a disclosure.
 *
 * The three booleans at the bottom are the interesting part. A record that can be *found* but not
 * *erased* has to say so out loud, with a reason, because the alternative is a compliance report
 * that quietly overstates what happened.
 */
class DataRecord extends Model
{
    // Article 30(1)(c) categories, kept short enough to be useful on a screen.
    public const CATEGORY_IDENTITY = 'identity';
    public const CATEGORY_CONTACT = 'contact';
    public const CATEGORY_ACCOUNT = 'account';
    public const CATEGORY_FINANCIAL = 'financial';
    public const CATEGORY_BEHAVIOUR = 'behaviour';
    public const CATEGORY_CONTENT = 'content';
    public const CATEGORY_TECHNICAL = 'technical';
    public const CATEGORY_SPECIAL = 'special';

    public const KIND_ELEMENT = 'element';
    public const KIND_ROW = 'row';
    public const KIND_FILE = 'file';
    public const KIND_LOG = 'log';

    /** @var string Handle of the collector that found it. */
    public string $source = '';

    /** @var string Human name of the source, e.g. "Commerce orders". */
    public string $sourceLabel = '';

    /**
     * @var string An identifier the same collector can resolve again later, e.g. `order:1042`.
     *             Erasure re-reads this rather than re-running the search, which is what keeps
     *             the preview and the execution talking about the same rows.
     */
    public string $key = '';

    /** @var string What this is, in a few words. Shown to the subject. */
    public string $label = '';

    public string $kind = self::KIND_ROW;

    /** @var array<string, mixed> The personal data itself: readable field name => value. */
    public array $data = [];

    /** @var string[] Which categories of personal data this record contains. */
    public array $categories = [];

    /** @var string|null Control-panel URL, for staff. Never included in the subject's copy. */
    public ?string $cpUrl = null;

    public ?DateTime $dateCreated = null;

    /** @var string|null Why the site holds it — the lawful basis, in a sentence. */
    public ?string $basis = null;

    /** @var string|null How long the site keeps it, in a sentence. */
    public ?string $retention = null;

    /** @var bool Whether this record can be deleted outright. */
    public bool $erasable = true;

    /** @var bool Whether the personal data in it can be overwritten while the record survives. */
    public bool $anonymisable = true;

    /**
     * @var string|null Why it must be kept regardless. A tax-relevant order, an audit record, a
     *                  log the site cannot selectively rewrite. Article 17(3) exemptions live here,
     *                  and they are shown to the subject too — "we kept this, and here is why" is
     *                  a lawful answer; silence is not.
     */
    public ?string $retainReason = null;

    /** The shape that goes into the subject's export. Staff-only fields are not in it. */
    public function toDisclosure(): array
    {
        return array_filter([
            'source' => $this->sourceLabel,
            'record' => $this->label,
            'held_since' => $this->dateCreated?->format('Y-m-d'),
            'categories' => $this->categories,
            'lawful_basis' => $this->basis,
            'retention' => $this->retention,
            'kept_because' => $this->retainReason,
            'data' => $this->data,
        ], static fn($v) => $v !== null && $v !== [] && $v !== '');
    }
}
