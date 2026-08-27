<?php

namespace justinholtweb\lock\models;

use Craft;
use craft\base\Model;
use DateTime;

/**
 * One entry in the record of processing activities — the Article 30 register.
 *
 * Held in the database rather than project config, unlike retention rules, because the register
 * is a living document a data protection officer maintains and prints, not a deployable
 * behaviour. Nothing in it changes what the site *does*.
 */
class ProcessingActivity extends Model
{
    public const BASIS_CONSENT = 'consent';
    public const BASIS_CONTRACT = 'contract';
    public const BASIS_OBLIGATION = 'legal_obligation';
    public const BASIS_VITAL = 'vital_interests';
    public const BASIS_PUBLIC = 'public_task';
    public const BASIS_LEGITIMATE = 'legitimate_interests';

    public ?int $id = null;
    public ?string $uid = null;

    public string $name = '';

    /** @var string Article 30(1)(b) — why the processing happens. */
    public string $purpose = '';

    /** @var string One of the six Article 6 bases. There is no seventh. */
    public string $basis = self::BASIS_LEGITIMATE;

    /** @var string|null Needed when the basis is legitimate interests: the interest, and the balancing. */
    public ?string $balancing = null;

    /** @var string[] Article 30(1)(c) — categories of personal data. */
    public array $dataCategories = [];

    /** @var string[] Categories of data subject: customers, employees, applicants. */
    public array $subjectCategories = [];

    /** @var string[] Article 30(1)(d) — who it is disclosed to. Processors count. */
    public array $recipients = [];

    /** @var string|null Article 30(1)(e) — transfers outside the EEA, and the safeguard relied on. */
    public ?string $transfers = null;

    /** @var string Article 30(1)(f) — the retention period, in words. */
    public string $retention = '';

    /** @var string|null Article 30(1)(g) — the security measures, in outline. */
    public ?string $safeguards = null;

    /** @var string[] Which systems hold it. Cross-referenced against Lock's collectors. */
    public array $systems = [];

    /** @var string|null Who owns this activity internally. */
    public ?string $owner = null;

    public bool $enabled = true;
    public int $sortOrder = 0;

    public ?DateTime $reviewedAt = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;

    public static function bases(): array
    {
        return [
            self::BASIS_CONSENT => Craft::t('lock', 'Consent — Article 6(1)(a)'),
            self::BASIS_CONTRACT => Craft::t('lock', 'Performance of a contract — Article 6(1)(b)'),
            self::BASIS_OBLIGATION => Craft::t('lock', 'Legal obligation — Article 6(1)(c)'),
            self::BASIS_VITAL => Craft::t('lock', 'Vital interests — Article 6(1)(d)'),
            self::BASIS_PUBLIC => Craft::t('lock', 'Public task — Article 6(1)(e)'),
            self::BASIS_LEGITIMATE => Craft::t('lock', 'Legitimate interests — Article 6(1)(f)'),
        ];
    }

    public function basisLabel(): string
    {
        return self::bases()[$this->basis] ?? $this->basis;
    }

    /** Legitimate interests without a balancing test written down is the commonest register defect. */
    public function isIncomplete(): bool
    {
        if ($this->purpose === '' || $this->retention === '') {
            return true;
        }

        return $this->basis === self::BASIS_LEGITIMATE && ($this->balancing === null || trim($this->balancing) === '');
    }

    /** @return string[] */
    public function defects(): array
    {
        $defects = [];

        if (trim($this->purpose) === '') {
            $defects[] = Craft::t('lock', 'No purpose recorded — Article 30(1)(b).');
        }

        if (trim($this->retention) === '') {
            $defects[] = Craft::t('lock', 'No retention period recorded — Article 30(1)(f).');
        }

        if ($this->basis === self::BASIS_LEGITIMATE && trim((string)$this->balancing) === '') {
            $defects[] = Craft::t('lock', 'Legitimate interests claimed with no balancing test written down.');
        }

        if ($this->dataCategories === []) {
            $defects[] = Craft::t('lock', 'No categories of personal data listed — Article 30(1)(c).');
        }

        if ($this->safeguards === null || trim($this->safeguards) === '') {
            $defects[] = Craft::t('lock', 'No security measures described — Article 30(1)(g).');
        }

        return $defects;
    }

    protected function defineRules(): array
    {
        return [
            [['name'], 'required'],
            [['basis'], 'in', 'range' => array_keys(self::bases())],
            [['name'], 'string', 'max' => 255],
        ];
    }
}
