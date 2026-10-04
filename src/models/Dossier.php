<?php

namespace justinholtweb\lock\models;

use Craft;
use craft\base\Model;
use DateTime;
use justinholtweb\lock\Plugin;

/**
 * "Everything we hold on this person", assembled.
 *
 * The one object the whole plugin exists to produce. It is also what the erasure planner reads,
 * so that what was disclosed and what gets deleted are answers derived from the same search
 * rather than two searches that agreed on the day they were written.
 */
class Dossier extends Model
{
    public Subject $subject;

    /** @var Bundle[] Keyed by collector handle. */
    public array $bundles = [];

    public ?DateTime $builtAt = null;
    public float $duration = 0.0;

    /** @var int|null The request this was assembled for, if any. */
    public ?int $requestId = null;

    public function __construct(?Subject $subject = null, array $config = [])
    {
        $this->subject = $subject ?? new Subject();
        parent::__construct($config);
    }

    /** @return DataRecord[] */
    public function records(): array
    {
        $records = [];

        foreach ($this->bundles as $bundle) {
            foreach ($bundle->records as $record) {
                $records[] = $record;
            }
        }

        return $records;
    }

    public function count(): int
    {
        return count($this->records());
    }

    /** @return Bundle[] Only the ones that found something. */
    public function populatedBundles(): array
    {
        return array_filter($this->bundles, static fn(Bundle $b) => !$b->isEmpty());
    }

    /**
     * Whether anything stopped the assembly from being complete.
     *
     * Surfaced on the dossier itself and in the export, because a partial disclosure that does not
     * announce itself is the failure mode that turns a handled request into a complaint.
     */
    public function isPartial(): bool
    {
        foreach ($this->bundles as $bundle) {
            if ($bundle->errors !== []) {
                return true;
            }
        }

        return false;
    }

    /** @return string[] */
    public function problems(): array
    {
        $problems = [];

        foreach ($this->bundles as $bundle) {
            foreach ($bundle->errors as $error) {
                $problems[] = "$bundle->sourceLabel: $error";
            }
        }

        return $problems;
    }

    /** @return string[] */
    public function categories(): array
    {
        $categories = [];

        foreach ($this->bundles as $bundle) {
            foreach ($bundle->categories() as $category) {
                $categories[$category] = true;
            }
        }

        return array_keys($categories);
    }

    /** @return DataRecord[] Records that cannot be deleted, with the reason. */
    public function retained(): array
    {
        return array_values(array_filter(
            $this->records(),
            static fn(DataRecord $r) => $r->retainReason !== null,
        ));
    }

    /**
     * The disclosure, as data. This is what the subject receives — JSON here, and the same array
     * is what the CSV and HTML renderings walk, so the three formats cannot describe different
     * holdings.
     */
    public function toDisclosure(): array
    {
        /** @var \justinholtweb\lock\models\Settings $settings */
        $settings = Plugin::getInstance()->getSettings();

        $sources = [];

        foreach ($this->bundles as $bundle) {
            if ($bundle->isEmpty() && $bundle->errors === []) {
                continue;
            }

            $sources[] = array_filter([
                'source' => $bundle->sourceLabel,
                'records_found' => $bundle->count(),
                'notes' => $bundle->notes,
                'problems' => $bundle->errors,
                'records' => array_map(static fn(DataRecord $r) => $r->toDisclosure(), $bundle->records),
            ], static fn($v) => $v !== []);
        }

        return [
            'disclosure' => [
                'about' => array_filter([
                    'name' => $this->subject->name,
                    'email' => $this->subject->email,
                    'account' => $this->subject->userId !== null ? 'yes' : 'no',
                ]),
                'prepared_by' => $settings->organisationName ?: Craft::$app->getSystemName(),
                'prepared_on' => $this->builtAt?->format(DateTime::ATOM),
                'contact' => $settings->resolvedContactEmail(),
                'privacy_policy' => $settings->policyUrl ?: null,
                'records_total' => $this->count(),
                'complete' => !$this->isPartial(),
                'sources_searched' => array_map(
                    static fn(Bundle $b) => $b->sourceLabel,
                    array_filter($this->bundles, static fn(Bundle $b) => $b->searched),
                ),
                'sources_skipped' => array_values(array_map(
                    static fn(Bundle $b) => ['source' => $b->sourceLabel, 'reason' => $b->skipReason],
                    array_filter($this->bundles, static fn(Bundle $b) => !$b->searched),
                )),
            ],
            'held' => $sources,
        ];
    }
}
