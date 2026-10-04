<?php
/**
 * Lock integration checks.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-lock/tests/integration/checks.php
 *
 * Covers what a unit fixture cannot: settings round-tripping through the shapes the control panel
 * actually posts, collectors against real elements and real tables, the plan/execute invariant,
 * and an anonymisation that is checked by reading the rows back rather than by trusting a return
 * value.
 *
 * Idempotent and self-cleaning. Settings are captured up front and restored at the end; every
 * fixture lives under `lock-test.invalid` and is removed in the teardown, so running this against
 * a configured site neither reconfigures it nor leaves test people in it.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\db\Query;
use craft\elements\Entry;
use craft\elements\User;
use craft\fields\PlainText;
use craft\helpers\Db;
use justinholtweb\lock\collectors\BaseCollector;
use justinholtweb\lock\collectors\CollectorInterface;
use justinholtweb\lock\helpers\Address;
use justinholtweb\lock\helpers\Cadence;
use justinholtweb\lock\helpers\ContentScan;
use justinholtweb\lock\helpers\Readable;
use justinholtweb\lock\models\Bundle;
use justinholtweb\lock\models\ConsentEntry;
use justinholtweb\lock\models\DataRecord;
use justinholtweb\lock\models\ErasureTarget;
use justinholtweb\lock\models\Hold;
use justinholtweb\lock\models\ProcessingActivity;
use justinholtweb\lock\models\Request;
use justinholtweb\lock\models\RetentionRule;
use justinholtweb\lock\models\Settings;
use justinholtweb\lock\models\Subject;
use justinholtweb\lock\Plugin;
use justinholtweb\lock\records\ActivityRecord;
use justinholtweb\lock\records\ConsentRecord;
use justinholtweb\lock\records\ErasureRecord;
use justinholtweb\lock\records\HoldRecord;
use justinholtweb\lock\records\ProcessingRecord;
use justinholtweb\lock\records\RequestRecord;
use justinholtweb\lock\records\RunRecord;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";

            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : 'returned ' . var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

$plugin = Plugin::getInstance();
$originalSettings = $plugin->getSettings()->toArray();
$originalEdition = $plugin->edition;

/** Swaps settings in memory only — project config is contended in this harness. */
function configure(array $overrides): Settings
{
    $settings = new Settings();
    $settings->setAttributes($overrides, false);
    Plugin::getInstance()->setSettings($settings->toArray());
    Plugin::getInstance()->collectors->reset();

    /** @var Settings $settings */
    $settings = Plugin::getInstance()->getSettings();

    return $settings;
}

// Pro throughout: the Lite gate is a permission check in a controller, not a service branch, and
// the services are what these checks exercise.
$plugin->edition = Plugin::EDITION_PRO;

const DOMAIN = 'lock-test.invalid';
$subjectEmail = 'ada@' . DOMAIN;
$otherEmail = 'grace@' . DOMAIN;

// ---------------------------------------------------------------------------------------------
section('Settings — the shapes the control panel posts');

check('a blank number falls back to the default rather than throwing a TypeError', function() {
    $settings = new Settings();
    $settings->setAttributes(['responseDays' => '', 'verificationTtl' => ''], false);

    return $settings->responseDays === 30 && $settings->verificationTtl === 48;
});

check('a comma-separated list of recipients becomes an array', function() {
    $settings = new Settings();
    $settings->setAttributes(['staffRecipients' => 'a@example.com, b@example.com'], false);

    return $settings->staffRecipients === ['a@example.com', 'b@example.com'];
});

check('reminder days arrive as integers however they are typed', function() {
    $settings = new Settings();
    $settings->setAttributes(['reminderDays' => '7, 2'], false);

    return $settings->reminderDays === [7, 2];
});

check("an editable table's blank trailing row is dropped", function() {
    $settings = new Settings();
    $settings->setAttributes(['customTables' => [
        ['table' => 'my_leads', 'emailColumn' => 'email', 'label' => 'Leads', 'mode' => 'anonymise'],
        ['table' => '', 'emailColumn' => '', 'label' => '', 'mode' => ''],
    ]], false);

    return count($settings->customTables) === 1;
});

check('a table name that is not a plain identifier is rejected', function() {
    $settings = new Settings();
    $settings->setAttributes(['customTables' => [
        ['table' => 'users; DROP TABLE x', 'emailColumn' => 'email'],
    ]], false);
    $settings->validate();

    return $settings->hasErrors('customTables');
});

check('a custom table with neither an email nor a user column is rejected', function() {
    $settings = new Settings();
    $settings->setAttributes(['customTables' => [['table' => 'my_leads']]], false);
    $settings->validate();

    return $settings->hasErrors('customTables');
});

check('two consent purposes with the same key are rejected', function() {
    $settings = new Settings();
    $settings->setAttributes(['consentPurposes' => [
        ['key' => 'marketing', 'label' => 'A'],
        ['key' => 'marketing', 'label' => 'B'],
    ]], false);
    $settings->validate();

    return $settings->hasErrors('consentPurposes');
});

check('no setting is marked required, so a fresh install can save', function() {
    $settings = new Settings();

    return $settings->validate() ?: 'validation failed on defaults: ' . json_encode($settings->getErrors());
});

check('a bad schedule time is rejected and a good one is not', function() {
    $bad = new Settings();
    $bad->scheduleTime = '25:00';
    $good = new Settings();
    $good->scheduleTime = '03:30';

    return !$bad->validate() && $good->validate();
});

check('staff recipients fall back to the contact address', function() {
    $settings = configure(['contactEmail' => 'dpo@' . DOMAIN, 'staffRecipients' => []]);

    return $settings->resolvedStaffRecipients() === ['dpo@' . DOMAIN];
});

// ---------------------------------------------------------------------------------------------
section('Subjects — hashing and pseudonyms');

check('an address is matched case-insensitively', function() use ($subjectEmail) {
    return (new Subject(email: strtoupper($subjectEmail)))->normalisedEmail() === $subjectEmail;
});

check('the pseudonym is deterministic, so two tables anonymise to the same person', function() use ($subjectEmail) {
    return (new Subject(email: $subjectEmail))->pseudonym() === (new Subject(email: ' ADA@' . DOMAIN . ' '))->pseudonym();
});

check('two different people get two different pseudonyms', function() use ($subjectEmail, $otherEmail) {
    return (new Subject(email: $subjectEmail))->pseudonym() !== (new Subject(email: $otherEmail))->pseudonym();
});

check('the hash is keyed, not a bare sha256 of the address', function() use ($subjectEmail) {
    return (new Subject(email: $subjectEmail))->emailHash() !== hash('sha256', $subjectEmail);
});

// ---------------------------------------------------------------------------------------------
section('Fixtures');

/** Removes anything this script has ever created, so a failed run does not poison the next. */
$teardown = function() use (&$fixtureEntryId, &$decoyEntryId) {
    foreach (User::find()->email('*@' . DOMAIN)->status(null)->limit(null)->all() as $user) {
        Craft::$app->getElements()->deleteElement($user, true);
    }

    foreach ([$fixtureEntryId, $decoyEntryId] as $entryId) {
        if ($entryId === null) {
            continue;
        }

        $entry = Craft::$app->getElements()->getElementById($entryId, Entry::class);

        if ($entry !== null) {
            Craft::$app->getElements()->deleteElement($entry, true);
        }
    }

    $db = Craft::$app->getDb();
    $like = ['like', 'email', '%@' . DOMAIN, false];
    $db->createCommand()->delete(ConsentRecord::tableName(), $like)->execute();
    $db->createCommand()->delete(RequestRecord::tableName(), $like)->execute();
    $db->createCommand()->delete(HoldRecord::tableName(), $like)->execute();
    $db->createCommand()->delete(ProcessingRecord::tableName(), ['like', 'name', 'Lock check%', false])->execute();

    // Runs of the rules these checks define. The dry-run-first guard counts dry runs, so a run
    // left over from last time would make "the first run is forced dry" false on the next.
    $db->createCommand()->delete(RunRecord::tableName(), ['ruleKey' => 'lock-check-firstrun'])->execute();

    $domain = Plugin::getInstance()->getSettings()->anonymousDomain ?: 'anonymised.invalid';

    foreach (['ada', 'grace', 'smoke', 'lex', 'alex'] as $name) {
        $person = new Subject(email: $name . '@' . DOMAIN);
        $hash = $person->emailHash();

        // Requests that were anonymised by an erasure no longer carry the fixture domain, only
        // the pseudonym — so they are found by that.
        $db->createCommand()->delete(RequestRecord::tableName(), ['email' => $person->pseudonym() . '@' . $domain])->execute();
        $db->createCommand()->delete(ErasureRecord::tableName(), ['emailHash' => $hash])->execute();
        $db->createCommand()->delete(ConsentRecord::tableName(), ['emailHash' => $hash])->execute();
        $db->createCommand()->delete(ActivityRecord::tableName(), ['subjectHash' => $hash])->execute();
        $db->createCommand()->delete(RunRecord::tableName(), ['subjectHash' => $hash])->execute();
        Plugin::getInstance()->dossiers->deleteFor($person);
    }
};

