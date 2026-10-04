<?php

namespace justinholtweb\lock\collectors;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use DateTime;
use justinholtweb\lock\helpers\Address;
use justinholtweb\lock\helpers\Readable;
use justinholtweb\lock\models\Bundle;
use justinholtweb\lock\models\DataRecord;
use justinholtweb\lock\models\ErasureTarget;
use justinholtweb\lock\models\RetentionScope;
use justinholtweb\lock\models\Subject;

/**
 * Formie submissions.
 *
 * Formie keeps submission values in its own `content` column rather than in Craft's
 * `elements_sites`, so the search is a `LIKE` over that column — with the address in both its raw
 * and its JSON-escaped form, because an address containing a slash or a quote is stored escaped
 * and a raw search misses exactly those.
 *
 * Submissions are the easiest case in the whole plugin: they are a copy of what somebody typed
 * into a form, they carry no legal obligation, and they are almost always the largest pile of
 * personal data on the site. Erasable, anonymisable, and the first thing any retention policy
 * should be pointed at.
 */
class FormieCollector extends BaseCollector
{
    public static function handle(): string
    {
        return 'formie';
    }

    public function label(): string
    {
        return Craft::t('lock', 'Form submissions (Formie)');
    }

    public function description(): string
    {
        return Craft::t('lock', 'Formie submissions containing the address anywhere in their answers, plus any submitted while signed in.');
    }

    public function isAvailable(): bool
    {
        return Craft::$app->getPlugins()->isPluginEnabled('formie')
            && class_exists(\verbb\formie\elements\Submission::class);
    }

    public function unavailableReason(): ?string
    {
        return Craft::t('lock', 'Formie is not installed.');
    }

    protected function find(Subject $subject, Bundle $bundle): array
    {
        $email = $subject->normalisedEmail();
        $subject->resolve();

        $ids = $this->matchingIds($email);

        if ($subject->userId !== null) {
            $byUser = (new Query())
                ->select(['id'])
                ->from(['{{%formie_submissions}}'])
                ->where(['userId' => $subject->userId])
                ->limit(500)
                ->column();

            $ids = array_unique(array_merge($ids, array_map('intval', $byUser)));
        }

        if ($ids === []) {
            return [];
        }

        $records = [];

        // @phpstan-ignore class.notFound (Formie is optional; isAvailable() checks class_exists first)
        foreach (\verbb\formie\elements\Submission::find()->id($ids)->status(null)->isIncomplete(null)->isSpam(null)->limit(null)->all() as $submission) {
            $data = ['Form' => $submission->getForm()?->title, 'Submitted' => $submission->dateCreated?->format('Y-m-d H:i:s')];

            $layout = $submission->getFieldLayout();

            if ($layout !== null) {
                foreach ($layout->getCustomFields() as $field) {
                    $value = Readable::value($submission->getFieldValue($field->handle));

                    if (!Readable::isEmpty($value)) {
                        $data[$field->name] = is_array($value) ? Readable::flatten($value) : $value;
                    }
                }
            }

            $record = $this->record("submission:$submission->id", Craft::t('lock', '“{form}” submitted {date}', [
                'form' => $submission->getForm()->title ?? Craft::t('lock', 'Form'),
                'date' => $submission->dateCreated?->format('Y-m-d'),
            ]), $data);
            $record->kind = DataRecord::KIND_ELEMENT;
            $record->categories = [DataRecord::CATEGORY_CONTACT, DataRecord::CATEGORY_CONTENT];
            $record->dateCreated = $submission->dateCreated;
            $record->cpUrl = $submission->getCpEditUrl();
            $record->basis = Craft::t('lock', 'The person sent it to the site.');
            $records[] = $record;
        }

        return $records;
    }

