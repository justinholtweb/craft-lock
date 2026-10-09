<?php

namespace justinholtweb\lock\services;

use Craft;
use craft\base\Component;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use DateInterval;
use DateTime;
use justinholtweb\lock\helpers\RateLimit;
use justinholtweb\lock\models\ConsentEntry;
use justinholtweb\lock\models\Settings;
use justinholtweb\lock\models\Subject;
use justinholtweb\lock\Plugin;
use justinholtweb\lock\records\ActivityRecord;
use justinholtweb\lock\records\PendingConsentRecord;
use yii\base\Event;

/**
 * Consent captured from Formie and Freeform forms.
 *
 * Most consent on a Craft site is a ticked box on a Formie or Freeform form. Settings map a field
 * on a form to a purpose; when a submission arrives with that box ticked, Lock acts on it under
 * the same rules as its own consent endpoint:
 *
 * - **A grant from somebody signed in, for their own address,** is recorded at once and marked
 *   verified. Lock knows whose decision it is.
 * - **A grant from anybody else waits.** Anybody can type anybody's address into a form, so a
 *   ticked box is not yet consent from the person at that address. Lock stores it as pending and
 *   emails a link; only the person who can read that mailbox can turn it into a ledger grant. The
 *   link follows the request-verification pattern: a GET shows a button, the POST confirms, the
 *   code is stored hashed, is single use, and expires after `verificationTtl` hours.
 * - **A withdrawal applies immediately,** marked unverified when signed out, because Article 7(3)
 *   requires withdrawing to be as easy as giving. The worst a forged one does is stop the site
 *   emailing somebody.
 *
 * Only a change is written, as everywhere else in the ledger. An unticked box is no decision at
 * all — somebody sending a contact form without ticking the newsletter box has not withdrawn from
 * anything.
 *
 * Nothing here changes what the form plugin answers. The visitor sees the form's own success
 * message whatever Lock did, so the form cannot be used to learn what an address has agreed to,
 * and a failure in Lock is logged and never breaks the submission.
 *
 * Formie and Freeform are reached by string class names and checked with `isPluginEnabled()`
 * before anything of theirs is touched, so a site without them never loads a class that doesn't
 * exist.
 */
class FormConsent extends Component
{
    public const SOURCE_FORMIE = 'formie';
    public const SOURCE_FREEFORM = 'freeform';

    private const FORMIE_SUBMISSIONS = 'verbb\formie\services\Submissions';
    private const FORMIE_EMAIL_FIELD = 'verbb\formie\fields\Email';
    private const FREEFORM_FORM = 'Solspace\Freeform\Form\Form';

    /** 32 random bytes, base64url without padding. Checked before any query is made. */
    private const CODE_PATTERN = '/^[A-Za-z0-9_-]{43}$/';

    /**
     * Listens for both form plugins. Registered by class *name*: `Event::on()` with a string never
     * autoloads the class, so this is safe on a site that has neither installed.
     */
    public function attach(): void
    {
        Event::on(self::FORMIE_SUBMISSIONS, 'afterSubmission', function(Event $event) {
            $this->guard(fn() => $this->onFormieSubmission($event));
        });

        Event::on(self::FREEFORM_FORM, 'after-submit', function(Event $event) {
            $this->guard(fn() => $this->onFreeformSubmission($event));
        });
    }