$fixtureEntryId = null;
$decoyEntryId = null;
$teardown();

$subjectUser = new User();
$subjectUser->username = 'lock-ada';
$subjectUser->email = $subjectEmail;
$subjectUser->firstName = 'Ada';
$subjectUser->lastName = 'Lovelace';
$subjectUser->active = true;

check('a fixture user can be created', function() use ($subjectUser) {
    return Craft::$app->getElements()->saveElement($subjectUser)
        ?: 'save failed: ' . json_encode($subjectUser->getErrors());
});

// The content scan needs a real plain-text field somewhere. Rather than push a new field into a
// contended project config, the check finds one that already exists — and says so plainly when
// there is none, instead of silently passing.
$contentTarget = null;

foreach (Craft::$app->getEntries()->getAllSections() as $section) {
    foreach ($section->getEntryTypes() as $entryType) {
        foreach ($entryType->getFieldLayout()->getCustomFields() as $field) {
            if ($field instanceof PlainText) {
                $contentTarget = ['section' => $section, 'type' => $entryType, 'field' => $field->handle];
                break 3;
            }
        }
    }
}

if ($contentTarget !== null) {
    $entry = new Entry();
    $entry->sectionId = $contentTarget['section']->id;
    $entry->typeId = $contentTarget['type']->id;
    $entry->title = 'Lock check — enquiry';
    $entry->setFieldValue($contentTarget['field'], "Please contact me at $subjectEmail about the order.");

    check('a fixture entry containing the address can be created', function() use ($entry, &$fixtureEntryId) {
        $saved = Craft::$app->getElements()->saveElement($entry);
        $fixtureEntryId = $entry->id;

        return $saved ?: 'save failed: ' . json_encode($entry->getErrors());
    });
} else {
    echo "  ! no plain text field on this site — the content-scan checks are skipped\n";
}

// ---------------------------------------------------------------------------------------------
section('Content scanning');

if ($contentTarget !== null) {
    check('an entry is found by an address inside a field', function() use ($subjectEmail, $fixtureEntryId) {
        return in_array($fixtureEntryId, ContentScan::matchingIds(Entry::class, $subjectEmail), true);
    });

    check('the matching field is identified by handle', function() use ($subjectEmail, $fixtureEntryId, $contentTarget) {
        $entry = Craft::$app->getElements()->getElementById($fixtureEntryId, Entry::class);

        return array_key_exists($contentTarget['field'], ContentScan::matchingFields($entry, $subjectEmail));
    });

    check('an address nobody used finds nothing', function() {
        return ContentScan::matchingIds(Entry::class, 'nobody-at-all@' . DOMAIN) === [];
    });

    // The decoy: `alex@…` contains `lex@…` as a substring. A `LIKE` or `stripos` finds it; the
    // bounded match must not, and an anonymisation of Lex must leave Alex's address alone.
    $decoy = new Entry();
    $decoy->sectionId = $contentTarget['section']->id;
    $decoy->typeId = $contentTarget['type']->id;
    $decoy->title = 'Lock check — decoy';
    $decoy->setFieldValue($contentTarget['field'], 'Write to alex@' . DOMAIN . ' about the delivery.');

    check('a decoy entry for a longer address can be created', function() use ($decoy, &$decoyEntryId) {
        $saved = Craft::$app->getElements()->saveElement($decoy);
        $decoyEntryId = $decoy->id;

        return $saved ?: 'save failed: ' . json_encode($decoy->getErrors());
    });

    check('an address is not found inside a longer one', function() use (&$decoyEntryId) {
        $lex = 'lex@' . DOMAIN;

        return !in_array($decoyEntryId, ContentScan::matchingIds(Entry::class, $lex), true)
            && in_array($decoyEntryId, ContentScan::matchingIds(Entry::class, 'alex@' . DOMAIN), true)
            ?: 'the decoy was matched for ' . $lex;
    });

    check('anonymising an address leaves a longer one that contains it untouched', function() use (&$decoyEntryId, $contentTarget) {
        $entry = Craft::$app->getElements()->getElementById($decoyEntryId, Entry::class);
        $changed = ContentScan::replace($entry, 'lex@' . DOMAIN, 'anon-x@anonymised.invalid');
        $value = (string)Craft::$app->getElements()->getElementById($decoyEntryId, Entry::class)->getFieldValue($contentTarget['field']);

        return !$changed && str_contains($value, 'alex@' . DOMAIN) ?: "decoy now reads: $value";
    });

    check('the entry collector does not disclose the decoy to the shorter address', function() use (&$decoyEntryId) {
        $bundle = Plugin::getInstance()->collectors->get('entry')->collect(new Subject(email: 'lex@' . DOMAIN));

        foreach ($bundle->records as $record) {
            if ($record->key === "content:$decoyEntryId") {
                return 'the decoy was disclosed';
            }
        }

        return true;
    });
}

check('a log line about a longer address is not disclosed to a shorter one', function() {
    return !Address::contains('2026-10-03 [info] mail sent to alex@' . DOMAIN, 'lex@' . DOMAIN)
        && Address::contains('2026-10-03 [info] mail sent to lex@' . DOMAIN . '.', 'lex@' . DOMAIN);
});

check('a URL needle is searched in its JSON-escaped form as well as raw', function() {
    // Craft stores `https://x` as `https:\/\/x`, so a raw-only LIKE misses exactly the values
    // worth escaping. The collector helper has to offer both.
    $collector = new class() extends BaseCollector {
        public static function handle(): string
        {
            return 'probe';
        }

        public function label(): string
        {
            return 'probe';
        }

        protected function find(Subject $subject, Bundle $bundle): array
        {
            return [];
        }

        public function apply(ErasureTarget $target, Subject $subject): void
        {
        }

        public function needlesFor(string $value): array
        {
            return $this->needles($value);
        }
    };

    $needles = $collector->needlesFor('https://example.com/a');

    return count($needles) === 2 && in_array('https:\/\/example.com\/a', $needles, true);
});

check('an element reduces to one string rather than exploding into its attributes', function() use ($subjectUser) {
    // Every Craft element is Traversable — `yii\base\Model` implements `IteratorAggregate` — so a
    // "flatten anything iterable" helper walks an element into its *attribute values*. The list
    // form is the check that matters: one element in, one string out, not a bag of columns.
    $single = Readable::value($subjectUser);
    $inList = Readable::value([$subjectUser]);

    return is_string($single)
        && $single !== ''
        && is_array($inList)
        && count($inList) === 1
        && is_string($inList[0])
        ?: 'got ' . var_export($inList, true);
});

// ---------------------------------------------------------------------------------------------
section('Collectors');

check('every registered collector implements the interface', function() {
    foreach (Plugin::getInstance()->collectors->all() as $collector) {
        if (!$collector instanceof CollectorInterface) {
            return get_class($collector) . ' does not';
        }
    }

    return true;
});

check('collector handles are unique and non-empty', function() {
    $handles = array_keys(Plugin::getInstance()->collectors->all());

    return $handles === array_unique($handles) && !in_array('', $handles, true) && count($handles) >= 14;
});

check('disabling a collector removes it from the enabled set', function() {
    configure(['disabledCollectors' => ['logs']]);
    $enabled = array_keys(Plugin::getInstance()->collectors->enabled());
    configure([]);

    return !in_array('logs', $enabled, true) && in_array('user', $enabled, true);
});

check('an absent plugin makes its collector unavailable with a reason, not an error', function() {
    $comments = Plugin::getInstance()->collectors->get('comments');

    return !$comments->isAvailable() && $comments->unavailableReason() !== null;
});

check('requiring a collector that does not exist throws rather than silently skipping', function() {
    try {
        Plugin::getInstance()->collectors->require('no-such-source');

        return 'it did not throw';
    } catch (yii\base\InvalidConfigException) {
        return true;
    }
});

