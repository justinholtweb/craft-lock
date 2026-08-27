<?php

namespace justinholtweb\lock\collectors;

use Craft;
use craft\helpers\Db;
use DateTime;
use justinholtweb\lock\models\Bundle;
use justinholtweb\lock\models\DataRecord;
use justinholtweb\lock\models\ErasureTarget;
use justinholtweb\lock\models\RetentionScope;
use justinholtweb\lock\models\Subject;

/**
 * Commerce orders, carts, addresses and subscriptions.
 *
 * This is where anonymise-versus-erase earns its keep. A completed order is a tax record — in the
 * UK it is six years, in Germany ten — and Article 17(3)(b) is explicit that the right to erasure
 * does not override a legal obligation to keep something. But the *order* is what the obligation
 * covers, not the customer's name and address inside it.
 *
 * So a completed order is never deleted, and is thoroughly emptied: email, billing and shipping
 * names, street lines, phone. What stays is the money, the tax, the line items, the country and
 * the date — everything an auditor asks for, and nothing that says who.
 *
 * A cart is not a tax record and is deleted outright.
 */
class CommerceCollector extends BaseCollector
{
    public static function handle(): string
    {
        return 'commerce';
    }

    public function label(): string
    {
        return Craft::t('lock', 'Commerce orders');
    }

    public function description(): string
    {
        return Craft::t('lock', 'Orders, carts, saved addresses and subscriptions matched by email address — guest orders included.');
    }

    public function isAvailable(): bool
    {
        return Craft::$app->getPlugins()->isPluginEnabled('commerce')
            && class_exists(\craft\commerce\elements\Order::class);
    }

    public function unavailableReason(): ?string
    {
        return Craft::t('lock', 'Craft Commerce is not installed.');
    }

    protected function find(Subject $subject, Bundle $bundle): array
    {
        $email = $subject->normalisedEmail();

        // Matched on the order's own email column, not on the customer account. Most stores take
        // more guest orders than account orders, and a search that starts from the user record
        // finds none of them.
        $orders = \craft\commerce\elements\Order::find()
            ->email($email)
            ->isCompleted(null)
            ->status(null)
            ->limit(500)
            ->all();

        $records = [];

        foreach ($orders as $order) {
            $completed = (bool)$order->isCompleted;

            $data = [
                'Order number' => $order->reference ?: $order->number,
                'Placed' => $order->dateOrdered?->format('Y-m-d H:i:s'),
                'Status' => $completed ? Craft::t('lock', 'Completed') : Craft::t('lock', 'Cart'),
                'Email' => $order->email,
                'Total' => $order->totalPrice,
                'Currency' => $order->currency,
                'Items' => implode(', ', array_map(static fn($line) => $line->description, $order->getLineItems())),
                'Billing name' => $order->getBillingAddress()?->fullName,
                'Billing address' => $order->getBillingAddress()?->addressLine1,
                'Shipping name' => $order->getShippingAddress()?->fullName,
                'Shipping address' => $order->getShippingAddress()?->addressLine1,
                'Last IP' => $order->lastIp,
            ];

            $record = $this->record("order:$order->id", $completed
                ? Craft::t('lock', 'Order {ref}', ['ref' => $order->reference ?: $order->number])
                : Craft::t('lock', 'Cart started {date}', ['date' => $order->dateCreated?->format('Y-m-d')]), $data);

            $record->kind = DataRecord::KIND_ELEMENT;
            $record->categories = [DataRecord::CATEGORY_IDENTITY, DataRecord::CATEGORY_CONTACT, DataRecord::CATEGORY_FINANCIAL];
            $record->dateCreated = $order->dateOrdered ?? $order->dateCreated;
            $record->cpUrl = $order->getCpEditUrl();

            if ($completed) {
                $record->erasable = false;
                $record->basis = Craft::t('lock', 'Performing the contract of sale, and the legal obligation to keep accounting records.');
                $record->retention = Craft::t('lock', 'Kept as long as tax law requires — typically six to ten years — then anonymised.');
            } else {
                $record->basis = Craft::t('lock', 'An abandoned basket, kept so it can be picked up again.');
            }

            $records[] = $record;
        }

        if ($records !== []) {
            $bundle->notes[] = Craft::t('lock', 'Completed orders are financial records and are not deleted. The personal details in them are overwritten instead; the amounts, taxes and dates stay.');
        }

        return array_merge($records, $this->subscriptions($subject));
    }

