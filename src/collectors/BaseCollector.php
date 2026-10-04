<?php

namespace justinholtweb\lock\collectors;

use Craft;
use DateTime;
use justinholtweb\lock\helpers\Address;
use justinholtweb\lock\models\Bundle;
use justinholtweb\lock\models\DataRecord;
use justinholtweb\lock\models\ErasureTarget;
use justinholtweb\lock\models\RetentionScope;
use justinholtweb\lock\models\Settings;
use justinholtweb\lock\models\Subject;
use justinholtweb\lock\Plugin;
use Throwable;

/**
 * Shared machinery for collectors: bundle construction, timing, error capture, and the default
 * planning rules.
 *
 * Subclasses implement {@see find()} rather than `collect()` — so that the timing, the try/catch
 * and the "not available" branch are written once and cannot be forgotten by the fourteenth
 * collector.
 */
abstract class BaseCollector implements CollectorInterface
{
    public function description(): string
    {
        return '';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function unavailableReason(): ?string
    {
        return null;
    }

    /**
     * The actual search. May throw — {@see collect()} turns that into a recorded problem.
     *
     * @return DataRecord[]
     */
    abstract protected function find(Subject $subject, Bundle $bundle): array;

    final public function collect(Subject $subject): Bundle
    {
        $bundle = new Bundle();
        $bundle->source = static::handle();
        $bundle->sourceLabel = $this->label();

        if (!$this->isAvailable()) {
            $bundle->searched = false;
            $bundle->skipReason = $this->unavailableReason() ?? Craft::t('lock', 'Not installed.');

            return $bundle;
        }

        $started = microtime(true);

        try {
            $bundle->records = $this->find($subject, $bundle);
        } catch (Throwable $e) {
            // Deliberately swallowed. A dossier that says "we could not read the orders table"
            // is a usable answer; an exception that abandons the other thirteen sources is not.
            //
            // The address is taken out of the message first. A database error quotes the SQL it
            // ran, bound values included, and this message goes on to the bundle, the plan, the
            // run ledger and the log — four places an erasure would never reach.
            $message = $subject->normalisedEmail() !== ''
                ? Address::replace($e->getMessage(), $subject->normalisedEmail(), '[address]')
                : $e->getMessage();
            $bundle->errors[] = $message;
            // Keyed by hash, never by address. This log outlives every erasure Lock performs, so
            // an address written here is an address no erasure can reach.
            Craft::error(
                sprintf('Collector %s failed for subject %s: %s', static::handle(), substr($subject->emailHash(), 0, 12), $message),
                Plugin::LOG_CATEGORY,
            );
        }

        $bundle->duration = round(microtime(true) - $started, 3);

        return $bundle;
    }

    /**
     * The default decision, which most collectors are happy with.
     *
     * The order matters. A record the collector marked as retained is skipped whatever was asked
     * for — a legal obligation is not a preference. Below that, an erase request against something
     * that cannot be deleted is *downgraded* to anonymise rather than refused, because the subject
     * gets more of what they asked for that way and the reason travels with the target.
     */
    public function planFor(DataRecord $record, string $mode, Subject $subject): ErasureTarget
    {
        $target = new ErasureTarget();
        $target->source = $record->source;
        $target->sourceLabel = $record->sourceLabel;
        $target->key = $record->key;
        $target->label = $record->label;
        $target->fields = array_keys($record->data);

        if ($record->retainReason !== null) {
            $target->action = ErasureTarget::ACTION_SKIP;
            $target->reason = $record->retainReason;

            return $target;
        }

        if ($mode === Settings::MODE_ERASE) {
            if ($record->erasable) {
                $target->action = ErasureTarget::ACTION_ERASE;

                return $target;
            }

            if ($record->anonymisable) {
                $target->action = ErasureTarget::ACTION_ANONYMISE;
                $target->reason = Craft::t('lock', 'Cannot be deleted here, so the personal data in it is overwritten instead.');

                return $target;
            }

            $target->action = ErasureTarget::ACTION_SKIP;
            $target->reason = Craft::t('lock', 'This source supports neither deletion nor anonymisation.');

            return $target;
        }

        if ($record->anonymisable) {
            $target->action = ErasureTarget::ACTION_ANONYMISE;

            return $target;
        }

        if ($record->erasable) {
            $target->action = ErasureTarget::ACTION_ERASE;
            $target->reason = Craft::t('lock', 'Cannot be anonymised in place, so the record is deleted.');

            return $target;
        }

        $target->action = ErasureTarget::ACTION_SKIP;
        $target->reason = Craft::t('lock', 'This source supports neither deletion nor anonymisation.');

        return $target;
    }

    /**
     * By default, the address the record itself carries.
     *
     * When a record has no address on it — a session row, a log line — the fallback is a stable
     * string built from the record's own key. It is not an email address and is never written
     * anywhere as one; it exists so that the derived pseudonym is unique per record rather than
     * shared, which is what keeps two anonymised rows from being provably the same person.
     */
    public function subjectFor(DataRecord $record): Subject
    {
        foreach (['Email', 'email', 'Address'] as $key) {
            $value = $record->data[$key] ?? null;

            if (is_string($value) && str_contains($value, '@')) {
                return new Subject(email: $value);
            }
        }

        return new Subject(email: "$record->source:$record->key");
    }

    public function scopes(): array
    {
        return [];
    }

    public function stale(RetentionScope $scope, DateTime $cutoff, int $limit): array
    {
        return [];
    }

    // -----------------------------------------------------------------------------------------
    // Helpers for subclasses
    // -----------------------------------------------------------------------------------------

    protected function settings(): Settings
    {
        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();

        return $settings;
    }

    /** Builds a record already stamped with this collector's identity. */
    protected function record(string $key, string $label, array $data = []): DataRecord
    {
        $record = new DataRecord();
        $record->source = static::handle();
        $record->sourceLabel = $this->label();
        $record->key = $key;
        $record->label = $label;
        $record->data = array_filter($data, static fn($v) => $v !== null && $v !== '' && $v !== []);

        return $record;
    }

    /**
     * The part of a record key after the collector's own prefix.
     *
     * Keys are `handle:rest`, and `rest` may itself contain colons — a custom-table key is
     * `custom:my_table:41`. So this splits once, not everywhere.
     */
    protected function keyBody(string $key): string
    {
        $prefix = static::handle() . ':';

        return str_starts_with($key, $prefix) ? substr($key, strlen($prefix)) : $key;
    }

    protected function keyId(string $key): int
    {
        return (int)$this->keyBody($key);
    }

    /**
     * The address as it is written into a JSON content column.
     *
     * Craft's `Json::encode()` escapes slashes and quotes but not unicode, so an address searched
     * for in its raw form is missed inside `elements_sites.content` exactly when it contains the
     * characters worth escaping. Both forms get searched.
     *
     * @return string[]
     */
    protected function needles(string $value): array
    {
        return Address::needles($value);
    }
}