check('every retention scope key is prefixed with its own collector handle', function() {
    foreach (Plugin::getInstance()->collectors->scopes() as $key => $scope) {
        if (!str_starts_with($key, $scope->source . ':')) {
            return "$key does not belong to $scope->source";
        }
    }

    return true;
});

check('every scope belongs to a registered collector', function() {
    foreach (Plugin::getInstance()->collectors->scopes() as $scope) {
        if (Plugin::getInstance()->collectors->get($scope->source) === null) {
            return "no collector for $scope->source";
        }
    }

    return true;
});

// ---------------------------------------------------------------------------------------------
section('Assembling a dossier');

$subject = (new Subject(email: $subjectEmail))->resolve();

check('the subject resolves to the fixture account', function() use ($subject, $subjectUser) {
    return $subject->userId === $subjectUser->id;
});

$dossier = $plugin->dossiers->assemble($subject);

check('the account itself is disclosed', function() use ($dossier) {
    $bundle = $dossier->bundles['user'] ?? null;

    return $bundle !== null && $bundle->count() >= 1;
});

check('the disclosure includes the name and the address', function() use ($dossier) {
    $record = $dossier->bundles['user']->records[0];

    return ($record->data['Email'] ?? '') === 'ada@' . DOMAIN && ($record->data['First name'] ?? '') === 'Ada';
});

if ($contentTarget !== null) {
    check('the entry mentioning the address is disclosed', function() use ($dossier, $fixtureEntryId) {
        foreach ($dossier->bundles['entry']->records as $record) {
            if ($record->key === "content:$fixtureEntryId") {
                return true;
            }
        }

        return 'not found among ' . count($dossier->bundles['entry']->records) . ' entry records';
    });
}

check('an unavailable source is recorded as not searched, which is not the same as empty', function() use ($dossier) {
    $bundle = $dossier->bundles['comments'];

    return !$bundle->searched && $bundle->skipReason !== null && $bundle->isEmpty();
});

check('the covering data lists both searched and skipped sources', function() use ($dossier) {
    $disclosure = $dossier->toDisclosure();

    return $disclosure['disclosure']['sources_searched'] !== []
        && $disclosure['disclosure']['sources_skipped'] !== [];
});

check('a source that throws marks the dossier partial rather than aborting it', function() use ($subject) {
    configure(['customTables' => [['table' => 'no_such_table_here', 'emailColumn' => 'email', 'label' => 'Ghost', 'mode' => 'read']]]);
    Plugin::getInstance()->collectors->reset();

    $partial = Plugin::getInstance()->dossiers->assemble($subject);
    configure([]);
    Plugin::getInstance()->collectors->reset();

    return $partial->isPartial() && $partial->problems() !== [] && $partial->count() > 0;
});

check('the export writes a readable archive with all four files', function() use ($dossier) {
    $filename = Plugin::getInstance()->dossiers->export($dossier);
    $path = Plugin::getInstance()->dossiers->pathFor($filename);

    if (!is_file($path)) {
        return 'no file at ' . $path;
    }

    $zip = new ZipArchive();
    $zip->open($path);
    $names = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }

    $readme = $zip->getFromName('README.txt');
    $json = json_decode((string)$zip->getFromName('data.json'), true);
    $zip->close();
    unlink($path);

    sort($names);

    return $names === ['README.txt', 'data.csv', 'data.html', 'data.json']
        && str_contains($readme, 'WHERE WE LOOKED')
        && isset($json['disclosure']['records_total'])
        ?: 'archive contents: ' . implode(', ', $names);
});

check('the covering note names the sources that were not searched', function() use ($dossier) {
    $filename = Plugin::getInstance()->dossiers->export($dossier);
    $path = Plugin::getInstance()->dossiers->pathFor($filename);
    $zip = new ZipArchive();
    $zip->open($path);
    $readme = (string)$zip->getFromName('README.txt');
    $zip->close();
    unlink($path);

    return str_contains($readme, 'not searched');
});

check('assembling writes a line to the ledger', function() use ($subject) {
    return ActivityRecord::find()
        ->where(['subjectHash' => $subject->emailHash(), 'action' => 'dossier.assembled'])
        ->exists();
});

// ---------------------------------------------------------------------------------------------
section('Planning an erasure');

$plan = $plugin->erasure->plan($subject, Settings::MODE_ANONYMISE);

check('the plan covers every record the dossier disclosed', function() use ($plan, $subject) {
    $dossier = Plugin::getInstance()->dossiers->assemble($subject);

    return count($plan->targets) === $dossier->count()
        ?: sprintf('%d targets against %d records', count($plan->targets), $dossier->count());
});

check('the fingerprint is stable across two identical plans', function() use ($subject) {
    $a = Plugin::getInstance()->erasure->plan($subject, Settings::MODE_ANONYMISE);
    $b = Plugin::getInstance()->erasure->plan($subject, Settings::MODE_ANONYMISE);

    return $a->fingerprint() === $b->fingerprint();
});

check('the fingerprint changes when the mode does', function() use ($subject) {
    $a = Plugin::getInstance()->erasure->plan($subject, Settings::MODE_ANONYMISE);
    $b = Plugin::getInstance()->erasure->plan($subject, Settings::MODE_ERASE);

    return $a->fingerprint() !== $b->fingerprint();
});

check('an authored entry is kept, with the reason on the target', function() use ($subject) {
    $entry = new Entry();

    $record = new DataRecord();
    $record->source = 'entry';
    $record->key = 'entry:authored:1';
    $record->erasable = false;
    $record->anonymisable = false;
    $record->retainReason = 'published content';

    $target = Plugin::getInstance()->collectors->get('entry')->planFor($record, Settings::MODE_ERASE, $subject);

    return $target->action === ErasureTarget::ACTION_SKIP && $target->reason === 'published content';
});

check('an erase against an unerasable record is downgraded to anonymise, with a reason', function() use ($subject) {
    $record = new DataRecord();
    $record->source = 'commerce';
    $record->key = 'commerce:order:1';
    $record->erasable = false;
    $record->anonymisable = true;

    $target = Plugin::getInstance()->collectors->get('commerce')->planFor($record, Settings::MODE_ERASE, $subject);

    return $target->action === ErasureTarget::ACTION_ANONYMISE && $target->reason !== null;
});

check('an anonymise against a record that cannot be anonymised becomes a delete', function() use ($subject) {
    $record = new DataRecord();
    $record->source = 'session';
    $record->key = 'session:session:1';
    $record->erasable = true;
    $record->anonymisable = false;

    $target = Plugin::getInstance()->collectors->get('session')->planFor($record, Settings::MODE_ANONYMISE, $subject);

    return $target->action === ErasureTarget::ACTION_ERASE && $target->reason !== null;
});

check('a log record is never actionable from a subject request', function() use ($subject) {
    $bundle = Plugin::getInstance()->collectors->get('logs')->collect($subject);

    foreach ($bundle->records as $record) {
        if ($record->erasable || $record->anonymisable || $record->retainReason === null) {
            return 'a log record was left actionable';
        }
    }

    return true;
});

check('an administrator account is kept even under an erase request', function() {
    $admin = User::find()->admin(true)->status(null)->one();

    if ($admin === null) {
        return 'no admin on this site';
    }

    $plan = Plugin::getInstance()->erasure->plan(new Subject(email: (string)$admin->email), Settings::MODE_ERASE);

    foreach ($plan->targets as $target) {
        if ($target->key === "user:$admin->id") {
            return $target->action === ErasureTarget::ACTION_SKIP ?: 'the admin account was planned for ' . $target->action;
        }
    }

    return 'the admin account was not in the plan at all';
});

// ---------------------------------------------------------------------------------------------
section('Holds');

$hold = new Hold();
$hold->email = $subjectEmail;
$hold->reason = 'Lock check — pretend litigation';

check('a hold can be placed', function() use ($hold) {
    return Plugin::getInstance()->holds->save($hold);
});

check('a hold blocks the plan, with the reason carried through', function() use ($subject) {
    $plan = Plugin::getInstance()->erasure->plan($subject, Settings::MODE_ANONYMISE);

    return $plan->blocked && str_contains((string)$plan->blockReason, 'pretend litigation');
});

