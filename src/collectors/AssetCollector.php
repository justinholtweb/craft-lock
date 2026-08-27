<?php

namespace justinholtweb\lock\collectors;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use justinholtweb\lock\models\Bundle;
use justinholtweb\lock\models\DataRecord;
use justinholtweb\lock\models\ErasureTarget;
use justinholtweb\lock\models\Subject;

/**
 * Files the person uploaded.
 *
 * Deliberately **not erasable**. The uploader of a file is personal data; the file usually is
 * not, and is usually on a page. A tool that deletes the site's images because the marketing
 * manager left is a tool nobody runs twice, so the attribution is cleared and the file stays —
 * with the reason on the record, so the operator can go and delete it by hand if that is the
 * right answer for that volume.
 */
class AssetCollector extends BaseCollector
{
    public static function handle(): string
    {
        return 'asset';
    }

    public function label(): string
    {
        return Craft::t('lock', 'Uploaded files');
    }

    public function description(): string
    {
        return Craft::t('lock', 'Assets recorded as uploaded by the account. The upload attribution is personal data; the file itself is usually site content, so it is kept.');
    }

    protected function find(Subject $subject, Bundle $bundle): array
    {
        $subject->resolve();

        if ($subject->userId === null) {
            return [];
        }

        $ids = (new Query())
            ->select(['id'])
            ->from([Table::ASSETS])
            ->where(['uploaderId' => $subject->userId])
            ->limit(500)
            ->column();

        if ($ids === []) {
            return [];
        }

        $bundle->notes[] = Craft::t('lock', 'Files are kept — only the record of who uploaded them is personal data. Delete a file itself from Assets if it needs to go.');

        $records = [];

        foreach (Asset::find()->id($ids)->limit(null)->all() as $asset) {
            $record = $this->record("asset:$asset->id", $asset->filename, [
                'Filename' => $asset->filename,
                'Volume' => $asset->getVolume()->name,
                'Uploaded' => $asset->dateCreated?->format('Y-m-d H:i:s'),
                'Size' => $asset->size,
            ]);
            $record->kind = DataRecord::KIND_FILE;
            $record->categories = [DataRecord::CATEGORY_CONTENT];
            $record->dateCreated = $asset->dateCreated;
            $record->cpUrl = $asset->getCpEditUrl();
            $record->erasable = false;
            $records[] = $record;
        }

        return $records;
    }

    public function apply(ErasureTarget $target, Subject $subject): void
    {
        if ($target->action !== ErasureTarget::ACTION_ANONYMISE) {
            return;
        }

        // Written straight to the column rather than through the element. Saving an asset runs
        // file operations and transform invalidation for a change that touches one integer, and
        // on a volume with thousands of files that is the difference between a run and a timeout.
        Craft::$app->getDb()->createCommand()
            ->update(Table::ASSETS, ['uploaderId' => null], ['id' => $this->keyId($target->key)])
            ->execute();
    }
}
