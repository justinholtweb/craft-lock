<?php

namespace justinholtweb\lock\models;

use craft\base\Model;
use DateTime;

/**
 * What is about to happen to a subject's data, decided in full before anything happens.
 *
 * The plan is the contract between the preview screen and the execution. Both read this same
 * object; the executor never goes back to the collectors to ask what to delete.
 */
class ErasurePlan extends Model
{
    public Subject $subject;

    /** @var string The mode asked for. Individual targets may be downgraded from it, with a reason. */
    public string $mode = Settings::MODE_ANONYMISE;

    /** @var ErasureTarget[] */
    public array $targets = [];

    /** @var string[] Sources that could not be planned at all. */
    public array $errors = [];

    /** @var int|null The request this plan answers. */
    public ?int $requestId = null;

    public ?DateTime $builtAt = null;

    /** @var bool Whether a legal hold or an open request blocks this plan entirely. */
    public bool $blocked = false;

    public ?string $blockReason = null;

    public function __construct(?Subject $subject = null, array $config = [])
    {
        $this->subject = $subject ?? new Subject();
        parent::__construct($config);
    }

    /** @return ErasureTarget[] */
    public function actionable(): array
    {
        return array_values(array_filter($this->targets, static fn(ErasureTarget $t) => !$t->isSkipped()));
    }

    /** @return ErasureTarget[] */
    public function skipped(): array
    {
        return array_values(array_filter($this->targets, static fn(ErasureTarget $t) => $t->isSkipped()));
    }

    public function countBy(string $action): int
    {
        return count(array_filter($this->targets, static fn(ErasureTarget $t) => $t->action === $action));
    }

    public function isEmpty(): bool
    {
        return $this->actionable() === [];
    }

    /**
     * A fingerprint of exactly which records, in which order, get which treatment.
     *
     * Recorded with the preview and checked at execution. If the two differ, something re-planned
     * between the approval and the button, and the run stops rather than deleting a set nobody
     * looked at.
     */
    public function fingerprint(): string
    {
        $parts = array_map(
            static fn(ErasureTarget $t) => "$t->source|$t->key|$t->action",
            $this->targets,
        );

        return hash('sha256', implode("\n", $parts));
    }

    /** @return array<string, array{label: string, erase: int, anonymise: int, skip: int}> */
    public function bySource(): array
    {
        $grouped = [];

        foreach ($this->targets as $target) {
            $grouped[$target->source] ??= [
                'label' => $target->sourceLabel,
                'erase' => 0,
                'anonymise' => 0,
                'skip' => 0,
            ];

            $grouped[$target->source][$target->action]++;
        }

        return $grouped;
    }

    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        return [
            'subject' => $this->subject->normalisedEmail(),
            'mode' => $this->mode,
            'fingerprint' => $this->fingerprint(),
            'blocked' => $this->blocked,
            'blockReason' => $this->blockReason,
            'errors' => $this->errors,
            'counts' => [
                'erase' => $this->countBy(ErasureTarget::ACTION_ERASE),
                'anonymise' => $this->countBy(ErasureTarget::ACTION_ANONYMISE),
                'skip' => $this->countBy(ErasureTarget::ACTION_SKIP),
            ],
            'targets' => array_map(static fn(ErasureTarget $t) => [
                'source' => $t->source,
                'key' => $t->key,
                'label' => $t->label,
                'action' => $t->action,
                'reason' => $t->reason,
                'fields' => $t->fields,
            ], $this->targets),
        ];
    }
}