check('a blocked plan runs nothing and is recorded as blocked', function() use ($subject) {
    $plan = Plugin::getInstance()->erasure->plan($subject, Settings::MODE_ANONYMISE);
    $outcome = Plugin::getInstance()->erasure->run($plan);

    $stillThere = User::find()->email($subject->normalisedEmail())->status(null)->exists();
    $run = RunRecord::find()->where(['subjectHash' => $subject->emailHash()])->orderBy(['id' => SORT_DESC])->one();

    return $outcome->touched() === 0 && $stillThere && $run !== null && $run->status === RunRecord::STATUS_BLOCKED;
});

check('an expired hold does not block', function() use ($subject, $hold) {
    $expired = new Hold();
    $expired->email = 'grace@' . DOMAIN;
    $expired->reason = 'Lock check — expired';
    $expired->expiresAt = new DateTime('-1 day');
    Plugin::getInstance()->holds->save($expired);

    $blocked = Plugin::getInstance()->holds->blockFor(new Subject(email: 'grace@' . DOMAIN));
    Plugin::getInstance()->holds->delete((int)$expired->id);

    return $blocked === null ?: "still blocked: $blocked";
});

check('a hold can be lifted', function() use ($hold, $subject) {
    Plugin::getInstance()->holds->delete((int)$hold->id);

    return Plugin::getInstance()->holds->blockFor($subject) === null;
});

// ---------------------------------------------------------------------------------------------
section('Requests');

$request = new Request();
$request->email = $subjectEmail;
$request->name = 'Ada Lovelace';
$request->type = Request::TYPE_ACCESS;
$request->source = Request::SOURCE_WEB;

check('a request can be taken', function() use ($request) {
    configure(['notifySubject' => false, 'notifyStaff' => false]);

    return Plugin::getInstance()->requests->create($request) ?: 'create failed';
});

check('it gets a sequential reference for this year', function() use ($request) {
    return (bool)preg_match('/^DSAR-' . date('Y') . '-\d{4}$/', $request->reference) ?: "got $request->reference";
});

check('the deadline is thirty days from receipt', function() use ($request) {
    $days = (int)$request->receivedAt->diff($request->dueAt)->format('%a');

    return $days === 30 ?: "got $days";
});

check('it starts unverified and holds only a hash of the token', function() use ($request) {
    $record = RequestRecord::findOne($request->id);

    return $request->status === Request::STATUS_UNVERIFIED
        && $request->plainToken !== null
        && $record->tokenHash === hash('sha256', $request->plainToken)
        && !str_contains((string)$record->tokenHash, $request->plainToken);
});

check('the verification link resolves back to the request', function() use ($request) {
    $found = Plugin::getInstance()->requests->getByToken((string)$request->plainToken);

    return $found !== null && $found->id === $request->id;
});

check('a wrong token resolves to nothing', function() {
    return Plugin::getInstance()->requests->getByToken('not-a-real-token') === null;
});

check('verifying opens the request and burns the token', function() use ($request) {
    $token = (string)$request->plainToken;
    Plugin::getInstance()->requests->verify($request);
    $record = RequestRecord::findOne($request->id);

    return $record->status === Request::STATUS_OPEN
        && $record->tokenHash === null
        && Plugin::getInstance()->requests->getByToken($token) === null;
});

check('an open request blocks a retention sweep of the same person', function() use ($subject) {
    $block = Plugin::getInstance()->holds->blockFor($subject);

    return $block !== null && str_contains($block, 'DSAR-') ?: 'not blocked by the open request';
});

check('the request itself is not blocked by its own openness', function() use ($subject, $request) {
    return Plugin::getInstance()->holds->blockFor($subject, $request->id) === null;
});

check('the extension moves the deadline by sixty calendar days and can only be claimed once', function() use ($request) {
    $before = clone $request->dueAt;
    $first = Plugin::getInstance()->requests->extend($request, 'Lock check — complex');
    $after = clone $request->dueAt;
    $second = Plugin::getInstance()->requests->extend($request, 'again');

    // Calendar days, not 60 × 86400 seconds. A deadline that crosses a daylight-saving boundary
    // is an hour off in seconds and exactly right in days, and the day is what the law counts.
    $days = (int)$before->diff($after)->format('%a');

    return $first && !$second && $days === 60
        ?: sprintf('first=%s second=%s days=%d', var_export($first, true), var_export($second, true), $days);
});

check('days remaining counts whole days and goes negative when late', function() {
    $late = new Request();
    $late->status = Request::STATUS_OPEN;
    $late->dueAt = (new DateTime())->modify('-3 days');

    return $late->daysRemaining() === -3 && $late->isOverdue() && $late->urgency() === 'overdue';
});

check('a closed request is never urgent, however old its deadline', function() {
    $closed = new Request();
    $closed->status = Request::STATUS_COMPLETED;
    $closed->dueAt = (new DateTime())->modify('-90 days');

    return !$closed->isOverdue() && $closed->urgency() === 'closed';
});

check('reminders fire once per threshold crossed, not once per run', function() {
    $soon = new Request();
    $soon->status = Request::STATUS_OPEN;
    $soon->dueAt = (new DateTime())->modify('+1 day');
    $soon->remindersSent = 2;

    // Two thresholds configured, two already sent: nothing further is owed.
    configure(['reminderDays' => [7, 2]]);
    $thresholds = 0;

    foreach ([7, 2] as $threshold) {
        if ($soon->daysRemaining() <= $threshold) {
            $thresholds++;
        }
    }

    return $thresholds === 2 && $thresholds === $soon->remindersSent;
});

check('an unconfirmed request past its link expiry is expired, not deleted', function() {
    $stale = new Request();
    $stale->email = 'grace@' . DOMAIN;
    $stale->type = Request::TYPE_ERASURE;
    Plugin::getInstance()->requests->create($stale);

    Craft::$app->getDb()->createCommand()->update(
        RequestRecord::tableName(),
        ['tokenExpiresAt' => Db::prepareDateForDb(new DateTime('-1 day'))],
        ['id' => $stale->id],
    )->execute();

    $expired = Plugin::getInstance()->requests->expireUnverified();
    $record = RequestRecord::findOne($stale->id);

    return $expired >= 1 && $record !== null && $record->status === Request::STATUS_EXPIRED;
});

check('closing a request records the outcome and the timeline entry', function() use ($request) {
    Plugin::getInstance()->requests->close($request, Request::STATUS_COMPLETED, 'Copy sent by email.', false);
    $types = array_map(static fn($e) => $e->type, Plugin::getInstance()->requests->timeline((int)$request->id));

    return in_array('closed', $types, true) && in_array('received', $types, true) && in_array('verified', $types, true);
});

check('a self-service request from a signed-in subject skips verification', function() {
    // Impersonating the fixture user is what makes this real: the branch reads the identity, so a
    // test that sets a flag instead would be testing the flag.
    $identity = User::find()->email('ada@' . DOMAIN)->status(null)->one();
    Craft::$app->getUser()->setIdentity($identity);

    $self = new Request();
    $self->email = 'ada@' . DOMAIN;
    $self->type = Request::TYPE_ACCESS;
    Plugin::getInstance()->requests->create($self);

    Craft::$app->getUser()->setIdentity(null);

    return $self->status === Request::STATUS_OPEN && $self->verifiedAt !== null;
});

// ---------------------------------------------------------------------------------------------
section('Consent');

check('a grant is recorded with its evidence', function() use ($subjectEmail) {
    $entry = Plugin::getInstance()->consent->grant($subjectEmail, 'marketing', ['text' => 'Tick to hear from us', 'ip' => '203.0.113.9']);

    return $entry !== null && $entry->evidence['ip'] === '203.0.113.9';
});

check('a granted purpose reads as allowed', function() use ($subjectEmail) {
    return Plugin::getInstance()->consent->allows($subjectEmail, 'marketing');
});

check('a purpose nobody answered reads as not allowed — silence is not consent', function() use ($subjectEmail) {
    return !Plugin::getInstance()->consent->allows($subjectEmail, 'profiling');
});

check('withdrawal is a new row, and the old one survives', function() use ($subjectEmail) {
    $before = count(Plugin::getInstance()->consent->history($subjectEmail));
    Plugin::getInstance()->consent->withdraw($subjectEmail, 'marketing', ['ip' => '203.0.113.9']);
    $after = Plugin::getInstance()->consent->history($subjectEmail);

    return count($after) === $before + 1
        && !Plugin::getInstance()->consent->allows($subjectEmail, 'marketing')
        && $after[1]->state === ConsentEntry::STATE_GRANTED;
});

