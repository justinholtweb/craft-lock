<?php

namespace justinholtweb\lock\collectors;

use Craft;
use craft\elements\Entry;
use justinholtweb\lock\helpers\ContentScan;
use justinholtweb\lock\helpers\Readable;
use justinholtweb\lock\models\Bundle;
use justinholtweb\lock\models\DataRecord;
use justinholtweb\lock\models\ErasureTarget;
use justinholtweb\lock\models\Subject;

/**
 * Entries, found two ways.
 *
 * **By authorship**, which is the obvious one, and **by content** — an entry with the address
 * typed into one of its fields. The second is where the personal data on a real Craft site
 * actually lives: enquiry entries, applications, testimonials, event registrations, anything
 * somebody built as a section instead of a form.
 *
 * Nothing here is erasable. An entry is a page; deleting one because the person who is named on
 * it asked to be forgotten is an editorial decision with a URL attached to it, and the plugin is
 * not entitled to make it. What it can do — and does — is take the person's details out.
 */
class EntryCollector extends BaseCollector
{
    public static function handle(): string
    {
        return 'entry';
    }

    public function label(): string
    {
        return Craft::t('lock', 'Entries');
    }

    public function description(): string
    {
        return Craft::t('lock', 'Entries the person wrote, and entries with their email address in a field.');
    }

    protected function find(Subject $subject, Bundle $bundle): array
    {
        $records = [];
        $seen = [];

        $subject->resolve();

        if ($subject->userId !== null) {
            foreach (Entry::find()->authorId($subject->userId)->status(null)->limit(200)->all() as $entry) {
                $seen[$entry->id] = true;
                $records[] = $this->authored($entry);
            }
        }

        $ids = ContentScan::matchingIds(Entry::class, $subject->normalisedEmail());

        if ($ids !== []) {
            $bundle->notes[] = Craft::t('lock', '{n} entries contain the address in a field.', ['n' => count($ids)]);
        }

        foreach (Entry::find()->id($ids)->status(null)->limit(null)->all() as $entry) {
            if (isset($seen[$entry->id])) {
                continue;
            }

            $records[] = $this->matched($entry, $subject);
        }

        return $records;
    }

    private function authored(Entry $entry): DataRecord
    {
        $record = $this->record("authored:$entry->id", Craft::t('lock', 'Wrote “{title}”', ['title' => $entry->title]), [
            'Title' => $entry->title,
            'Section' => $entry->getSection()?->name,
            'Posted' => $entry->postDate?->format('Y-m-d'),
            'URL' => $entry->getUrl(),
        ]);
        $record->kind = DataRecord::KIND_ELEMENT;
        $record->categories = [DataRecord::CATEGORY_CONTENT];
        $record->dateCreated = $entry->dateCreated;
        $record->cpUrl = $entry->getCpEditUrl();
        $record->erasable = false;
        $record->anonymisable = false;
        $record->retainReason = Craft::t('lock', 'This is published content. The byline follows the account, so anonymising the account takes the name off it; deleting the page is an editorial decision.');

        return $record;
    }

    private function matched(Entry $entry, Subject $subject): DataRecord
    {
        $fields = ContentScan::matchingFields($entry, $subject->normalisedEmail());

        $data = [
            'Title' => $entry->title,
            'Section' => $entry->getSection()?->name,
            'Found in' => implode(', ', $fields) ?: Craft::t('lock', 'a field on this entry'),
        ];

        foreach ($fields as $handle => $name) {
            $value = Readable::value($entry->getFieldValue($handle));

            if (!Readable::isEmpty($value)) {
                $data[$name] = is_array($value) ? Readable::flatten($value) : $value;
            }
        }

        $record = $this->record("content:$entry->id", Craft::t('lock', 'Mentioned in “{title}”', ['title' => $entry->title]), $data);
        $record->kind = DataRecord::KIND_ELEMENT;
        $record->categories = [DataRecord::CATEGORY_CONTACT, DataRecord::CATEGORY_CONTENT];
        $record->dateCreated = $entry->dateCreated;
        $record->cpUrl = $entry->getCpEditUrl();
        $record->erasable = false;

        return $record;
    }

    public function apply(ErasureTarget $target, Subject $subject): void
    {
        if ($target->action !== ErasureTarget::ACTION_ANONYMISE) {
            return;
        }

        $body = $this->keyBody($target->key);

        if (!str_starts_with($body, 'content:')) {
            return;
        }

        $entry = Craft::$app->getElements()->getElementById((int)substr($body, 8), Entry::class);

        if (!$entry instanceof Entry) {
            return;
        }

        $domain = $this->settings()->anonymousDomain ?: 'anonymised.invalid';

        ContentScan::replace($entry, $subject->normalisedEmail(), $subject->pseudonym() . '@' . $domain);
    }
}