    /** @return int[] */
    private function matchingIds(string $email): array
    {
        if ($email === '') {
            return [];
        }

        $conditions = ['or'];

        foreach (Address::needles($email) as $needle) {
            $conditions[] = ['like', 'content', $needle];
        }

        // `LIKE` narrows it down; the bounded match decides. `%lex@corp.co%` is also a substring
        // of `alex@corp.com`, and a disclosure that hands Alex's enquiry to Lex is a breach.
        $rows = (new Query())
            ->select(['id', 'content'])
            ->from(['{{%formie_submissions}}'])
            ->where($conditions)
            ->limit(2500)
            ->all();

        $ids = [];

        foreach ($rows as $row) {
            if (Address::contains(is_string($row['content']) ? $row['content'] : null, $email)) {
                $ids[] = (int)$row['id'];
            }
        }

        return array_slice($ids, 0, 500);
    }

    public function apply(ErasureTarget $target, Subject $subject): void
    {
        // @phpstan-ignore class.notFound (Formie is optional; isAvailable() checks class_exists first)
        $submission = Craft::$app->getElements()->getElementById($this->keyId($target->key), \verbb\formie\elements\Submission::class);

        if ($submission === null) {
            return;
        }

        if ($target->action === ErasureTarget::ACTION_ERASE) {
            Craft::$app->getElements()->deleteElement($submission, true);

            return;
        }

        $domain = $this->settings()->anonymousDomain ?: 'anonymised.invalid';
        $replacement = $subject->pseudonym() . '@' . $domain;

        $layout = $submission->getFieldLayout();

        if ($layout !== null) {
            foreach ($layout->getCustomFields() as $field) {
                $value = $submission->getSerializedFieldValues([$field->handle])[$field->handle] ?? null;

                if (Address::containsDeep($value, $subject->normalisedEmail())) {
                    $submission->setFieldValue($field->handle, Address::replaceDeep($value, $subject->normalisedEmail(), $replacement));
                }
            }
        }

        // @phpstan-ignore property.notFound (a Formie Submission, which PHPStan cannot see because Formie is optional)
        $submission->ipAddress = null;

        Craft::$app->getElements()->saveElement($submission, false);
    }

    public function scopes(): array
    {
        $all = new RetentionScope();
        $all->key = 'formie:submissions';
        $all->source = self::handle();
        $all->label = Craft::t('lock', 'Form submissions');
        $all->description = Craft::t('lock', 'Every Formie submission older than the period. Usually the largest pile of personal data on a site, and the one with the least reason to still be there.');
        $all->measuredFrom = Craft::t('lock', 'submission date');
        $all->suggestedMonths = 24;

        $spam = new RetentionScope();
        $spam->key = 'formie:spam';
        $spam->source = self::handle();
        $spam->label = Craft::t('lock', 'Spam and abandoned submissions');
        $spam->description = Craft::t('lock', 'Submissions marked as spam or never finished. Still personal data, and nobody is ever going to read them.');
        $spam->measuredFrom = Craft::t('lock', 'submission date');
        $spam->anonymisable = false;
        $spam->suggestedMonths = 1;

        return [$all, $spam];
    }

    public function stale(RetentionScope $scope, DateTime $cutoff, int $limit): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        // @phpstan-ignore class.notFound (Formie is optional; isAvailable() checks class_exists first)
        $query = \verbb\formie\elements\Submission::find()
            ->status(null)
            ->dateCreated('< ' . Db::prepareDateForDb($cutoff))
            ->orderBy(['dateCreated' => SORT_ASC])
            ->limit($limit);

        if ($scope->key === 'formie:spam') {
            $query->isSpam(null)->isIncomplete(null)->andWhere(['or', ['isSpam' => true], ['isIncomplete' => true]]);
        } elseif ($scope->key !== 'formie:submissions') {
            return [];
        }

        $records = [];

        foreach ($query->all() as $submission) {
            $record = $this->record("submission:$submission->id", Craft::t('lock', '“{form}” from {date}', [
                'form' => $submission->getForm()->title ?? Craft::t('lock', 'Form'),
                'date' => $submission->dateCreated?->format('Y-m-d'),
            ]), []);
            $record->kind = DataRecord::KIND_ELEMENT;
            $record->categories = [DataRecord::CATEGORY_CONTACT];
            $record->anonymisable = $scope->anonymisable;
            $records[] = $record;
        }

        return $records;
    }
}