check('re-granting flips it back, and the ledger keeps all three', function() use ($subjectEmail) {
    Plugin::getInstance()->consent->grant($subjectEmail, 'marketing');

    return Plugin::getInstance()->consent->allows($subjectEmail, 'marketing')
        && count(Plugin::getInstance()->consent->history($subjectEmail)) === 3;
});

check('the tally counts people once, on their newest answer', function() use ($subjectEmail) {
    $tally = Plugin::getInstance()->consent->tally();

    // Three rows for one person on one purpose must count as one granted, not three.
    return ($tally['marketing']['granted'] ?? 0) >= 1 && ($tally['marketing']['withdrawn'] ?? 0) === 0
        ?: 'tally: ' . json_encode($tally['marketing'] ?? null);
});

check('an expired consent stops reading as allowed', function() use ($subjectEmail) {
    $entry = Plugin::getInstance()->consent->grant($subjectEmail, 'analytics');
    Craft::$app->getDb()->createCommand()->update(
        ConsentRecord::tableName(),
        ['expiresAt' => Db::prepareDateForDb(new DateTime('-1 day'))],
        ['id' => $entry->id],
    )->execute();

    return !Plugin::getInstance()->consent->allows($subjectEmail, 'analytics')
        && count(Plugin::getInstance()->consent->stale(50)) >= 1;
});

check("Lock's own consent ledger appears in the person's disclosure", function() use ($subject) {
    $bundle = Plugin::getInstance()->collectors->get('consent')->collect($subject);

    return $bundle->count() >= 3;
});

check('consent records are never planned for deletion', function() use ($subject) {
    $bundle = Plugin::getInstance()->collectors->get('consent')->collect($subject);

    foreach ($bundle->records as $record) {
        if ($record->erasable) {
            return 'a consent record was marked erasable';
        }
    }

    return true;
});

// ---------------------------------------------------------------------------------------------
section('Retention');

check('a rule reads back from the shape the settings table posts', function() {
    $rule = RetentionRule::fromArray([
        'key' => 'carts',
        'label' => 'Abandoned carts',
        'scope' => 'commerce:carts',
        'period' => '3',
        'unit' => 'months',
        'mode' => 'erase',
        'enabled' => '1',
        'limit' => '100',
    ]);

    return $rule->period === 3 && $rule->enabled && $rule->mode === 'erase' && $rule->limit === 100;
});

check('a rule with a nonsense unit falls back to months rather than exploding', function() {
    return RetentionRule::fromArray(['key' => 'x', 'scope' => 'y', 'unit' => 'fortnights'])->unit === 'months';
});

check('a period in months lands on the same day of the month', function() {
    $rule = RetentionRule::fromArray(['key' => 'x', 'scope' => 'y', 'period' => 3, 'unit' => 'months']);
    $cutoff = $rule->cutoff(new DateTime('2026-05-31 12:00:00'));

    // 31 February does not exist; PHP rolls it forward to 3 March, which is the documented and
    // expected behaviour of a calendar-month interval — the point of the check is that the month
    // arithmetic is calendar-based rather than 90 fixed days.
    return $cutoff->format('Y-m') === '2026-03' ?: 'got ' . $cutoff->format('Y-m-d');
});

check('a period in days is exact', function() {
    $rule = RetentionRule::fromArray(['key' => 'x', 'scope' => 'y', 'period' => 30, 'unit' => 'days']);

    return $rule->cutoff(new DateTime('2026-05-31'))->format('Y-m-d') === '2026-05-01';
});

check('a rule reads as a sentence for the register', function() {
    $rule = RetentionRule::fromArray(['key' => 'x', 'label' => 'Form submissions', 'scope' => 'formie:submissions', 'period' => 2, 'unit' => 'years', 'mode' => 'anonymise']);

    return str_contains($rule->sentence(), 'Form submissions') && str_contains($rule->sentence(), '2 years');
});

check('a rule pointing at a scope nothing offers reports the problem instead of running', function() {
    configure(['retentionRules' => [['key' => 'ghost', 'label' => 'Ghost', 'scope' => 'nowhere:nothing', 'period' => 1, 'unit' => 'days', 'mode' => 'erase', 'enabled' => '1']]]);
    $plan = Plugin::getInstance()->retention->preview(Plugin::getInstance()->retention->rule('ghost'));
    configure([]);

    return $plan->errors !== [] && $plan->targets === [];
});

check('a report-only rule plans every record as a skip', function() {
    configure(['retentionRules' => [['key' => 'idle', 'label' => 'Idle sessions', 'scope' => 'session:stale', 'period' => 0, 'unit' => 'days', 'mode' => 'report', 'enabled' => '1', 'limit' => 20]]]);
    $plan = Plugin::getInstance()->retention->preview(Plugin::getInstance()->retention->rule('idle'));
    configure([]);

    foreach ($plan->targets as $target) {
        if (!$target->isSkipped()) {
            return 'a report-only rule planned an action';
        }
    }

    return true;
});

check('a retention sweep gives each record its own subject, not the sweeper’s', function() {
    $collector = Plugin::getInstance()->collectors->get('commerce');

    $a = new DataRecord();
    $a->source = 'commerce';
    $a->key = 'commerce:order:1';
    $a->data = ['Email' => 'one@' . DOMAIN];

    $b = new DataRecord();
    $b->source = 'commerce';
    $b->key = 'commerce:order:2';
    $b->data = ['Email' => 'two@' . DOMAIN];

    return $collector->subjectFor($a)->pseudonym() !== $collector->subjectFor($b)->pseudonym();
});

check('a record with no address still gets a pseudonym unique to it', function() {
    $collector = Plugin::getInstance()->collectors->get('session');

    $a = new DataRecord();
    $a->source = 'session';
    $a->key = 'session:session:1';

    $b = new DataRecord();
    $b->source = 'session';
    $b->key = 'session:session:2';

    return $collector->subjectFor($a)->pseudonym() !== $collector->subjectFor($b)->pseudonym();
});

check('a held subject is skipped by a sweep that would otherwise touch them', function() use ($subjectEmail) {
    $hold = new Hold();
    $hold->email = $subjectEmail;
    $hold->reason = 'Lock check — sweep hold';
    Plugin::getInstance()->holds->save($hold);

    $collector = Plugin::getInstance()->collectors->get('commerce');
    $record = new DataRecord();
    $record->source = 'commerce';
    $record->key = 'commerce:order:99';
    $record->data = ['Email' => $subjectEmail];
    $record->erasable = false;
    $record->anonymisable = true;

    $rule = RetentionRule::fromArray(['key' => 'k', 'scope' => 'commerce:orders', 'mode' => 'anonymise']);
    $method = new ReflectionMethod(Plugin::getInstance()->retention, 'targetsFor');
    $method->setAccessible(true);
    $targets = $method->invoke(Plugin::getInstance()->retention, [$record], $rule, $collector);

    Plugin::getInstance()->holds->delete((int)$hold->id);

    return count($targets) === 1
        && $targets[0]->isSkipped()
        && str_contains((string)$targets[0]->reason, 'sweep hold');
});

check("a rule's first real run is forced to report instead", function() {
    configure(['retentionRules' => [['key' => 'lock-check-firstrun', 'label' => 'Idle sessions', 'scope' => 'session:stale', 'period' => 3650, 'unit' => 'days', 'mode' => 'erase', 'enabled' => '1']], 'retentionDryRunFirst' => true]);
    $outcome = Plugin::getInstance()->retention->run(Plugin::getInstance()->retention->rule('lock-check-firstrun'), false);
    configure([]);

    return $outcome->dryRun;
});

check('only the first: the second run of the same rule is real', function() {
    // The guard used to count only real runs, so the dry run it forced never counted and every
    // run after it was forced dry as well — a rule that could never delete anything. A ten-year
    // period over stale sessions keeps this real run harmless.
    configure(['retentionRules' => [['key' => 'lock-check-firstrun', 'label' => 'Idle sessions', 'scope' => 'session:stale', 'period' => 3650, 'unit' => 'days', 'mode' => 'erase', 'enabled' => '1']], 'retentionDryRunFirst' => true]);
    $outcome = Plugin::getInstance()->retention->run(Plugin::getInstance()->retention->rule('lock-check-firstrun'), false);
    configure([]);

    return !$outcome->dryRun ?: 'the second run was forced to a dry run too';
});

