<?php

namespace justinholtweb\lock\models;

use craft\base\Model;
use DateTime;

/**
 * What actually happened when a plan ran.
 *
 * Kept separately from the plan rather than folded into it, so the two can be compared. "Exactly
 * what the preview said" is a claim, and a claim needs both halves on record to be checkable.
 */
class ErasureOutcome extends Model
{
    public string $planFingerprint = '';
    public string $mode = '';
    public bool $dryRun = false;

    public int $erased = 0;
    public int $anonymised = 0;
    public int $skipped = 0;
    public int $failed = 0;

    /** @var array<int, array{source: string, key: string, label: string, action: string, error: string}> */
    public array $failures = [];

    /** @var array<int, array{source: string, key: string, action: string}> */
    public array $completed = [];

    public float $duration = 0.0;
    public ?DateTime $finishedAt = null;
    public ?string $backupPath = null;

    public function isClean(): bool
    {
        return $this->failed === 0;
    }

    public function touched(): int
    {
        return $this->erased + $this->anonymised;
    }

    /** Did the run do what the plan said it would? */
    public function matches(ErasurePlan $plan): bool
    {
        return $this->planFingerprint === $plan->fingerprint();
    }

    /**
     * Defined rather than inherited: `Model::toArray()` would include the `DateTime` as an object,
     * and this array is JSON-encoded straight into the run ledger, where an object is unreadable
     * a year later.
     */
    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        return [
            'fingerprint' => $this->planFingerprint,
            'mode' => $this->mode,
            'dryRun' => $this->dryRun,
            'erased' => $this->erased,
            'anonymised' => $this->anonymised,
            'skipped' => $this->skipped,
            'failed' => $this->failed,
            'failures' => $this->failures,
            'completed' => $this->completed,
            'duration' => $this->duration,
            'finishedAt' => $this->finishedAt?->format(DateTime::ATOM),
            'backupPath' => $this->backupPath,
        ];
    }

    public function summary(): string
    {
        $parts = [];

        if ($this->erased > 0) {
            $parts[] = "$this->erased deleted";
        }

        if ($this->anonymised > 0) {
            $parts[] = "$this->anonymised anonymised";
        }

        if ($this->skipped > 0) {
            $parts[] = "$this->skipped kept";
        }

        if ($this->failed > 0) {
            $parts[] = "$this->failed failed";
        }

        return $parts === [] ? 'Nothing to do' : implode(', ', $parts);
    }
}
