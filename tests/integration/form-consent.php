<?php
/**
 * Consent captured from Formie and Freeform forms, with emailed confirmation.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-lock/tests/integration/form-consent.php
 *
 * Three halves:
 *
 * - **The capture rules**, in this process: a signed-out grant waits and emails a link, the link
 *   confirms once and expires, a withdrawal applies at once, only changes are written, and the
 *   intake rate limit holds. Mail goes to a file transport here.
 * - **Freeform**, against a real form built by Freeform's own persistence events, read by the
 *   adapter the `after-submit` listener calls.
 * - **Formie, end to end over HTTP**: a real form, a real front-end submission, the email read
 *   back out of Mailpit, the link opened (which changes nothing) and the button pressed.
 *
 * Each half is skipped, and says so, when its plugin is not installed. Self-cleaning: forms,
 * ledger rows, pending rows, Mailpit messages and the project-config mapping are removed, the
 * last of them in a fresh PHP process (see the harness notes on stale project config).
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use justinholtweb\lock\models\ConsentEntry;
use justinholtweb\lock\models\Settings;
use justinholtweb\lock\models\Subject;
use justinholtweb\lock\Plugin;
use justinholtweb\lock\records\ConsentRecord;
use justinholtweb\lock\records\PendingConsentRecord;
use yii\mail\BaseMailer;
use yii\mail\MailEvent;

$passed = 0;
$failed = 0;
$skipped = [];

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
$service = $plugin->formConsent;
$originalSettings = $plugin->getSettings()->toArray();
$originalEdition = $plugin->edition;
$run = strtolower(StringHelper::randomString(6));
const DOMAIN = 'lock-test.invalid';
$db = Craft::$app->getDb();

/** Swaps settings in memory only. */
function configure(array $overrides): Settings
{
    global $originalSettings;
    $settings = new Settings();
    $settings->setAttributes(array_merge($originalSettings, $overrides), false);
    Plugin::getInstance()->setSettings($settings->toArray());

    /** @var Settings $settings */
    $settings = Plugin::getInstance()->getSettings();

    return $settings;
}

$cleanup = ['formie' => null, 'freeform' => null, 'mailpit' => [], 'projectConfig' => false, 'adminConsentFrom' => null];

