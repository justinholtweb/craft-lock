<?php

namespace justinholtweb\lock\models;

use Craft;
use craft\base\Model;
use DateInterval;
use DateTime;
use DateTimeZone;

/**
 * "Delete this kind of record once it is this old."
 *
 * Storage limitation (Article 5(1)(e)) is the principle nobody implements, because implementing
 * it means writing down how long you keep things and then actually doing it. A rule is both
 * halves at once: the period is the policy, the scheduled run is the compliance.
 *
 * A rule is data, held in project config. See {@see Settings::$retentionRules} for why.
 */
class RetentionRule extends Model
{
    public const UNIT_DAYS = 'days';
    public const UNIT_MONTHS = 'months';
    public const UNIT_YEARS = 'years';

    public const MODE_ERASE = 'erase';
    public const MODE_ANONYMISE = 'anonymise';
    public const MODE_REPORT = 'report';

    public string $key = '';
    public string $label = '';

    /** @var string The {@see RetentionScope} key this applies to. */
    public string $scope = '';

    public int $period = 24;
    public string $unit = self::UNIT_MONTHS;
    public string $mode = self::MODE_ANONYMISE;
    public bool $enabled = false;

    /**
     * @var string Why this period and not another. Required in spirit if not in code — this is the
     *             sentence that goes on the Article 30 register, and a retention schedule with no
     *             justification is a number somebody guessed.
     */
    public string $justification = '';

    /** @var int Most records to touch in one run. Keeps the first run on a large table survivable. */
    public int $limit = 500;

    public static function fromArray(array $row): self
    {
        $rule = new self();
        $rule->key = trim((string)($row['key'] ?? ''));
        $rule->label = trim((string)($row['label'] ?? '')) ?: $rule->key;
        $rule->scope = trim((string)($row['scope'] ?? ''));
        $rule->period = max(0, (int)($row['period'] ?? 24));
        $rule->unit = in_array($row['unit'] ?? '', [self::UNIT_DAYS, self::UNIT_MONTHS, self::UNIT_YEARS], true)
            ? (string)$row['unit']
            : self::UNIT_MONTHS;
        $rule->mode = in_array($row['mode'] ?? '', [self::MODE_ERASE, self::MODE_ANONYMISE, self::MODE_REPORT], true)
            ? (string)$row['mode']
            : self::MODE_ANONYMISE;
        $rule->enabled = !empty($row['enabled']) && $row['enabled'] !== '0';
        $rule->justification = trim((string)($row['justification'] ?? ''));
        $rule->limit = max(1, (int)($row['limit'] ?? 500));

        return $rule;
    }

    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'scope' => $this->scope,
            'period' => $this->period,
            'unit' => $this->unit,
            'mode' => $this->mode,
            'enabled' => $this->enabled,
            'justification' => $this->justification,
            'limit' => $this->limit,
        ];
    }

    /** The collector handle the scope belongs to. */
    public function source(): string
    {
        return explode(':', $this->scope)[0] ?? '';
    }

    /**
     * Anything older than this goes.
     *
     * Built with `DateInterval` rather than `strtotime('-24 months')` arithmetic on a timestamp,
     * so that a rule expressed in months lands on the same day-of-month rather than drifting by
     * the length of whichever months it passed through.
     */
    public function cutoff(?DateTime $now = null): DateTime
    {
        $cutoff = clone($now ?? new DateTime('now', new DateTimeZone(Craft::$app->getTimeZone())));

        $spec = match ($this->unit) {
            self::UNIT_DAYS => "P{$this->period}D",
            self::UNIT_YEARS => "P{$this->period}Y",
            default => "P{$this->period}M",
        };

        return $cutoff->sub(new DateInterval($spec));
    }

    public function periodLabel(): string
    {
        $unit = $this->period === 1 ? rtrim($this->unit, 's') : $this->unit;

        return "$this->period $unit";
    }

    public function modeLabel(): string
    {
        return match ($this->mode) {
            self::MODE_ERASE => Craft::t('lock', 'Delete'),
            self::MODE_ANONYMISE => Craft::t('lock', 'Anonymise'),
            default => Craft::t('lock', 'Report only'),
        };
    }

    /** A rule reads as a sentence, and the sentence is what goes on the register. */
    public function sentence(): string
    {
        return Craft::t('lock', '{mode} {scope} more than {period} old.', [
            'mode' => $this->modeLabel(),
            'scope' => $this->label,
            'period' => $this->periodLabel(),
        ]);
    }

    protected function defineRules(): array
    {
        return [
            [['key', 'scope'], 'required'],
            [['key'], 'match', 'pattern' => '/^[a-z0-9_-]+$/i'],
            [['period'], 'integer', 'min' => 0],
            [['limit'], 'integer', 'min' => 1, 'max' => 100000],
            [['mode'], 'in', 'range' => [self::MODE_ERASE, self::MODE_ANONYMISE, self::MODE_REPORT]],
            [['unit'], 'in', 'range' => [self::UNIT_DAYS, self::UNIT_MONTHS, self::UNIT_YEARS]],
        ];
    }
}
