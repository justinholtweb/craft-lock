<?php

namespace justinholtweb\lock\collectors;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use DateTime;
use justinholtweb\lock\models\Bundle;
use justinholtweb\lock\models\DataRecord;
use justinholtweb\lock\models\ErasureTarget;
use justinholtweb\lock\models\RetentionScope;
use justinholtweb\lock\models\Subject;

/**
 * Comments left on the site (Verbb Comments).
 *
 * The comment *text* is left alone and the commenter is removed from it. A comment thread with
 * holes in it is a broken page, and the personal data in a comment is the name and the address
 * attached to it, not usually the opinion. Where the text itself names the person, the operator
 * can see that on the request screen and edit it — which is a judgement, and belongs to a human.
 */
class CommentsCollector extends BaseCollector
{
    private const TABLE = '{{%comments_comments}}';

    public static function handle(): string
    {
        return 'comments';
    }

    public function label(): string
    {
        return Craft::t('lock', 'Comments');
    }

    public function description(): string
    {
        return Craft::t('lock', 'Comments left under the address, whether signed in or as a guest.');
    }

    public function isAvailable(): bool
    {
        return Craft::$app->getPlugins()->isPluginEnabled('comments')
            && Craft::$app->getDb()->tableExists(self::TABLE);
    }

    public function unavailableReason(): ?string
    {
        return Craft::t('lock', 'The Comments plugin is not installed.');
    }

    protected function find(Subject $subject, Bundle $bundle): array
    {
        $subject->resolve();

        $conditions = ['or', ['email' => $subject->normalisedEmail()]];

        if ($subject->userId !== null) {
            $conditions[] = ['userId' => $subject->userId];
        }

        $rows = (new Query())
            ->select(['id', 'ownerId', 'userId', 'name', 'email', 'url', 'ipAddress', 'userAgent', 'comment', 'dateCreated'])
            ->from([self::TABLE])
            ->where($conditions)
            ->orderBy(['dateCreated' => SORT_DESC])
            ->limit(500)
            ->all();

        $records = [];

        foreach ($rows as $row) {
            $record = $this->record("comment:{$row['id']}", Craft::t('lock', 'Comment left {date}', ['date' => substr((string)$row['dateCreated'], 0, 10)]), [
                'Name' => $row['name'] ?? null,
                'Email' => $row['email'] ?? null,
                'Comment' => $row['comment'] ?? null,
                'Page' => $row['url'] ?? null,
                'IP address' => $row['ipAddress'] ?? null,
                'Browser' => $row['userAgent'] ?? null,
                'Posted' => $row['dateCreated'],
            ]);
            $record->categories = [DataRecord::CATEGORY_IDENTITY, DataRecord::CATEGORY_CONTENT, DataRecord::CATEGORY_TECHNICAL];
            $record->basis = Craft::t('lock', 'The person chose to publish it.');
            $records[] = $record;
        }

        return $records;
    }

    public function apply(ErasureTarget $target, Subject $subject): void
    {
        $id = $this->keyId($target->key);

        if ($target->action === ErasureTarget::ACTION_ERASE) {
            $element = Craft::$app->getElements()->getElementById($id);

            if ($element !== null) {
                Craft::$app->getElements()->deleteElement($element, true);

                return;
            }

            Craft::$app->getDb()->createCommand()->delete(self::TABLE, ['id' => $id])->execute();

            return;
        }

        Craft::$app->getDb()->createCommand()->update(self::TABLE, [
            'name' => $this->settings()->anonymousName,
            'email' => $subject->pseudonym() . '@' . ($this->settings()->anonymousDomain ?: 'anonymised.invalid'),
            'url' => null,
            'ipAddress' => null,
            'userAgent' => null,
            'userId' => null,
        ], ['id' => $id])->execute();
    }

    public function scopes(): array
    {
        $scope = new RetentionScope();
        $scope->key = 'comments:old';
        $scope->source = self::handle();
        $scope->label = Craft::t('lock', 'Old comments');
        $scope->description = Craft::t('lock', 'Comments older than the period, with the commenter removed and the text kept.');
        $scope->measuredFrom = Craft::t('lock', 'date posted');
        $scope->erasable = false;
        $scope->suggestedMonths = 36;

        return [$scope];
    }

    public function stale(RetentionScope $scope, DateTime $cutoff, int $limit): array
    {
        if ($scope->key !== 'comments:old' || !$this->isAvailable()) {
            return [];
        }

        $rows = (new Query())
            ->select(['id', 'email', 'dateCreated'])
            ->from([self::TABLE])
            ->where(['<', 'dateCreated', Db::prepareDateForDb($cutoff)])
            ->andWhere(['not', ['email' => null]])
            ->orderBy(['dateCreated' => SORT_ASC])
            ->limit($limit)
            ->all();

        $records = [];

        foreach ($rows as $row) {
            $record = $this->record("comment:{$row['id']}", Craft::t('lock', 'Comment from {date}', ['date' => substr((string)$row['dateCreated'], 0, 10)]), [
                'Email' => $row['email'],
            ]);
            $record->erasable = false;
            $record->categories = [DataRecord::CATEGORY_IDENTITY, DataRecord::CATEGORY_CONTENT];
            $records[] = $record;
        }

        return $records;
    }
}