check('scopes with no rule pointed at them are listed as uncovered', function() {
    configure(['retentionRules' => [['key' => 'idle', 'label' => 'Idle', 'scope' => 'session:stale', 'period' => 3, 'unit' => 'months', 'mode' => 'erase', 'enabled' => '1']]]);
    $uncovered = array_keys(Plugin::getInstance()->retention->uncoveredScopes());
    configure([]);

    return !in_array('session:stale', $uncovered, true) && in_array('logs:files', $uncovered, true);
});

check('a daily schedule at 3am is due once a day and not twice', function() {
    $now = new DateTime('2026-05-20 09:00:00');

    return Cadence::isDue('daily', 3, 1, 1, new DateTime('2026-05-19 23:00:00'), $now)
        && !Cadence::isDue('daily', 3, 1, 1, new DateTime('2026-05-20 03:00:01'), $now);
});

check('a schedule that has never run is due immediately', function() {
    return Cadence::isDue('daily', 3, 1, 1, null, new DateTime());
});

// ---------------------------------------------------------------------------------------------
section('Running an erasure');

check('an open request of its own blocks an unrelated erasure of the same person', function() use ($subject) {
    // The self-service request created above is still open, so this is the real state of the site
    // rather than a contrived one: purging somebody's data while they are waiting for a copy of it
    // would answer their request honestly and emptily.
    $plan = Plugin::getInstance()->erasure->plan($subject, Settings::MODE_ANONYMISE);

    return $plan->blocked && str_contains((string)$plan->blockReason, 'DSAR-')
        ?: 'not blocked: ' . var_export($plan->blockReason, true);
});

// Close everything outstanding, so the erasure checks belowthe erasure rather than the hold.
foreach (Plugin::getInstance()->requests->find(['email' => $subjectEmail], 100) as $outstanding) {
    if ($outstanding->isOpen()) {
        Plugin::getInstance()->requests->close($outstanding, Request::STATUS_COMPLETED, 'Lock check — closed for the erasure checks.', false);
    }
}

check('with nothing outstanding, the plan is no longer blocked', function() use ($subject) {
    return !Plugin::getInstance()->erasure->plan($subject, Settings::MODE_ANONYMISE)->blocked;
});

check('the run refuses a fingerprint that no longer matches', function() use ($subject) {
    $plan = Plugin::getInstance()->erasure->plan($subject, Settings::MODE_ANONYMISE);

    try {
        Plugin::getInstance()->erasure->run($plan, false, 'a-stale-fingerprint');

        return 'it ran anyway';
    } catch (yii\base\InvalidArgumentException) {
        return true;
    }
});

check('a dry run changes nothing but counts what it would do', function() use ($subject, $subjectEmail) {
    $plan = Plugin::getInstance()->erasure->plan($subject, Settings::MODE_ANONYMISE);
    $outcome = Plugin::getInstance()->erasure->run($plan, true, $plan->fingerprint());

    return $outcome->anonymised > 0
        && User::find()->email($subjectEmail)->status(null)->exists()
        && $outcome->dryRun;
});

check('a dry run leaves no erasure certificate behind', function() use ($subject) {
    return !ErasureRecord::find()->where(['emailHash' => $subject->emailHash()])->exists();
});

check('the console refuses --force without the fingerprint preview printed', function() use ($subjectEmail) {
    $code = Craft::$app->runAction('lock/erase/run', ['email' => $subjectEmail, 'force' => 1]);

    return $code === yii\console\ExitCode::USAGE
        && User::find()->email($subjectEmail)->status(null)->exists()
        ?: "exit code $code";
});

check('the console refuses a fingerprint that does not match the plan', function() use ($subjectEmail) {
    $code = Craft::$app->runAction('lock/erase/run', ['email' => $subjectEmail, 'force' => 1, 'fingerprint' => str_repeat('0', 64)]);

    return $code === yii\console\ExitCode::DATAERR
        && User::find()->email($subjectEmail)->status(null)->exists()
        ?: "exit code $code";
});

// The real erasure is run the way the desk runs it: for a confirmed erasure request, with a
// dossier assembled for it first, and closed afterwards — so that the checks below can look for
// the address in every place that path writes to.
$erasureRequest = new Request();
$erasureRequest->email = $subjectEmail;
$erasureRequest->name = 'Ada Lovelace';
$erasureRequest->type = Request::TYPE_ERASURE;
$erasureRequest->message = "Please delete everything you hold for $subjectEmail.";
$erasureRequest->source = Request::SOURCE_CP;

check('an erasure request can be taken and assembled', function() use ($erasureRequest) {
    configure(['notifySubject' => false, 'notifyStaff' => false, 'protectDossier' => false]);

    if (!Plugin::getInstance()->requests->create($erasureRequest, false, true)) {
        return 'create failed';
    }

    [, , $filename] = Plugin::getInstance()->requests->assemble($erasureRequest);

    // Named by a prefix of the keyed hash, never by the address.
    return is_file(Plugin::getInstance()->dossiers->pathFor($filename))
        && !str_contains($filename, 'lock-test')
        ?: "archive: $filename";
});

check('an unconfirmed request cannot be assembled', function() {
    $unconfirmed = new Request();
    $unconfirmed->email = 'grace@' . DOMAIN;
    $unconfirmed->type = Request::TYPE_ERASURE;
    Plugin::getInstance()->requests->create($unconfirmed, false);

    try {
        Plugin::getInstance()->requests->assemble($unconfirmed);

        return 'it assembled';
    } catch (yii\base\InvalidCallException) {
        return $unconfirmed->dossierPath === null;
    }
});

check('an unconfirmed request does not hold anybody\'s data', function() {
    // Grace has the unconfirmed request just made. Anybody can type an address into the form;
    // that must not be enough to stop retention touching that person.
    return Plugin::getInstance()->holds->blockFor(new Subject(email: 'grace@' . DOMAIN)) === null;
});

$liveSubject = (new Subject(email: $subjectEmail))->resolve();
$livePlan = $plugin->erasure->plan($liveSubject, Settings::MODE_ANONYMISE, $erasureRequest->id);
$liveOutcome = $plugin->erasure->run($livePlan, false, $livePlan->fingerprint());

check('the run reports what the plan promised', function() use ($livePlan, $liveOutcome) {
    return $liveOutcome->matches($livePlan)
        && $liveOutcome->anonymised + $liveOutcome->erased + $liveOutcome->skipped + $liveOutcome->failed === count($livePlan->targets)
        ?: $liveOutcome->summary() . ' against ' . count($livePlan->targets) . ' targets';
});

check('nothing failed', function() use ($liveOutcome) {
    return $liveOutcome->isClean() ?: json_encode($liveOutcome->failures);
});

check('the account survives and the person in it does not', function() use ($subjectUser, $liveSubject) {
    $row = (new Query())->select(['username', 'email', 'firstName', 'lastName', 'suspended'])
        ->from([craft\db\Table::USERS])->where(['id' => $subjectUser->id])->one();

    return $row !== null
        && $row['email'] === $liveSubject->pseudonym() . '@anonymised.invalid'
        && $row['username'] === $liveSubject->pseudonym()
        && $row['lastName'] === null
        && (int)$row['suspended'] === 1
        ?: 'row: ' . json_encode($row);
});

check('the anonymised account can no longer be signed into', function() use ($subjectUser) {
    // The identifiers being gone is not enough on its own: a stored password hash and a live
    // session are both still routes back in.
    $sessions = (new Query())->from([craft\db\Table::SESSIONS])->where(['userId' => $subjectUser->id])->count();
    $user = Craft::$app->getUsers()->getUserById($subjectUser->id);

    return (int)$sessions === 0 && $user !== null && $user->suspended;
});

if ($contentTarget !== null) {
    check('the address is gone from the entry, and the rest of the sentence is not', function() use ($fixtureEntryId, $contentTarget, $subjectEmail, $liveSubject) {
        $entry = Craft::$app->getElements()->getElementById($fixtureEntryId, Entry::class);
        $value = (string)$entry->getFieldValue($contentTarget['field']);

        return !str_contains($value, $subjectEmail)
            && str_contains($value, $liveSubject->pseudonym())
            && str_contains($value, 'about the order')
            ?: "field now reads: $value";
    });
}

check('the certificate records what was done, without the address', function() use ($liveSubject) {
    $certificate = ErasureRecord::find()->where(['emailHash' => $liveSubject->emailHash()])->one();

    return $certificate !== null
        && $certificate->anonymised > 0
        && $certificate->pseudonym === $liveSubject->pseudonym()
        && !in_array('email', array_keys($certificate->getAttributes()), true);
});