    /** @return DataRecord[] */
    private function subscriptions(Subject $subject): array
    {
        $subject->resolve();

        if ($subject->userId === null || !class_exists(\craft\commerce\elements\Subscription::class)) {
            return [];
        }

        $records = [];

        foreach (\craft\commerce\elements\Subscription::find()->userId($subject->userId)->status(null)->limit(200)->all() as $subscription) {
            $record = $this->record("subscription:$subscription->id", Craft::t('lock', 'Subscription {ref}', ['ref' => $subscription->reference]), [
                'Plan' => $subscription->getPlan()?->name,
                'Started' => $subscription->dateCreated?->format('Y-m-d'),
                'Cancelled' => $subscription->dateCanceled?->format('Y-m-d'),
                'Expires' => $subscription->dateExpired?->format('Y-m-d'),
            ]);
            $record->kind = DataRecord::KIND_ELEMENT;
            $record->categories = [DataRecord::CATEGORY_FINANCIAL, DataRecord::CATEGORY_ACCOUNT];
            $record->dateCreated = $subscription->dateCreated;
            $record->erasable = false;
            $record->anonymisable = false;
            $record->retainReason = Craft::t('lock', 'A live subscription is a running contract. Cancel it first; the record is then anonymised with the account.');
            $records[] = $record;
        }

        return $records;
    }

    public function apply(ErasureTarget $target, Subject $subject): void
    {
        $body = $this->keyBody($target->key);

        if (!str_starts_with($body, 'order:')) {
            return;
        }

        $order = \craft\commerce\elements\Order::find()->id((int)substr($body, 6))->isCompleted(null)->status(null)->one();

        if ($order === null) {
            return;
        }

        if ($target->action === ErasureTarget::ACTION_ERASE) {
            Craft::$app->getElements()->deleteElement($order, true);

            return;
        }

        $this->anonymiseOrder($order, $subject);
    }

    private function anonymiseOrder(mixed $order, Subject $subject): void
    {
        $pseudonym = $subject->pseudonym();
        $domain = $this->settings()->anonymousDomain ?: 'anonymised.invalid';

        foreach (['getBillingAddress', 'getShippingAddress'] as $getter) {
            $address = $order->$getter();

            if ($address === null) {
                continue;
            }

            foreach (['fullName', 'firstName', 'lastName', 'organization', 'organizationTaxId', 'addressLine1', 'addressLine2', 'addressLine3', 'locality', 'postalCode'] as $attribute) {
                if ($address->canSetProperty($attribute)) {
                    $address->$attribute = null;
                }
            }

            // Country and region survive. They are what a VAT return is built from, and they
            // identify nobody on their own.
            $address->fullName = $pseudonym;
            Craft::$app->getElements()->saveElement($address, false);
        }

        $order->email = "$pseudonym@$domain";
        $order->lastIp = null;
        $order->message = null;
        $order->customerId = null;

        Craft::$app->getElements()->saveElement($order, false);
    }

    public function scopes(): array
    {
        $carts = new RetentionScope();
        $carts->key = 'commerce:carts';
        $carts->source = self::handle();
        $carts->label = Craft::t('lock', 'Abandoned carts');
        $carts->description = Craft::t('lock', 'Baskets that were never completed. Not a financial record, so these can simply go.');
        $carts->measuredFrom = Craft::t('lock', 'last touched');
        $carts->anonymisable = false;
        $carts->suggestedMonths = 3;

        $orders = new RetentionScope();
        $orders->key = 'commerce:orders';
        $orders->source = self::handle();
        $orders->label = Craft::t('lock', 'Completed orders');
        $orders->description = Craft::t('lock', 'Old orders, anonymised in place. The money stays and the customer does not.');
        $orders->measuredFrom = Craft::t('lock', 'order date');
        $orders->erasable = false;
        $orders->suggestedMonths = 84;
        $orders->rationale = Craft::t('lock', 'Seven years covers the usual six-year accounting requirement with a margin.');

        return [$carts, $orders];
    }

    public function stale(RetentionScope $scope, DateTime $cutoff, int $limit): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        $isCarts = $scope->key === 'commerce:carts';

        if (!$isCarts && $scope->key !== 'commerce:orders') {
            return [];
        }

        $query = \craft\commerce\elements\Order::find()
            ->isCompleted(!$isCarts)
            ->status(null)
            ->limit($limit);

        if ($isCarts) {
            $query->dateUpdated('< ' . Db::prepareDateForDb($cutoff));
        } else {
            $query->dateOrdered('< ' . Db::prepareDateForDb($cutoff));

            // An order already anonymised has nothing left to anonymise, and re-running the rule
            // over it every night would fill the ledger with work that isn't happening.
            $query->email('not *@' . ($this->settings()->anonymousDomain ?: 'anonymised.invalid'));
        }

        $records = [];

        foreach ($query->all() as $order) {
            $record = $this->record("order:$order->id", $isCarts
                ? Craft::t('lock', 'Cart from {date}', ['date' => $order->dateUpdated?->format('Y-m-d')])
                : Craft::t('lock', 'Order {ref} from {date}', ['ref' => $order->reference ?: $order->number, 'date' => $order->dateOrdered?->format('Y-m-d')]), [
                    'Email' => $order->email,
                    'Total' => $order->totalPrice,
                ]);
            $record->kind = DataRecord::KIND_ELEMENT;
            $record->categories = [DataRecord::CATEGORY_FINANCIAL, DataRecord::CATEGORY_CONTACT];
            $record->erasable = $isCarts;
            $record->anonymisable = !$isCarts;
            $records[] = $record;
        }

        return $records;
    }
}