$teardown = function() use (&$cleanup, $db) {
    $db->createCommand()->delete(ConsentRecord::tableName(), ['like', 'email', '%@' . DOMAIN, false])->execute();
    $db->createCommand()->delete(PendingConsentRecord::tableName(), ['like', 'email', '%@' . DOMAIN, false])->execute();

    if ($cleanup['adminConsentFrom'] !== null) {
        [$email, $fromId] = $cleanup['adminConsentFrom'];
        $db->createCommand()->delete(ConsentRecord::tableName(), ['and', ['email' => $email], ['>', 'id', $fromId]])->execute();
    }

    if ($cleanup['formie'] !== null) {
        $form = \verbb\formie\Formie::$plugin->getForms()->getFormById($cleanup['formie']);

        if ($form !== null) {
            foreach (\verbb\formie\elements\Submission::find()->formId($form->id)->status(null)->all() as $submission) {
                Craft::$app->getElements()->deleteElement($submission, true);
            }

            Craft::$app->getElements()->deleteElement($form, true);
        }
    }

    if ($cleanup['freeform'] !== null) {
        \Solspace\Freeform\Freeform::getInstance()->forms->deleteById($cleanup['freeform']);
    }

    if ($cleanup['mailpit'] !== []) {
        @(new Client(['timeout' => 3]))->delete('http://localhost:8025/api/v1/messages', ['json' => ['IDs' => $cleanup['mailpit']]]);
    }

    // Project config restore in a fresh process: the web process has changed it since this one
    // loaded it, so this process's copy is stale and would write nothing.
    if ($cleanup['projectConfig']) {
        $script = sys_get_temp_dir() . '/lock-form-consent-restore-' . getmypid() . '.php';
        file_put_contents($script, '<?php
            require "' . getcwd() . '/bootstrap.php";
            $app = require CRAFT_VENDOR_PATH . "/craftcms/cms/bootstrap/console.php";
            $pc = Craft::$app->getProjectConfig();
            $pc->remove("plugins.lock.settings.formConsents");
            $pc->saveModifiedConfigData();
            $pc->writeYamlFiles(true);
            echo $pc->get("plugins.lock.settings.formConsents") === null ? "restored" : "NOT restored";
        ');
        echo '  project config: ' . shell_exec(PHP_BINARY . ' ' . escapeshellarg($script)) . "\n";
        @unlink($script);
        $cleanup['projectConfig'] = false;
    }
};

// Mail from this process goes to files; every message is kept for the checks.
$mailDir = sys_get_temp_dir() . "/lock-form-consent-$run";
$mailer = Craft::$app->getMailer();
$mailer->useFileTransport = true;
$mailer->fileTransportPath = $mailDir;
$sent = [];
$mailer->on(BaseMailer::EVENT_BEFORE_SEND, function(MailEvent $event) use (&$sent) {
    $sent[] = ['to' => array_keys((array)$event->message->getTo()), 'body' => quoted_printable_decode((string)$event->message->toString())];
});

/** The confirmation code in the newest message to $to, read from the link. */
$codeIn = function(string $to) use (&$sent): ?string {
    foreach (array_reverse($sent) as $message) {
        if (in_array($to, $message['to'], true) && preg_match('#lock/confirm/([A-Za-z0-9_-]{43})#', $message['body'], $m)) {
            return $m[1];
        }
    }

    return null;
};

$mapping = static fn(string $plugin, string $form, array $extra = []) => array_merge([
    'plugin' => $plugin, 'form' => $form, 'field' => 'newsletter', 'emailField' => '', 'purpose' => 'marketing', 'action' => 'grant', 'wording' => '',
], $extra);

$box = static fn(string $purpose, bool $ticked = true, string $action = 'grant') => ['purpose' => $purpose, 'action' => $action, 'ticked' => $ticked, 'text' => 'Email me news.', 'field' => 'newsletter'];

// Lite throughout: consent capture is a Lite feature, and the checks would hide a gate otherwise.
$plugin->edition = Plugin::EDITION_LITE;

try {
    // -----------------------------------------------------------------------------------------
    section('Settings');

    check('a mapping row needs a known plugin, real handles and a configured purpose', function() use ($mapping) {
        $settings = new Settings();
        $settings->setAttributes(['formConsents' => [
            $mapping('wufoo', 'contact'),
            $mapping('formie', 'contact', ['field' => 'news letter']),
            $mapping('formie', 'contact', ['purpose' => 'telepathy']),
            ['plugin' => '', 'form' => '', 'field' => '', 'emailField' => '', 'purpose' => '', 'action' => '', 'wording' => ''],
        ]], false);
        $settings->validate(['formConsents']);
        $errors = $settings->getErrors('formConsents');

        return count($settings->formConsents) === 3 && count($errors) === 3 ?: json_encode($errors);
    });

    check('a good row validates, and formConsentsFor() picks out one form', function() use ($mapping) {
        $settings = new Settings();
        $settings->setAttributes(['formConsents' => [
            $mapping('formie', 'contact'),
            $mapping('freeform', 'contact', ['action' => 'withdraw', 'purpose' => 'analytics']),
        ]], false);

        return $settings->validate(['formConsents'])
            && count($settings->formConsentsFor('formie', 'contact')) === 1
            && $settings->formConsentsFor('freeform', 'contact')[0]['action'] === 'withdraw'
            && $settings->formConsentsFor('formie', 'other') === [] ?: json_encode($settings->getErrors());
    });

    configure([]);

    // -----------------------------------------------------------------------------------------
    section('Capture rules');

    $anon = "anon-$run@" . DOMAIN;

    check('a signed-out grant waits: a pending row and an email, nothing in the ledger', function() use ($service, $anon, $box, $codeIn, $db) {
        $result = $service->capture('formie', 'contact', $anon, [$box('marketing')], ['submission' => 99]);
        $row = (new Query())->from(PendingConsentRecord::tableName())->where(['email' => $anon])->one();

        return $result['pending'] === ['marketing']
            && $row !== null && $row['tokenHash'] === hash('sha256', (string)$result['code'])
            && $codeIn($anon) === $result['code']
            && !Plugin::getInstance()->consent->allows($anon, 'marketing')
            && !ConsentRecord::find()->where(['email' => $anon])->exists()
            ?: json_encode([$result, $row]);
    });

    check('the stored row has no plaintext code, and its address is keyed for erasure', function() use ($anon, $codeIn) {
        $row = (new Query())->from(PendingConsentRecord::tableName())->where(['email' => $anon])->one();

        return !str_contains(json_encode($row), (string)$codeIn($anon))
            && $row['emailHash'] === (new Subject(email: $anon))->emailHash();
    });

    check('the link looks up by exact code only, and never by a malformed one', function() use ($service, $anon, $codeIn) {
        $code = (string)$codeIn($anon);
        $wrong = substr($code, 0, 42) . ($code[42] === 'A' ? 'B' : 'A');

        return $service->getPendingByCode($code) !== null
            && $service->getPendingByCode($wrong) === null
            && $service->getPendingByCode(substr($code, 0, 20)) === null
            && $service->getPendingByCode('%') === null
            && $service->getPendingByCode('') === null;
    });

    check('confirming records a verified grant with the wording, form and submission', function() use ($service, $anon, $codeIn) {
        $pending = $service->getPendingByCode((string)$codeIn($anon));
        $recorded = $service->confirm($pending);
        $latest = Plugin::getInstance()->consent->latest($anon, 'marketing');

        return $recorded === ['marketing']
            && $latest !== null && $latest->state === ConsentEntry::STATE_GRANTED
            && ($latest->evidence['verified'] ?? null) === true
            && ($latest->evidence['confirmedBy'] ?? null) === 'email'
            && ($latest->evidence['text'] ?? null) === 'Email me news.'
            && ($latest->evidence['form'] ?? null) === 'formie:contact'
            && ($latest->evidence['submission'] ?? null) === 99
            ?: json_encode($latest?->evidence);
    });

    check('the link is single use: the row is gone and a second confirm records nothing', function() use ($service, $anon, $codeIn) {
        $code = (string)$codeIn($anon);
        $stale = new PendingConsentRecord(['id' => 0, 'tokenHash' => hash('sha256', $code)]);

        return $service->getPendingByCode($code) === null
            && $service->confirm($stale) === null
            && count(Plugin::getInstance()->consent->history($anon)) === 1;
    });

    check('ticking a purpose that is already allowed sends nothing and stores nothing', function() use ($service, $anon, $box, &$sent) {
        $before = count($sent);
        $result = $service->capture('formie', 'contact', $anon, [$box('marketing')]);

        return $result['pending'] === [] && $result['code'] === null && count($sent) === $before
            && !PendingConsentRecord::find()->where(['email' => $anon])->exists();
    });

    check('an unticked box is no decision at all', function() use ($service, $box, $run) {
        $email = "unticked-$run@" . DOMAIN;
        $result = $service->capture('formie', 'contact', $email, [$box('marketing', false), $box('analytics', false, 'withdraw')]);

        return $result === ['granted' => [], 'pending' => [], 'withdrawn' => [], 'limited' => false, 'code' => null]
            && !PendingConsentRecord::find()->where(['email' => $email])->exists()
            && !ConsentRecord::find()->where(['email' => $email])->exists();
    });

    check('a ticked withdraw box applies at once, marked unverified', function() use ($service, $anon, $box) {
        $result = $service->capture('freeform', 'unsubscribe', $anon, [$box('marketing', true, 'withdraw')]);
        $latest = Plugin::getInstance()->consent->latest($anon, 'marketing');

        return $result['withdrawn'] === ['marketing']
            && $latest->state === ConsentEntry::STATE_WITHDRAWN
            && ($latest->evidence['verified'] ?? null) === false
            && ($latest->evidence['form'] ?? null) === 'freeform:unsubscribe'
            ?: json_encode([$result, $latest?->evidence]);
    });

    check('withdrawing what was never granted writes nothing', function() use ($service, $box, $run) {
        $email = "never-$run@" . DOMAIN;
        $result = $service->capture('freeform', 'unsubscribe', $email, [$box('marketing', true, 'withdraw')]);

        return $result['withdrawn'] === [] && !ConsentRecord::find()->where(['email' => $email])->exists();
    });

    check('a purpose the site does not configure is ignored, and so is a bad address', function() use ($service, $box, $run) {
        $a = $service->capture('formie', 'contact', "odd-$run@" . DOMAIN, [$box('telepathy')]);
        $b = $service->capture('formie', 'contact', 'not an address', [$box('marketing')]);

        return $a['pending'] === [] && $b['pending'] === []
            && !PendingConsentRecord::find()->where(['email' => "odd-$run@" . DOMAIN])->exists();
    });

    check('several ticked purposes on one form share one email and one link', function() use ($service, $box, $run, $codeIn) {
        $email = "multi-$run@" . DOMAIN;
        $result = $service->capture('formie', 'contact', $email, [$box('marketing'), $box('analytics')]);
        $pending = $service->getPendingByCode((string)$codeIn($email));

        return $result['pending'] === ['marketing', 'analytics']
            && $pending !== null
            && $service->purposeLabels($pending) === ['Marketing email', 'Analytics']
            && $service->confirm($pending) === ['marketing', 'analytics'];
    });

    check('the intake rate limit holds per address: the third attempt sends nothing', function() use ($service, $box, $run, &$sent) {
        configure(['intakeRateLimit' => 2]);
        $email = "flood-$run@" . DOMAIN;
        $before = count($sent);
        $results = [];

        for ($i = 0; $i < 3; $i++) {
            $results[] = $service->capture('formie', 'contact', $email, [$box('marketing')]);
        }

        configure([]);

        return $results[2]['limited'] === true && $results[2]['code'] === null
            && count($sent) === $before + 2
            && (int)PendingConsentRecord::find()->where(['email' => $email])->count() === 2;
    });

    check('an expired link stops working, and garbage collection deletes it', function() use ($service, $box, $run, $codeIn, $db, $plugin) {
        $email = "late-$run@" . DOMAIN;
        $service->capture('formie', 'contact', $email, [$box('marketing')]);
        $code = (string)$codeIn($email);
        $db->createCommand()->update(PendingConsentRecord::tableName(), ['expiresAt' => Db::prepareDateForDb(new DateTime('-1 minute'))], ['email' => $email])->execute();

        $found = $service->getPendingByCode($code);
        $gc = $plugin->collectGarbage();

        return $found === null
            && ($gc['pendingConsents'] ?? 0) >= 1
            && !PendingConsentRecord::find()->where(['email' => $email])->exists()
            ?: json_encode($gc);
    });

    check('the email says what was asked for and that doing nothing is fine', function() use ($run, &$sent) {
        $email = "multi-$run@" . DOMAIN;

        foreach ($sent as $message) {
            if (in_array($email, $message['to'], true)) {
                $body = $message['body'];

                return str_contains($body, 'Marketing email') && str_contains($body, 'Analytics')
                    && str_contains($body, 'do nothing') ?: 'body did not carry the purposes';
            }
        }

        return 'no message';
    });

    check('a pending confirmation is disclosed, and an erasure deletes it outright', function() use ($service, $box, $run) {
        $email = "erase-$run@" . DOMAIN;
        $service->capture('formie', 'contact', $email, [$box('marketing')]);
        $subject = new Subject(email: $email);
        $collector = Plugin::getInstance()->collectors->get('consent');
        $bundle = $collector->collect($subject);
        $pending = array_values(array_filter($bundle->records, static fn($r) => str_starts_with($r->key, 'consent:pending:')));

        if (count($pending) !== 1 || ($pending[0]->data['Email'] ?? null) !== $email) {
            return 'not disclosed: ' . json_encode(array_map(static fn($r) => $r->key, $bundle->records));
        }

        $target = $collector->planFor($pending[0], Settings::MODE_ANONYMISE, $subject);
        $collector->apply($target, $subject);

        return $target->action === 'erase' && !PendingConsentRecord::find()->where(['email' => $email])->exists();
    });

    check('a console submission (an import, a replay) is not somebody ticking a box', function() use ($service) {
        $method = new ReflectionMethod($service, 'listening');
        $method->setAccessible(true);

        return $method->invoke($service) === false;
    });

    check('both form plugins are listened to by class name', function() {
        return yii\base\Event::hasHandlers('verbb\formie\services\Submissions', 'afterSubmission')
            && yii\base\Event::hasHandlers('Solspace\Freeform\Form\Form', 'after-submit');
    });

    // -----------------------------------------------------------------------------------------
    section('Freeform');

    if (!Craft::$app->getPlugins()->isPluginEnabled('freeform')) {
        $skipped[] = 'Freeform (not installed)';
        echo "  - skipped: Freeform is not installed\n";
    } else {
        $freeformHandle = "lockConsent$run";
        $freeform = null;

        check('a real Freeform form with an email and a checkbox field is built', function() use ($freeformHandle, &$freeform, &$cleanup) {
            Craft::$app->getUser()->setIdentity(craft\elements\User::find()->admin()->status(null)->one());
            $generator = \Solspace\Freeform\Freeform::getInstance()->formGeneration;
            $build = new ReflectionMethod($generator, 'buildPersistPayload');
            $build->setAccessible(true);
            $payload = $build->invoke($generator, 'Lock consent test', $freeformHandle, [
                ['type' => 'Email', 'label' => 'Email', 'handle' => 'email'],
                ['type' => 'Checkbox', 'label' => 'Send me the newsletter', 'handle' => 'newsletter'],
            ]);

            $event = new \Solspace\Freeform\Events\Forms\PersistFormEvent($payload);
            yii\base\Event::trigger(\Solspace\Freeform\controllers\api\FormsController::class, \Solspace\Freeform\controllers\api\FormsController::EVENT_CREATE_FORM, $event);
            yii\base\Event::trigger(\Solspace\Freeform\controllers\api\FormsController::class, \Solspace\Freeform\controllers\api\FormsController::EVENT_UPSERT_FORM, $event);
            Craft::$app->getUser()->setIdentity(null);

            $form = $event->getForm();
            $cleanup['freeform'] = $form?->getId();
            $freeform = $form;

            return $form !== null && $form->get('newsletter') !== null && $form->get('email') !== null
                ?: json_encode($event->getResponseData());
        });

        $load = static function() use ($freeformHandle) {
            return \Solspace\Freeform\Freeform::getInstance()->forms->getFormByHandle($freeformHandle);
        };

        if ($cleanup['freeform'] !== null) {
            configure(['formConsents' => [$mapping('freeform', $freeformHandle)]]);

            check('ticked: the adapter finds the email field itself and the grant waits', function() use ($service, $load, $run, $codeIn) {
                $email = "freeform-$run@" . DOMAIN;
                $form = $load();
                $form->get('email')->setValue($email);
                $form->get('newsletter')->setValue('yes');
                $result = $service->captureFromFreeform($form);
                $row = (new Query())->from(PendingConsentRecord::tableName())->where(['email' => $email])->one();
                $grants = json_decode((string)$row['grants'], true);

                return $result['pending'] === ['marketing']
                    && $row['source'] === 'freeform'
                    && ($grants[0]['text'] ?? null) === 'Send me the newsletter'
                    && $codeIn($email) === $result['code']
                    ?: json_encode([$result, $row]);
            });

            check('unticked: nothing', function() use ($service, $load, $run) {
                $email = "freeform-off-$run@" . DOMAIN;
                $form = $load();
                $form->get('email')->setValue($email);
                $form->get('newsletter')->setValue('');

                return $service->captureFromFreeform($form)['pending'] === []
                    && !PendingConsentRecord::find()->where(['email' => $email])->exists();
            });

            check('a submission Freeform marked as spam is ignored', function() use ($service, $load, $run) {
                $email = "freeform-spam-$run@" . DOMAIN;
                $form = $load();
                $form->get('email')->setValue($email);
                $form->get('newsletter')->setValue('yes');
                $form->markAsSpam(\Solspace\Freeform\Library\DataObjects\SpamReason::TYPE_HONEYPOT, 'test');

                $ignored = $form->isMarkedAsSpam() && $service->captureFromFreeform($form)['pending'] === [];
                // Freeform hands back the same form object on the next load.
                $form->removeMarkedAsSpam();

                return $ignored;
            });

            check('the wording in the mapping wins over the field label', function() use ($service, $load, $run, $mapping, $freeformHandle) {
                configure(['formConsents' => [$mapping('freeform', $freeformHandle, ['wording' => 'Yes, the newsletter please.'])]]);
                $email = "freeform-words-$run@" . DOMAIN;
                $form = $load();
                $form->get('email')->setValue($email);
                $form->get('newsletter')->setValue('yes');
                $service->captureFromFreeform($form);
                $grants = json_decode((string)(new Query())->select('grants')->from(PendingConsentRecord::tableName())->where(['email' => $email])->scalar(), true);
                configure([]);

                return ($grants[0]['text'] ?? null) === 'Yes, the newsletter please.' ?: json_encode($grants);
            });

            check('Lock\'s after-submit listener ignores a submission made from the console', function() use ($service, $load, $run, $mapping, $freeformHandle) {
                configure(['formConsents' => [$mapping('freeform', $freeformHandle)]]);
                $email = "freeform-event-$run@" . DOMAIN;
                $form = $load();
                $form->get('email')->setValue($email);
                $form->get('newsletter')->setValue('yes');
                $handler = new ReflectionMethod($service, 'onFreeformSubmission');
                $handler->setAccessible(true);
                $handler->invoke($service, new \Solspace\Freeform\Events\Forms\SubmitEvent($form));
                configure([]);

                return !PendingConsentRecord::find()->where(['email' => $email])->exists();
            });
        }

        configure([]);
    }

    // -----------------------------------------------------------------------------------------
    section('Formie, over HTTP');

    if (!Craft::$app->getPlugins()->isPluginEnabled('formie')) {
        $skipped[] = 'Formie (not installed)';
        echo "  - skipped: Formie is not installed\n";
    } else {
        $formieHandle = "lockConsent$run";

        check('a real Formie form with an email and an agree field is built', function() use ($formieHandle, &$cleanup) {
            $form = new \verbb\formie\elements\Form();
            $form->title = 'Lock consent test';
            $form->handle = $formieHandle;
            $form->getFormLayout()->setPages([[
                'label' => 'Page 1',
                'settings' => [],
                'rows' => [
                    ['fields' => [['type' => \verbb\formie\fields\Email::class, 'label' => 'Email', 'handle' => 'emailAddress']]],
                    ['fields' => [['type' => \verbb\formie\fields\Agree::class, 'label' => 'Newsletter', 'handle' => 'newsletter', 'checkedValue' => 'Yes', 'uncheckedValue' => 'No']]],
                ],
            ]]);
            $ok = Craft::$app->getElements()->saveElement($form);
            $cleanup['formie'] = $form->id;

            return $ok ?: json_encode($form->getErrors());
        });

        // The web process reads Lock's settings from project config, so the mapping goes there.
        check('the mapping is saved to project config for the web process', function() use ($formieHandle, $mapping, &$cleanup) {
            $pc = Craft::$app->getProjectConfig();
            $pc->set('plugins.lock.settings.formConsents', [$mapping('formie', $formieHandle, ['wording' => 'Email me the newsletter.'])]);
            $pc->saveModifiedConfigData();
            $pc->writeYamlFiles(true);
            $cleanup['projectConfig'] = true;

            return true;
        });

        // The IP budgets are per hour, and every run of this script posts from the same address.
        foreach (['formconsent', 'formconsent-withdraw', 'confirm'] as $bucket) {
            foreach (['127.0.0.1', '::1', '172.17.0.1'] as $ip) {
                Craft::$app->getCache()->delete("lock.rate.$bucket.ip." . hash('sha256', $ip));
            }
        }

        $jar = new CookieJar();
        $http = new Client(['base_uri' => 'http://localhost', 'headers' => ['Host' => 'plugin-testing.ddev.site'], 'cookies' => $jar, 'http_errors' => false, 'timeout' => 20, 'allow_redirects' => false]);
        $csrf = static function() use ($http): string {
            $info = json_decode((string)$http->get('/index.php?action=users/session-info', ['headers' => ['Accept' => 'application/json']])->getBody(), true);

            return (string)($info['csrfTokenValue'] ?? '');
        };
        $submit = static function(array $fields) use ($http, $csrf, $formieHandle): array {
            $response = $http->post('/', [
                'headers' => ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest', 'Referer' => 'http://plugin-testing.ddev.site/newsletter'],
                'form_params' => ['action' => 'formie/submissions/submit', 'handle' => $formieHandle, 'CRAFT_CSRF_TOKEN' => $csrf(), 'fields' => $fields],
            ]);

            return ['status' => $response->getStatusCode(), 'json' => json_decode((string)$response->getBody(), true) ?? [], 'raw' => substr((string)$response->getBody(), 0, 300)];
        };
        $mailpitCode = static function(string $to) use (&$cleanup): ?string {
            $client = new Client(['base_uri' => 'http://localhost:8025', 'timeout' => 5, 'http_errors' => false]);

            for ($i = 0; $i < 10; $i++) {
                $search = json_decode((string)$client->get('/api/v1/search', ['query' => ['query' => "to:\"$to\""]])->getBody(), true);

                foreach ($search['messages'] ?? [] as $message) {
                    $cleanup['mailpit'][] = $message['ID'];
                    $full = json_decode((string)$client->get('/api/v1/message/' . $message['ID'])->getBody(), true);

                    if (preg_match('#lock/confirm/([A-Za-z0-9_-]{43})#', (string)($full['Text'] ?? ''), $m)) {
                        return $m[1];
                    }
                }

                usleep(300000);
            }

            return null;
        };

        $httpEmail = "formie-$run@" . DOMAIN;
        $ticked = [];
        $httpCode = null;

        check('a ticked front-end submission succeeds as usual and the grant waits', function() use ($submit, $httpEmail, &$ticked) {
            $ticked = $submit(['emailAddress' => $httpEmail, 'newsletter' => '1']);
            $row = (new Query())->from(PendingConsentRecord::tableName())->where(['email' => $httpEmail])->one();
            $evidence = json_decode((string)($row['evidence'] ?? ''), true);

            return ($ticked['json']['success'] ?? false) === true
                && $row !== null && $row['source'] === 'formie'
                && ($evidence['url'] ?? null) === 'http://plugin-testing.ddev.site/newsletter'
                && ($evidence['ip'] ?? '') !== ''
                && ($evidence['submission'] ?? null) === ($ticked['json']['submissionId'] ?? 'x')
                && !ConsentRecord::find()->where(['email' => $httpEmail])->exists()
                ?: json_encode([$ticked, $row]);
        });

        check('the confirmation email arrived, with a link', function() use ($mailpitCode, $httpEmail, &$httpCode) {
            $httpCode = $mailpitCode($httpEmail);

            return $httpCode !== null ?: 'no confirmation email in Mailpit';
        });

        check('an unticked submission gets the same answer and starts nothing', function() use ($submit, $run, &$ticked) {
            $email = "formie-off-$run@" . DOMAIN;
            $plain = $submit(['emailAddress' => $email]);

            return ($plain['json']['success'] ?? false) === true
                && array_keys($plain['json']) === array_keys($ticked['json'])
                && !PendingConsentRecord::find()->where(['email' => $email])->exists()
                ?: json_encode([$plain, $ticked]);
        });

        check('opening the link changes nothing: a page with a button', function() use ($http, $httpEmail, &$httpCode) {
            $response = $http->get('/lock/confirm/' . $httpCode);
            $body = (string)$response->getBody();

            return $response->getStatusCode() === 200
                && str_contains($body, 'Marketing email') && str_contains($body, 'lock/portal/confirm')
                && str_contains($response->getHeaderLine('Cache-Control'), 'no-store')
                && str_starts_with($response->getHeaderLine('Referrer-Policy'), 'no-referrer')
                && str_contains($body, '<meta name="referrer" content="no-referrer">')
                && PendingConsentRecord::find()->where(['email' => $httpEmail])->exists()
                && !ConsentRecord::find()->where(['email' => $httpEmail])->exists()
                ?: json_encode([$response->getStatusCode(), $response->getHeaderLine('Cache-Control'), $response->getHeaderLine('Referrer-Policy')]);
        });

        check('the button without a CSRF token is refused', function() use ($http, $httpEmail, &$httpCode) {
            $response = $http->post('/', ['form_params' => ['action' => 'lock/portal/confirm', 'code' => $httpCode]]);

            return $response->getStatusCode() === 400
                && !ConsentRecord::find()->where(['email' => $httpEmail])->exists()
                ?: 'status ' . $response->getStatusCode();
        });

        check('the button confirms: a verified grant, with the wording from the mapping', function() use ($http, $csrf, $httpEmail, &$httpCode) {
            $response = $http->post('/', ['form_params' => ['action' => 'lock/portal/confirm', 'code' => $httpCode, 'CRAFT_CSRF_TOKEN' => $csrf()]]);
            $latest = Plugin::getInstance()->consent->latest($httpEmail, 'marketing');

            return $response->getStatusCode() === 200
                && str_contains((string)$response->getBody(), 'Confirmed')
                && $latest?->state === ConsentEntry::STATE_GRANTED
                && ($latest->evidence['verified'] ?? null) === true
                && ($latest->evidence['text'] ?? null) === 'Email me the newsletter.'
                && !PendingConsentRecord::find()->where(['email' => $httpEmail])->exists()
                ?: 'status ' . $response->getStatusCode() . ' ' . json_encode($latest?->evidence);
        });

        check('pressing it again says the link has expired and records nothing more', function() use ($http, $csrf, $httpEmail, &$httpCode) {
            $response = $http->post('/', ['form_params' => ['action' => 'lock/portal/confirm', 'code' => $httpCode, 'CRAFT_CSRF_TOKEN' => $csrf()]]);

            return str_contains((string)$response->getBody(), 'expired')
                && count(Plugin::getInstance()->consent->history($httpEmail)) === 1;
        });

        check('a made-up or malformed code gets the same expired page', function() use ($http) {
            $a = $http->get('/lock/confirm/' . str_repeat('A', 43));
            $b = $http->get('/lock/confirm/%27%20OR%201%3D1');

            return $a->getStatusCode() === 200 && $b->getStatusCode() === 200
                && str_contains((string)$a->getBody(), 'expired') && str_contains((string)$b->getBody(), 'expired');
        });

        check('signed in, for your own address: recorded at once, verified, against the account', function() use ($http, $csrf, $submit, &$cleanup) {
            $admin = craft\elements\User::find()->admin()->status(null)->orderBy(['id' => SORT_ASC])->one();
            $email = mb_strtolower((string)$admin->email);
            $cleanup['adminConsentFrom'] = [$email, (int)(new Query())->from(ConsentRecord::tableName())->max('id')];

            $login = $http->post('/index.php', [
                'headers' => ['Accept' => 'application/json'],
                'form_params' => ['action' => 'users/login', 'loginName' => 'admin', 'password' => 'claudepassword', 'CRAFT_CSRF_TOKEN' => $csrf()],
            ]);

            if ($login->getStatusCode() !== 200) {
                return 'could not sign in: ' . $login->getStatusCode();
            }

            if (Plugin::getInstance()->consent->allows($email, 'marketing')) {
                return 'the admin address already allows marketing on this site, so nothing could change';
            }

            $result = $submit(['emailAddress' => $email, 'newsletter' => '1']);
            $latest = Plugin::getInstance()->consent->latest($email, 'marketing');

            return ($result['json']['success'] ?? false) === true
                && $latest?->state === ConsentEntry::STATE_GRANTED
                && $latest->userId === (int)$admin->id
                && ($latest->evidence['verified'] ?? null) !== false
                && !PendingConsentRecord::find()->where(['email' => $email])->exists()
                ?: json_encode([$result, $latest?->evidence]);
        });
    }
} finally {
    section('Teardown');
    $teardown();

    foreach (glob("$mailDir/*") ?: [] as $file) {
        @unlink($file);
    }

    @rmdir($mailDir);

    Plugin::getInstance()->setSettings($originalSettings);
    Plugin::getInstance()->edition = $originalEdition;

    check('every fixture is gone', function() use ($db) {
        return !PendingConsentRecord::find()->where(['like', 'email', '%@' . DOMAIN, false])->exists()
            && !ConsentRecord::find()->where(['like', 'email', '%@' . DOMAIN, false])->exists();
    });
}

echo "\n" . str_repeat('-', 60) . "\n";
echo "$passed passed, $failed failed" . ($skipped ? ' — skipped: ' . implode(', ', $skipped) : '') . "\n";

exit($failed === 0 ? 0 : 1);