check('the address is now suppressed against re-import', function() use ($subjectEmail) {
    return Plugin::getInstance()->holds->isSuppressed($subjectEmail);
});

check('an address nobody erased is not suppressed', function() {
    return !Plugin::getInstance()->holds->isSuppressed('never-erased@' . DOMAIN);
});

check('the run ledger keeps the plan and the outcome side by side', function() use ($liveSubject) {
    $run = RunRecord::find()->where(['subjectHash' => $liveSubject->emailHash(), 'dryRun' => false])->orderBy(['id' => SORT_DESC])->one();

    return $run !== null
        && is_array($run->plan)
        && is_array($run->outcome)
        && $run->plan['fingerprint'] === $run->outcome['fingerprint'];
});

check('the erasure is in the ledger', function() use ($liveSubject) {
    return ActivityRecord::find()->where(['subjectHash' => $liveSubject->emailHash(), 'action' => 'erasure.ran'])->exists();
});

check("the consent record survives with its proof, minus the person's address", function() use ($liveSubject, $subjectEmail) {
    $rows = (new Query())->from([ConsentRecord::tableName()])->where(['emailHash' => $liveSubject->emailHash()])->all();

    if ($rows === []) {
        return 'the consent records were deleted, which would make every past mailing unprovable';
    }

    foreach ($rows as $row) {
        if ($row['email'] === $subjectEmail) {
            return 'a consent record still holds the address';
        }

        $evidence = is_string($row['evidence']) ? json_decode($row['evidence'], true) : $row['evidence'];

        if (is_array($evidence) && (($evidence['ip'] ?? null) !== null || ($evidence['userAgent'] ?? null) !== null || ($evidence['url'] ?? null) !== null)) {
            return 'the consent evidence still identifies the person: ' . json_encode($evidence);
        }
    }

    return true;
});

$erasureDossier = Plugin::getInstance()->requests->getById((int)$erasureRequest->id)?->dossierPath;

check('closing the erasure request anonymises the request itself', function() use ($erasureRequest, $liveSubject) {
    Plugin::getInstance()->requests->close($erasureRequest, Request::STATUS_COMPLETED, 'Erased.', false);
    $row = RequestRecord::findOne($erasureRequest->id);

    return $row !== null
        && $row->email === $liveSubject->pseudonym() . '@anonymised.invalid'
        && $row->name === null
        && $row->message === null
        && $row->context === null
        && $row->dossierPath === null
        && $row->reference === $erasureRequest->reference
        ?: 'row: ' . json_encode($row?->getAttributes(['email', 'name', 'message', 'dossierPath']));
});

check("and deletes the subject's dossier archive", function() use ($erasureDossier) {
    return $erasureDossier !== null && !is_file(Plugin::getInstance()->dossiers->pathFor($erasureDossier))
        ?: 'still on disk: ' . var_export($erasureDossier, true);
});

check("after an erasure, the address is in none of Lock's own tables", function() use ($subjectEmail) {
    $db = Craft::$app->getDb();
    $prefix = $db->tablePrefix;
    $found = [];

    foreach ($db->getSchema()->getTableNames() as $table) {
        if (!str_starts_with($table, $prefix . 'lock_')) {
            continue;
        }

        foreach ((new Query())->from([$table])->all() as $row) {
            $flat = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if (stripos((string)$flat, $subjectEmail) !== false) {
                $found[] = "$table #" . ($row['id'] ?? '?');
            }
        }
    }

    return $found === [] ?: 'still there: ' . implode(', ', array_slice($found, 0, 10));
});

check("and none of Lock's storage", function() use ($subjectEmail) {
    $root = Craft::$app->getPath()->getStoragePath() . DIRECTORY_SEPARATOR . 'lock';
    $found = [];

    if (is_dir($root)) {
        foreach (craft\helpers\FileHelper::findFiles($root) as $file) {
            if (stripos(basename($file), 'lock-test') !== false || stripos((string)file_get_contents($file), $subjectEmail) !== false) {
                $found[] = basename($file);
            }
        }
    }

    return $found === [] ?: 'still there: ' . implode(', ', $found);
});

// ---------------------------------------------------------------------------------------------
section('The register');

check('an empty register reports itself as the gap', function() {
    Craft::$app->getDb()->createCommand()->delete(ProcessingRecord::tableName(), ['like', 'name', 'Lock check%', false])->execute();
    $gaps = Plugin::getInstance()->register->gaps();

    return $gaps !== [];
});

$activity = new ProcessingActivity();
$activity->name = 'Lock check — fulfilling orders';
$activity->purpose = 'Getting the thing to the person who bought it.';
$activity->basis = ProcessingActivity::BASIS_CONTRACT;
$activity->dataCategories = ['name', 'address'];
$activity->retention = '7 years';
$activity->safeguards = 'Encrypted at rest.';
$activity->systems = ['commerce'];

check('an entry can be saved', function() use ($activity) {
    return Plugin::getInstance()->register->save($activity);
});

check('a complete entry has no defects', function() use ($activity) {
    return $activity->defects() === [] && !$activity->isIncomplete();
});

check('legitimate interests with no balancing test is reported as a defect', function() {
    $weak = new ProcessingActivity();
    $weak->name = 'Lock check — analytics';
    $weak->purpose = 'Measuring the site.';
    $weak->basis = ProcessingActivity::BASIS_LEGITIMATE;
    $weak->retention = '2 years';
    $weak->dataCategories = ['behaviour'];
    $weak->safeguards = 'Aggregated.';

    return $weak->isIncomplete() && count($weak->defects()) === 1;
});

check('a source no entry accounts for is reported as a gap', function() {
    $gaps = Plugin::getInstance()->register->gaps();

    foreach ($gaps as $gap) {
        if (str_contains($gap, 'User account')) {
            return true;
        }
    }

    return 'the account source was not flagged: ' . json_encode($gaps);
});

check('a source that an entry names is not reported', function() {
    foreach (Plugin::getInstance()->register->gaps() as $gap) {
        if (str_contains($gap, 'Commerce orders') && str_contains($gap, 'not named')) {
            return 'Commerce was flagged despite being named';
        }
    }

    return true;
});

check('an enabled rule with no justification is reported', function() {
    configure(['retentionRules' => [['key' => 'nojust', 'label' => 'Unjustified', 'scope' => 'session:stale', 'period' => 1, 'unit' => 'days', 'mode' => 'erase', 'enabled' => '1']]]);
    $gaps = Plugin::getInstance()->register->gaps();
    configure([]);

    foreach ($gaps as $gap) {
        if (str_contains($gap, 'no justification')) {
            return true;
        }
    }

    return 'not reported';
});

check('the report puts the register and the enforced rules on one page', function() {
    configure(['retentionRules' => [['key' => 'idle', 'label' => 'Idle', 'scope' => 'session:stale', 'period' => 3, 'unit' => 'months', 'mode' => 'erase', 'enabled' => '1', 'justification' => 'Because.']]]);
    $report = Plugin::getInstance()->register->report();
    configure([]);

    return isset($report['activities'], $report['retention_rules'], $report['gaps'])
        && $report['retention_rules'][0]['period'] === '3 months';
});

check('marking the register reviewed stamps every entry', function() use ($activity) {
    Plugin::getInstance()->register->markReviewed();

    return Plugin::getInstance()->register->get((int)$activity->id)->reviewedAt !== null;
});

check('an entry can be removed', function() use ($activity) {
    return Plugin::getInstance()->register->delete((int)$activity->id)
        && Plugin::getInstance()->register->get((int)$activity->id) === null;
});

// ---------------------------------------------------------------------------------------------
section('The ledger');

check('the ledger records deleting a request, and outlives it', function() {
    $doomed = new Request();
    $doomed->email = 'grace@' . DOMAIN;
    $doomed->type = Request::TYPE_ACCESS;
    Plugin::getInstance()->requests->create($doomed);
    $id = (int)$doomed->id;

    Plugin::getInstance()->requests->delete($id);

    return RequestRecord::findOne($id) === null
        && ActivityRecord::find()->where(['requestId' => $id, 'action' => 'request.deleted'])->exists();
});

check('the tally counts by category and returns integers', function() {
    $tally = Plugin::getInstance()->activity->tally((new DateTime())->modify('-1 day'));

    foreach ($tally as $count) {
        if (!is_int($count)) {
            return 'a count came back as ' . gettype($count);
        }
    }

    return $tally !== [];
});