    /** A form submission must never fail because of Lock. */
    private function guard(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Craft::error('Lock could not read consent from a form submission: ' . $e->getMessage(), Plugin::LOG_CATEGORY);
        }
    }

    /**
     * Only a visitor's own front-end submission counts. A submission an editor creates in the
     * control panel, or one a console import replays, is not anybody ticking a box.
     */
    private function listening(): bool
    {
        $request = Craft::$app->getRequest();

        return !$request->getIsConsoleRequest() && $request->getIsSiteRequest();
    }

    private function onFormieSubmission(Event $event): void
    {
        if (!$this->listening() || !Craft::$app->getPlugins()->isPluginEnabled('formie')) {
            return;
        }

        // Formie's SubmissionEvent, read without naming its class.
        $properties = get_object_vars($event);
        $submission = $properties['submission'] ?? null;

        if (($properties['success'] ?? false) !== true || !is_object($submission)) {
            return;
        }

        $this->captureFromFormie($submission);
    }

    private function onFreeformSubmission(Event $event): void
    {
        if (!$this->listening() || !Craft::$app->getPlugins()->isPluginEnabled('freeform')) {
            return;
        }

        if (!method_exists($event, 'getForm')) {
            return;
        }

        $this->captureFromFreeform($event->getForm());
    }

    /**
     * Reads a Formie submission against the mappings for its form.
     *
     * @param object $submission A `verbb\formie\elements\Submission`
     * @return array{granted: string[], pending: string[], withdrawn: string[], limited: bool, code: ?string}
     */
    public function captureFromFormie(object $submission): array
    {
        $none = self::emptyResult();

        if (($submission->isSpam ?? false) || ($submission->isIncomplete ?? false)) {
            return $none;
        }

        $form = $submission->getForm();
        $handle = (string)($form->handle ?? '');
        $mappings = $this->settings()->formConsentsFor(self::SOURCE_FORMIE, $handle);

        if ($mappings === []) {
            return $none;
        }

        $value = static function(string $field) use ($submission): mixed {
            try {
                return $submission->getFieldValue($field);
            } catch (\Throwable) {
                return null;
            }
        };

        $boxes = [];
        $emailField = '';

        foreach ($mappings as $mapping) {
            $emailField = $emailField ?: $mapping['emailField'];
            $field = $form->getFieldByHandle($mapping['field']);
            $wording = $mapping['wording'];

            if ($wording === '' && $field !== null) {
                $description = method_exists($field, 'getDescriptionHtml') ? trim(strip_tags((string)$field->getDescriptionHtml())) : '';
                $wording = $description !== '' ? $description : trim(strip_tags((string)($field->label ?? '')));
            }

            $boxes[] = $mapping + ['ticked' => self::isTicked($value($mapping['field'])), 'text' => $wording];
        }

        if ($emailField === '') {
            foreach ($form->getFields() as $field) {
                if (is_a($field::class, self::FORMIE_EMAIL_FIELD, true)) {
                    $emailField = (string)($field->handle ?? '');
                    break;
                }
            }
        }

        return $this->capture(
            self::SOURCE_FORMIE,
            $handle,
            self::firstString($emailField !== '' ? $value($emailField) : null),
            $boxes,
            ['submission' => $submission->id ?? null],
        );
    }

    /**
     * Reads a submitted Freeform form against the mappings for it.
     *
     * @param object $form A `Solspace\Freeform\Form\Form`
     * @return array{granted: string[], pending: string[], withdrawn: string[], limited: bool, code: ?string}
     */
    public function captureFromFreeform(object $form): array
    {
        $none = self::emptyResult();

        if ($form->isMarkedAsSpam()) {
            return $none;
        }

        $handle = (string)$form->getHandle();
        $mappings = $this->settings()->formConsentsFor(self::SOURCE_FREEFORM, $handle);

        if ($mappings === []) {
            return $none;
        }

        $boxes = [];
        $emailField = '';

        foreach ($mappings as $mapping) {
            $emailField = $emailField ?: $mapping['emailField'];
            $field = $form->get($mapping['field']);
            $wording = $mapping['wording'];

            if ($wording === '' && $field !== null) {
                $wording = trim(strip_tags((string)$field->getLabel()));
            }

            $boxes[] = $mapping + ['ticked' => $field !== null && self::isTicked($field->getValue()), 'text' => $wording];
        }

        if ($emailField === '') {
            foreach ($form->getLayout()->getFields() as $field) {
                if ($field->getType() === 'email') {
                    $emailField = (string)$field->getHandle();
                    break;
                }
            }
        }

        $email = $emailField !== '' ? $form->get($emailField)?->getValue() : null;

        return $this->capture(
            self::SOURCE_FREEFORM,
            $handle,
            self::firstString($email),
            $boxes,
            ['submission' => $form->getSubmission()?->id],
        );
    }

    /**
     * Acts on the ticked boxes of one submission. Both adapters end here, and so can a site's own
     * form handler.
     *
     * @param array<int, array{purpose?: string, action?: string, ticked?: bool, text?: string, field?: string}> $boxes
     * @param array<string, mixed> $evidence Extra circumstances to keep with the decision.
     * @return array{granted: string[], pending: string[], withdrawn: string[], limited: bool, code: ?string}
     */
    public function capture(string $source, string $form, string $email, array $boxes, array $evidence = []): array
    {
        $result = self::emptyResult();
        $email = mb_strtolower(trim($email));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $result;
        }

        $settings = $this->settings();
        $purposes = $settings->purposeOptions();
        $consent = Plugin::getInstance()->consent;

        $identity = Craft::$app->getRequest()->getIsConsoleRequest() ? null : Craft::$app->getUser()->getIdentity();
        $signedIn = $identity !== null && mb_strtolower(trim((string)$identity->email)) === $email;

        $evidence = $this->requestEvidence() + ['form' => "$source:$form"] + array_filter($evidence, static fn($v) => $v !== null);

        if (!$signedIn) {
            $evidence['verified'] = false;
        }

        $grants = [];
        $ip = (string)($evidence['ip'] ?? '');

        foreach ($boxes as $box) {
            $purpose = (string)($box['purpose'] ?? '');

            if (!($box['ticked'] ?? false) || !isset($purposes[$purpose])) {
                continue;
            }

            $boxEvidence = $evidence + ['text' => StringHelper::safeTruncate((string)($box['text'] ?? ''), 2000) ?: null, 'field' => $box['field'] ?? null];

            if (($box['action'] ?? 'grant') === 'withdraw') {
                $within = RateLimit::within('formconsent-withdraw', [
                    'email' => RateLimit::keyForEmail($email),
                    'ip' => $ip,
                ], $settings->intakeRateLimit > 0 ? $settings->intakeRateLimit * 4 : 0);

                if (!$within) {
                    $result['limited'] = true;
                    continue;
                }

                if ($consent->allows($email, $purpose)) {
                    $consent->record($email, $purpose, ConsentEntry::STATE_WITHDRAWN, ConsentEntry::SOURCE_FORM, $boxEvidence, userId: $signedIn ? (int)$identity->id : null);
                    $result['withdrawn'][] = $purpose;
                }

                continue;
            }

            // Only a change is written; a purpose already allowed needs no email either.
            if (!$consent->allows($email, $purpose)) {
                $grants[$purpose] = ['purpose' => $purpose, 'text' => $boxEvidence['text'], 'field' => $boxEvidence['field']];
            }
        }

        if ($grants === []) {
            return $result;
        }

        if ($signedIn) {
            foreach ($grants as $purpose => $grant) {
                $consent->record($email, $purpose, ConsentEntry::STATE_GRANTED, ConsentEntry::SOURCE_FORM, $evidence + ['verified' => true, 'text' => $grant['text'], 'field' => $grant['field']], userId: (int)$identity->id);
                $result['granted'][] = $purpose;
            }

            return $result;
        }

        // The same budget as the request intake: this sends an email to an address a stranger typed.
        $within = RateLimit::within('formconsent', [
            'email' => RateLimit::keyForEmail($email),
            'ip' => $ip,
        ], $settings->intakeRateLimit, $settings->intakeGlobalLimit);

        if (!$within) {
            $result['limited'] = true;

            return $result;
        }

        $result['code'] = $this->createPending($source, $form, $email, array_values($grants), $evidence);
        $result['pending'] = $result['code'] !== null ? array_keys($grants) : [];

        return $result;
    }

    /**
     * Stores the grants and emails the link. Returns the code — in the clear once, here, because
     * that is the only moment it exists in a form that can go in an email. The row keeps its hash.
     *
     * @param array<int, array{purpose: string, text: ?string, field: ?string}> $grants
     * @param array<string, mixed> $evidence
     */
    private function createPending(string $source, string $form, string $email, array $grants, array $evidence): ?string
    {
        $settings = $this->settings();
        $code = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $subject = new Subject(email: $email);
        $expiresAt = (new DateTime())->add(new DateInterval("PT{$settings->verificationTtl}H"));

        $record = new PendingConsentRecord();
        $record->email = $email;
        $record->emailHash = $subject->emailHash();
        $record->grants = $grants;
        $record->evidence = $evidence;
        $record->source = $source;
        $record->form = StringHelper::safeTruncate($form, 255) ?: null;
        $record->siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $record->tokenHash = hash('sha256', $code);
        $record->expiresAt = Db::prepareDateForDb($expiresAt);

        if (!$record->save(false)) {
            return null;
        }

        Plugin::getInstance()->activity->log(
            ActivityRecord::CATEGORY_CONSENT,
            'consent.pending',
            Craft::t('lock', 'Consent to {purposes} ticked on a {source} form; waiting for the emailed confirmation.', [
                'purposes' => implode(', ', array_column($grants, 'purpose')),
                'source' => $source === self::SOURCE_FORMIE ? 'Formie' : 'Freeform',
            ]),
            $subject,
            null,
            ['purposes' => array_column($grants, 'purpose'), 'form' => "$source:$form"],
        );

        Plugin::getInstance()->notifications->sendConsentConfirmation(
            $email,
            UrlHelper::siteUrl('lock/confirm/' . $code),
            $this->purposeLabels($record),
            $expiresAt,
        );

        return $code;
    }

    /**
     * Looks a pending confirmation up by the code in its link.
     *
     * The shape is checked before anything is queried, the comparison is an exact match on the
     * hash, and an expired row matches nothing.
     */
    public function getPendingByCode(string $code): ?PendingConsentRecord
    {
        if (!preg_match(self::CODE_PATTERN, $code)) {
            return null;
        }

        $record = PendingConsentRecord::find()
            ->where(['tokenHash' => hash('sha256', $code)])
            ->andWhere(['>', 'expiresAt', Db::prepareDateForDb(new DateTime())])
            ->one();

        return $record instanceof PendingConsentRecord ? $record : null;
    }

    /**
     * Turns a pending row into ledger grants, once.
     *
     * The row is deleted *first*, by a conditional delete whose affected-row count says whether
     * this call is the one that spent it — so two tabs, or a double click, record one grant
     * rather than two. Returns the purposes recorded, or null when the link was already spent.
     *
     * @return string[]|null
     */
    public function confirm(PendingConsentRecord $pending): ?array
    {
        $deleted = Craft::$app->getDb()->createCommand()->delete(PendingConsentRecord::tableName(), [
            'and',
            ['id' => $pending->id, 'tokenHash' => $pending->tokenHash],
            ['>', 'expiresAt', Db::prepareDateForDb(new DateTime())],
        ])->execute();

        if ($deleted !== 1) {
            return null;
        }

        $consent = Plugin::getInstance()->consent;
        $evidence = is_array($pending->evidence) ? $pending->evidence : [];
        $evidence['verified'] = true;
        $evidence['confirmedBy'] = 'email';
        $evidence['confirmedAt'] = (new DateTime())->format(DateTime::ATOM);
        $recorded = [];

        foreach (is_array($pending->grants) ? $pending->grants : [] as $grant) {
            $purpose = (string)($grant['purpose'] ?? '');

            if ($purpose === '' || $consent->allows($pending->email, $purpose)) {
                continue;
            }

            $entry = $consent->record(
                $pending->email,
                $purpose,
                ConsentEntry::STATE_GRANTED,
                ConsentEntry::SOURCE_FORM,
                $evidence + ['text' => $grant['text'] ?? null, 'field' => $grant['field'] ?? null],
                siteId: $pending->siteId,
            );

            if ($entry !== null) {
                $recorded[] = $purpose;
            }
        }

        Plugin::getInstance()->activity->log(
            ActivityRecord::CATEGORY_CONSENT,
            'consent.confirmed',
            Craft::t('lock', 'Consent from a {source} form confirmed by email.', [
                'source' => $pending->source === self::SOURCE_FORMIE ? 'Formie' : 'Freeform',
            ]),
            new Subject(email: $pending->email),
            null,
            ['purposes' => $recorded, 'form' => $pending->form],
        );

        return $recorded;
    }

    /**
     * The labels of what a pending row asks for, for the email and the confirmation page.
     *
     * @return string[]
     */
    public function purposeLabels(PendingConsentRecord $pending): array
    {
        $options = $this->settings()->purposeOptions();
        $labels = [];

        foreach (is_array($pending->grants) ? $pending->grants : [] as $grant) {
            $purpose = (string)($grant['purpose'] ?? '');
            $labels[] = $options[$purpose] ?? $purpose;
        }

        return $labels;
    }

    /** Deletes confirmations whose link has lapsed. Run from garbage collection. */
    public function expirePending(): int
    {
        return Craft::$app->getDb()->createCommand()
            ->delete(PendingConsentRecord::tableName(), ['<', 'expiresAt', Db::prepareDateForDb(new DateTime())])
            ->execute();
    }

    /** How many confirmations are still waiting, for the Consent screen. */
    public function countPending(): int
    {
        return (int)PendingConsentRecord::find()
            ->where(['>', 'expiresAt', Db::prepareDateForDb(new DateTime())])
            ->count();
    }

    /**
     * The circumstances of the submission, under the keys the consent collector clears when a
     * record is anonymised.
     *
     * @return array<string, mixed>
     */
    private function requestEvidence(): array
    {
        $request = Craft::$app->getRequest();

        if ($request->getIsConsoleRequest()) {
            return [];
        }

        return array_filter([
            'ip' => $request->getUserIP(),
            'url' => StringHelper::safeTruncate((string)($request->getReferrer() ?: $request->getAbsoluteUrl()), 500) ?: null,
            'userAgent' => StringHelper::safeTruncate((string)$request->getUserAgent(), 500) ?: null,
        ], static fn($v) => $v !== null && $v !== '');
    }

    /** Agree fields post booleans, checkboxes post their value or nothing. */
    private static function isTicked(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_array($value)) {
            return array_filter($value, static fn($v) => self::isTicked($v)) !== [];
        }

        if (!is_scalar($value)) {
            return false;
        }

        $value = mb_strtolower(trim((string)$value));

        return $value !== '' && !in_array($value, ['0', 'false', 'no', 'off'], true);
    }

    private static function firstString(mixed $value): string
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        return is_scalar($value) ? (string)$value : '';
    }

    /** @return array{granted: string[], pending: string[], withdrawn: string[], limited: bool, code: ?string} */
    private static function emptyResult(): array
    {
        return ['granted' => [], 'pending' => [], 'withdrawn' => [], 'limited' => false, 'code' => null];
    }

    private function settings(): Settings
    {
        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();

        return $settings;
    }
}
