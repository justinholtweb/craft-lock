<?php

namespace justinholtweb\lock\collectors;

use Craft;
use craft\db\Query;
use DateTime;
use justinholtweb\lock\models\Bundle;
use justinholtweb\lock\models\DataRecord;
use justinholtweb\lock\models\ErasureTarget;
use justinholtweb\lock\models\Subject;

/**
 * Cookie consent and policy acceptances recorded by Toss.
 *
 * Toss decides what the banner says and gates the tags behind it; Lock is what answers for it
 * afterwards. Toss keys its records to an anonymous browser token, which is exactly right for a
 * visitor who is not signed in and exactly useless for a subject access request — so the only
 * rows that can honestly be attributed to a person here are the ones carrying a user ID.
 *
 * That limitation is stated in the bundle's notes rather than papered over. A disclosure that
 * silently omits the cookie decisions of everyone who was signed out is a disclosure that is
 * wrong more often than it is right.
 */
class TossCollector extends BaseCollector
{
    public static function handle(): string
    {
        return 'toss';
    }

    public function label(): string
    {
        return Craft::t('lock', 'Cookie consent and policy acceptances (Toss)');
    }

    public function description(): string
    {
        return Craft::t('lock', 'Cookie category decisions and policy acceptances recorded against the account.');
    }

    public function isAvailable(): bool
    {
        return $this->settings()->adoptToss
            && Craft::$app->getPlugins()->isPluginEnabled('toss')
            && Craft::$app->getDb()->tableExists('{{%toss_consents}}');
    }

    public function unavailableReason(): ?string
    {
        return $this->settings()->adoptToss
            ? Craft::t('lock', 'Toss is not installed.')
            : Craft::t('lock', 'Reading Toss’s records is switched off in Lock’s settings.');
    }

    protected function find(Subject $subject, Bundle $bundle): array
    {
        $subject->resolve();

        if ($subject->userId === null) {
            $bundle->notes[] = Craft::t('lock', 'Toss records cookie decisions against an anonymous browser token, so they can only be tied to a person who was signed in. There is no account for this address, so none can be.');

            return [];
        }

        $bundle->notes[] = Craft::t('lock', 'Only decisions made while signed in are shown — Toss deliberately does not identify signed-out visitors.');

        $records = [];

        foreach ((new Query())->from(['{{%toss_consents}}'])->where(['userId' => $subject->userId])->orderBy(['dateCreated' => SORT_DESC])->limit(200)->all() as $row) {
            $record = $this->record("consent:{$row['id']}", Craft::t('lock', 'Cookie choices on {date}', ['date' => substr((string)$row['dateCreated'], 0, 10)]), [
                'Categories allowed' => $row['categories'],
                'Chosen through' => $row['source'],
                'Recorded' => $row['dateCreated'],
                'Browser' => $row['userAgent'],
            ]);
            $record->categories = [DataRecord::CATEGORY_TECHNICAL];
            $record->erasable = false;
            $record->basis = Craft::t('lock', 'Demonstrating that cookie consent was given — Article 7(1).');
            $record->dateCreated = new DateTime((string)$row['dateCreated']);
            $records[] = $record;
        }

        if (Craft::$app->getDb()->tableExists('{{%toss_acceptances}}')) {
            foreach ((new Query())->from(['{{%toss_acceptances}}'])->where(['userId' => $subject->userId])->orderBy(['dateCreated' => SORT_DESC])->limit(200)->all() as $row) {
                $record = $this->record("acceptance:{$row['id']}", Craft::t('lock', 'Accepted a policy on {date}', ['date' => substr((string)$row['dateCreated'], 0, 10)]), [
                    'Where' => $row['context'],
                    'Reference' => $row['reference'],
                    'Accepted' => $row['dateCreated'],
                ]);
                $record->categories = [DataRecord::CATEGORY_ACCOUNT];
                $record->erasable = false;
                $record->basis = Craft::t('lock', 'Evidence that the terms were agreed to.');
                $record->dateCreated = new DateTime((string)$row['dateCreated']);
                $records[] = $record;
            }
        }

        return $records;
    }

    public function apply(ErasureTarget $target, Subject $subject): void
    {
        if ($target->action !== ErasureTarget::ACTION_ANONYMISE) {
            return;
        }

        $body = $this->keyBody($target->key);
        [$kind, $id] = array_pad(explode(':', $body, 2), 2, null);

        $table = match ($kind) {
            'consent' => '{{%toss_consents}}',
            'acceptance' => '{{%toss_acceptances}}',
            default => null,
        };

        if ($table === null) {
            return;
        }

        // The row survives without the person. Toss's own token stays: it is already anonymous,
        // and it is what keeps the record meaningful as evidence.
        Craft::$app->getDb()->createCommand()->update($table, [
            'userId' => null,
            'userAgent' => null,
        ], ['id' => (int)$id])->execute();
    }
}
