# Extending

## Adding a source

The one extension point that matters. A disclosure is only as complete as the list of places it
looked, and every site has one more.

```php
use justinholtweb\lock\collectors\BaseCollector;
use justinholtweb\lock\models\Bundle;
use justinholtweb\lock\models\DataRecord;
use justinholtweb\lock\models\ErasureTarget;
use justinholtweb\lock\models\Subject;

class LoyaltyCollector extends BaseCollector
{
    public static function handle(): string
    {
        return 'loyalty';
    }

    public function label(): string
    {
        return 'Loyalty scheme';
    }

    public function description(): string
    {
        return 'Points balances and the transactions behind them.';
    }

    /** Not searched, rather than searched and empty — the disclosure says which. */
    public function isAvailable(): bool
    {
        return Craft::$app->getDb()->tableExists('{{%loyalty_members}}');
    }

    public function unavailableReason(): ?string
    {
        return 'The loyalty scheme is not installed.';
    }

    /** May throw. The base class turns that into a recorded problem on the bundle. */
    protected function find(Subject $subject, Bundle $bundle): array
    {
        $rows = (new Query())
            ->from(['{{%loyalty_members}}'])
            ->where(['email' => $subject->normalisedEmail()])
            ->all();

        return array_map(function(array $row) {
            $record = $this->record("member:{$row['id']}", "Loyalty membership {$row['card']}", [
                'Card number' => $row['card'],
                'Points' => $row['points'],
                'Joined' => $row['dateCreated'],
            ]);

            $record->categories = [DataRecord::CATEGORY_ACCOUNT, DataRecord::CATEGORY_BEHAVIOUR];
            $record->basis = 'Running the scheme the person signed up to.';

            // Say what cannot be done, and why. It reaches both the operator and the subject.
            $record->erasable = false;
            $record->retainReason = 'Points are a liability in the accounts until they expire.';

            return $record;
        }, $rows);
    }

    public function apply(ErasureTarget $target, Subject $subject): void
    {
        Craft::$app->getDb()->createCommand()
            ->update('{{%loyalty_members}}', ['email' => $subject->pseudonym() . '@anonymised.invalid'], [
                'id' => (int)$this->keyId($target->key),
            ])
            ->execute();
    }
}
```

Register it:

```php
use justinholtweb\lock\events\RegisterCollectorsEvent;
use justinholtweb\lock\services\Collectors;

Event::on(Collectors::class, Collectors::EVENT_REGISTER_COLLECTORS, function(RegisterCollectorsEvent $event) {
    $event->collectors[] = LoyaltyCollector::class;
});
```

That is the whole contract. The record's `erasable`, `anonymisable` and `retainReason` decide what
a plan does with it, so a collector never has to think about modes — and a record it marks as
retained is skipped whatever was asked for.

### Adding retention

Two more methods, and the source joins the retention screen:

```php
public function scopes(): array
{
    $scope = new RetentionScope();
    $scope->key = 'loyalty:closed';        // must start with this collector's handle
    $scope->source = self::handle();
    $scope->label = 'Closed memberships';
    $scope->measuredFrom = 'the day it was closed';
    $scope->erasable = true;
    $scope->suggestedMonths = 24;

    return [$scope];
}

public function stale(RetentionScope $scope, DateTime $cutoff, int $limit): array
{
    // Same DataRecord shape as find(). It goes through the same planner and the same executor,
    // so a nightly sweep gets every guarantee a hand-run erasure gets.
}
```

Give each record its address in `data['Email']` where there is one. That is what
`subjectFor()` reads to pseudonymise each row as **its own** person, which is what stops a sweep
collapsing thousands of people into one linkable pseudonym.

## Stopping an erasure

```php
use justinholtweb\lock\events\ErasureEvent;
use justinholtweb\lock\services\Erasure;

Event::on(Erasure::class, Erasure::EVENT_BEFORE_ERASE, function(ErasureEvent $event) {
    if ($event->plan->subject->userId !== null && mySiteSaysNo($event->plan->subject)) {
        $event->isValid = false;
    }
});
```

The hook for when a site's rules about who may be erased are more complicated than a legal hold
can express. `EVENT_AFTER_ERASE` carries the outcome.

## Reacting to requests

`Requests::EVENT_BEFORE_SAVE_REQUEST` and `EVENT_AFTER_SAVE_REQUEST` both carry the request and an
`isNew` flag. The before event is cancelable.

## Writing to the ledger

Anything your own code does to somebody's data belongs in the same ledger:

```php
Plugin::getInstance()->activity->log(
    ActivityRecord::CATEGORY_ERASURE,
    'loyalty.closed',
    'Membership closed at the member’s request.',
    $subject,
);
```

It never throws. A ledger write that can break the operation it is recording is a ledger that gets
removed from the hot path six months later.
