<?php

namespace justinholtweb\lock\collectors;

use DateTime;
use justinholtweb\lock\models\DataRecord;
use justinholtweb\lock\models\ErasureTarget;
use justinholtweb\lock\models\RetentionScope;
use justinholtweb\lock\models\Subject;

/**
 * A place personal data lives, and the three things Lock needs to be able to do to it.
 *
 * **Find it, plan what happens to it, do that.** One object owns all three on purpose. The moment
 * finding and erasing live in separate classes they start to disagree — the disclosure lists a
 * table the eraser has never heard of, or the eraser clears a column the disclosure never showed
 * anyone — and both failures are the kind a site only discovers when a regulator asks.
 *
 * Retention is the fourth thing, and it is the same machinery pointed at a different question:
 * instead of "which records belong to this person", "which records are older than this date".
 * Both hand back {@see DataRecord}s and both end up in {@see \justinholtweb\lock\services\Erasure::run()}.
 */
interface CollectorInterface
{
    /** Stable identifier. Appears in record keys, settings and the activity log, so never rename it. */
    public static function handle(): string;

    /** What this source is called on screen and in the subject's disclosure. */
    public function label(): string;

    /** One sentence: what is searched, and how. */
    public function description(): string;

    /**
     * Whether the source is here at all. A collector for a plugin that is not installed answers
     * false and is recorded as *not searched*, which is different from *searched and empty* —
     * a distinction the disclosure makes explicitly.
     */
    public function isAvailable(): bool;

    /** Why not, when {@see isAvailable()} is false. */
    public function unavailableReason(): ?string;

    /**
     * Everything this source holds about the subject.
     *
     * Must not throw. Problems belong on the bundle's `errors`, so that one unreachable source
     * degrades the dossier honestly instead of failing the whole assembly.
     */
    public function collect(Subject $subject): \justinholtweb\lock\models\Bundle;

    /**
     * What should happen to this record under this mode, and why.
     *
     * The collector gets the last word: a mode of `erase` against an invoice line comes back as
     * `skip` with "kept for tax purposes", because the collector is the only thing that knows.
     */
    public function planFor(DataRecord $record, string $mode, Subject $subject): ErasureTarget;

    /**
     * Carry out one planned action. Throwing marks that one target failed; the run continues.
     */
    public function apply(ErasureTarget $target, Subject $subject): void;

    /**
     * Who a record found by a retention sweep belongs to.
     *
     * A subject-driven erasure already knows the person. A retention sweep does not: every row it
     * finds is somebody different, and the pseudonym written into each has to be derived from that
     * row's own address — otherwise a night's sweep gives ten thousand orders the same pseudonym
     * and makes them linkable to each other again, which is the one thing anonymisation is for.
     */
    public function subjectFor(DataRecord $record): Subject;

    /**
     * The retention sweeps this source offers, if any.
     *
     * @return RetentionScope[]
     */
    public function scopes(): array;

    /**
     * Records in a scope that are older than the cutoff, oldest first.
     *
     * @return DataRecord[]
     */
    public function stale(RetentionScope $scope, DateTime $cutoff, int $limit): array;
}