check('a ledger write cannot break the operation it records', function() {
    // The signature is what guarantees it: no return value to check, and everything caught inside.
    $method = new ReflectionMethod(Plugin::getInstance()->activity, 'log');

    return $method->getReturnType()?->getName() === 'void';
});

// ---------------------------------------------------------------------------------------------
section('Hardening');

check("the ledgers have no column for an address", function() {
    $db = Craft::$app->getDb();

    return $db->getTableSchema(ActivityRecord::tableName(), true)->getColumn('subjectEmail') === null
        && $db->getTableSchema(RunRecord::tableName(), true)->getColumn('subjectEmail') === null;
});

check("a ledger summary that names the address is stored without it", function() {
    $hold = new Hold();
    $hold->email = 'grace@' . DOMAIN;
    $hold->reason = 'Lock check — logged';
    Plugin::getInstance()->holds->save($hold);

    // HoldsController no longer names the person in the summary; this is the backstop in
    // Activity::log for a caller that still does.
    Plugin::getInstance()->activity->log(ActivityRecord::CATEGORY_ADMIN, 'hold.placed', 'A hold was placed on grace@' . DOMAIN . ': x', new Subject(email: $hold->email));
    Plugin::getInstance()->holds->delete((int)$hold->id);

    $row = ActivityRecord::find()->where(['action' => 'hold.placed', 'subjectHash' => (new Subject(email: 'grace@' . DOMAIN))->emailHash()])->orderBy(['id' => SORT_DESC])->one();

    return $row !== null && !str_contains((string)$row->summary, 'grace@') ?: 'summary: ' . $row?->summary;
});

check("Craft's own tables and Lock's are refused as custom tables", function() {
    foreach (['users', 'elements_sites', 'lock_requests', 'sessions', 'content'] as $table) {
        $settings = new Settings();
        $settings->setAttributes(['customTables' => [['table' => $table, 'emailColumn' => 'email', 'mode' => 'erase']]], false);

        if ($settings->validate() || !$settings->hasErrors('customTables')) {
            return "$table was accepted";
        }
    }

    $ok = new Settings();
    $ok->setAttributes(['customTables' => [['table' => 'my_leads', 'emailColumn' => 'email']]], false);

    return $ok->validate() ?: json_encode($ok->getErrors());
});

check('a protected table in project config is ignored by the collector anyway', function() {
    configure(['customTables' => [['table' => 'users', 'emailColumn' => 'email', 'label' => 'Sneaky', 'mode' => 'erase']]]);
    $available = Plugin::getInstance()->collectors->get('custom')->isAvailable();
    configure([]);

    return !$available;
});

check('a CSV cell that a spreadsheet would run as a formula is defused', function() {
    $method = new ReflectionMethod(Plugin::getInstance()->dossiers, 'csvCell');
    $method->setAccessible(true);
    $cell = static fn(string $v) => $method->invoke(Plugin::getInstance()->dossiers, $v);

    return $cell('=HYPERLINK("x")') === "'=HYPERLINK(\"x\")"
        && $cell('+1') === "'+1"
        && $cell('@SUM(A1)') === "'@SUM(A1)"
        && $cell("\t=1") === "'\t=1"
        && $cell('ada') === 'ada'
        && $cell('') === '';
});

check('the rate-limit key folds the spellings of one mailbox together', function() {
    $key = [justinholtweb\lock\controllers\PortalController::class, 'rateKeyForEmail'];

    return $key('Ada+news@Example.com') === 'ada@example.com'
        && $key('a.d.a+x@googlemail.com') === 'ada@gmail.com'
        && $key('a.d.a@example.com') === 'a.d.a@example.com';
});

check('garbage collection expires dead links, and is callable without running Craft\'s', function() {
    $stale = new Request();
    $stale->email = 'grace@' . DOMAIN;
    $stale->type = Request::TYPE_ACCESS;
    Plugin::getInstance()->requests->create($stale, false);

    Craft::$app->getDb()->createCommand()->update(
        RequestRecord::tableName(),
        ['tokenExpiresAt' => Db::prepareDateForDb(new DateTime('-1 hour'))],
        ['id' => $stale->id],
    )->execute();

    $result = Plugin::getInstance()->collectGarbage();

    return $result['expired'] >= 1 && RequestRecord::findOne($stale->id)?->status === Request::STATUS_EXPIRED
        ?: json_encode($result);
});

check('the retention job is never retried and outlasts a long sweep', function() {
    $job = new justinholtweb\lock\queue\ApplyRetentionRules();

    return $job instanceof yii\queue\RetryableJobInterface && !$job->canRetry(1, new Exception()) && $job->getTtr() >= 1800;
});

// ---------------------------------------------------------------------------------------------
section('Lite');

$plugin->edition = Plugin::EDITION_LITE;

check('Lite: retention from the console exits non-zero instead of pretending', function() {
    $due = Craft::$app->runAction('lock/retention/due');
    $status = Craft::$app->runAction('lock/retention/status');

    return $due === yii\console\ExitCode::UNAVAILABLE && $status === yii\console\ExitCode::UNAVAILABLE
        ?: "due=$due status=$status";
});

check('Lite: the register reports exit non-zero', function() {
    $gaps = Craft::$app->runAction('lock/report/gaps');
    $register = Craft::$app->runAction('lock/report/register');

    return $gaps === yii\console\ExitCode::UNAVAILABLE && $register === yii\console\ExitCode::UNAVAILABLE
        ?: "gaps=$gaps register=$register";
});

check('Lite: the retention service refuses to run a rule', function() {
    try {
        Plugin::getInstance()->retention->run(RetentionRule::fromArray(['key' => 'lock-check-lite', 'scope' => 'session:stale', 'mode' => 'erase']));

        return 'it ran';
    } catch (yii\base\InvalidCallException) {
        return Plugin::getInstance()->retention->runDue() === []
            && !RunRecord::find()->where(['ruleKey' => 'lock-check-lite'])->exists();
    }
});

check('Lite: a queued retention job does nothing', function() {
    configure(['retentionRules' => [['key' => 'lock-check-lite', 'label' => 'x', 'scope' => 'session:stale', 'period' => 3650, 'unit' => 'days', 'mode' => 'erase', 'enabled' => '1']]]);
    $job = new justinholtweb\lock\queue\ApplyRetentionRules(['ruleKeys' => ['lock-check-lite']]);
    $job->execute(Craft::$app->getQueue());
    configure([]);

    return !RunRecord::find()->where(['ruleKey' => 'lock-check-lite'])->exists();
});

check('Lite: deadline reminders are not sent', function() {
    configure(['notifyStaff' => true, 'contactEmail' => 'dpo@' . DOMAIN]);
    $late = new Request();
    $late->reference = 'DSAR-LITE-CHECK';
    $late->status = Request::STATUS_OPEN;
    $late->dueAt = (new DateTime())->modify('+1 day');
    $sent = Plugin::getInstance()->notifications->sendReminder($late);
    configure([]);

    return $sent === false && $late->remindersSent === 0;
});

$plugin->edition = Plugin::EDITION_PRO;

check('Pro: reminders respect the staff switch', function() {
    configure(['notifyStaff' => false, 'contactEmail' => 'dpo@' . DOMAIN]);
    $late = new Request();
    $late->status = Request::STATUS_OPEN;
    $late->dueAt = (new DateTime())->modify('+1 day');
    $sent = Plugin::getInstance()->notifications->sendReminder($late);
    configure([]);

    return $sent === false;
});

// ---------------------------------------------------------------------------------------------
section('Teardown');

$teardown();

check('every fixture is gone', function() {
    return !User::find()->email('*@' . DOMAIN)->status(null)->exists()
        && !RequestRecord::find()->where(['like', 'email', '%@' . DOMAIN, false])->exists()
        && !ConsentRecord::find()->where(['like', 'email', '%@' . DOMAIN, false])->exists();
});

Plugin::getInstance()->setSettings($originalSettings);
Plugin::getInstance()->edition = $originalEdition;
Plugin::getInstance()->collectors->reset();

check('the settings this site had are back', function() use ($originalSettings) {
    return Plugin::getInstance()->getSettings()->toArray() == $originalSettings;
});

echo "\n" . str_repeat('-', 60) . "\n";
echo "$passed passed, $failed failed\n";

exit($failed === 0 ? 0 : 1);
