<?php

namespace justinholtweb\lock\collectors;

use Craft;
use craft\elements\Address;
use justinholtweb\lock\models\Bundle;
use justinholtweb\lock\models\DataRecord;
use justinholtweb\lock\models\ErasureTarget;
use justinholtweb\lock\models\Subject;

/**
 * Postal addresses owned by the account.
 *
 * Anonymising an address keeps the country and the region and destroys everything else. That
 * split is not arbitrary: the country is what a VAT return and a tax audit actually need, and the
 * street is what identifies a person. Deleting the row outright would take the tax evidence with
 * it; keeping the row intact would not be an erasure at all.
 */
class AddressCollector extends BaseCollector
{
    /** Everything an address holds except the parts a tax authority has a claim on. */
    private const IDENTIFYING = [
        'title', 'fullName', 'firstName', 'lastName', 'organization', 'organizationTaxId',
        'addressLine1', 'addressLine2', 'addressLine3', 'locality', 'dependentLocality',
        'postalCode', 'sortingCode',
    ];

    public static function handle(): string
    {
        return 'address';
    }

    public function label(): string
    {
        return Craft::t('lock', 'Addresses');
    }

    public function description(): string
    {
        return Craft::t('lock', 'Postal addresses saved against the account, including the ones Commerce keeps in the address book.');
    }

    protected function find(Subject $subject, Bundle $bundle): array
    {
        if ($subject->userId === null) {
            $user = $subject->getUser();

            if ($user === null) {
                return [];
            }

            $subject->userId = $user->id;
        }

        $addresses = Address::find()->ownerId($subject->userId)->limit(null)->all();
        $records = [];

        foreach ($addresses as $address) {
            $record = $this->record("address:$address->id", $address->title ?: Craft::t('lock', 'Address #{id}', ['id' => $address->id]), [
                'Name' => $address->fullName,
                'Organisation' => $address->organization,
                'Line 1' => $address->addressLine1,
                'Line 2' => $address->addressLine2,
                'Line 3' => $address->addressLine3,
                'Town or city' => $address->locality,
                'Region' => $address->administrativeArea,
                'Postal code' => $address->postalCode,
                'Country' => $address->countryCode,
            ]);
            $record->kind = DataRecord::KIND_ELEMENT;
            $record->categories = [DataRecord::CATEGORY_CONTACT, DataRecord::CATEGORY_IDENTITY];
            $record->dateCreated = $address->dateCreated;
            $record->basis = Craft::t('lock', 'Kept to deliver to and invoice the person.');
            $records[] = $record;
        }

        return $records;
    }

    public function apply(ErasureTarget $target, Subject $subject): void
    {
        $address = Craft::$app->getElements()->getElementById($this->keyId($target->key), Address::class);

        if (!$address instanceof Address) {
            return;
        }

        if ($target->action === ErasureTarget::ACTION_ERASE) {
            Craft::$app->getElements()->deleteElement($address, true);

            return;
        }

        foreach (self::IDENTIFYING as $attribute) {
            if ($address->canSetProperty($attribute)) {
                $address->$attribute = null;
            }
        }

        $address->title = Craft::t('lock', 'Anonymised address');
        $address->fullName = $subject->pseudonym();

        Craft::$app->getElements()->saveElement($address, false);
    }
}
