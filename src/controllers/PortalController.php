<?php

namespace justinholtweb\lock\controllers;

use Craft;
use craft\helpers\StringHelper;
use craft\web\Controller;
use justinholtweb\lock\models\ConsentEntry;
use justinholtweb\lock\models\Request as SubjectRequest;
use justinholtweb\lock\models\Settings;
use justinholtweb\lock\Plugin;
use justinholtweb\lock\records\ActivityRecord;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * The front door: where a member of the public asks, confirms, and checks.
 *
 * Everything here is unauthenticated by necessity — most data subjects have no account, which is
 * exactly why they need to ask rather than log in and look. That makes this the plugin's whole
 * attack surface, so:
 *
 * - **An unverified request does nothing.** Submitting the form creates a row and sends an email
 *   to the address that was typed. Nothing is disclosed, nothing is deleted, and the person whose
 *   address it is finds out either way.
 * - **The response never differs on whether the address is known.** A form that says "no account
 *   found" is an account enumeration oracle wearing a compliance hat.
 * - **Rate limited by address and by IP**, because an intake form that emails anybody you name is
 *   otherwise a mail cannon.
 */
class PortalController extends Controller
{
    protected array|bool|int $allowAnonymous = [
        'submit' => self::ALLOW_ANONYMOUS_LIVE,
        'verify' => self::ALLOW_ANONYMOUS_LIVE,
        'status' => self::ALLOW_ANONYMOUS_LIVE,
        'download' => self::ALLOW_ANONYMOUS_LIVE,
        'consent' => self::ALLOW_ANONYMOUS_LIVE,
    ];

    /** Takes a request from a front-end form. */
    public function actionSubmit(): ?Response
    {
        $this->requirePostRequest();

        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->intakeEnabled) {
            throw new ForbiddenHttpException(Craft::t('lock', 'Requests are not being taken through this site.'));
        }

        $request = Craft::$app->getRequest();

        // A honeypot, checked before anything is written. Named for something a browser will
        // autofill and a person will never see.
        if (trim((string)$request->getBodyParam('confirmEmail', '')) !== '') {
            return $this->pretendSuccess();
        }

        $email = mb_strtolower(trim((string)$request->getBodyParam('email', '')));
        $type = (string)$request->getBodyParam('type', SubjectRequest::TYPE_ACCESS);

        if (!in_array($type, $settings->intakeTypes, true)) {
            $type = SubjectRequest::TYPE_ACCESS;
        }

        if (!$this->withinRateLimit($email)) {
            // Deliberately the same answer a genuine submission gets. Telling a script that it has
            // been rate limited tells it that the previous attempts landed.
            return $this->pretendSuccess();
        }

        $subjectRequest = new SubjectRequest();
        $subjectRequest->email = $email;
        $subjectRequest->name = StringHelper::safeTruncate(trim((string)$request->getBodyParam('name', '')), 250) ?: null;
        $subjectRequest->type = $type;
        $subjectRequest->message = StringHelper::safeTruncate(trim((string)$request->getBodyParam('message', '')), 5000) ?: null;
        $subjectRequest->source = SubjectRequest::SOURCE_WEB;
        $subjectRequest->context = [
            'ip' => $request->getUserIP(),
            'userAgent' => StringHelper::safeTruncate((string)$request->getUserAgent(), 500),
            'site' => Craft::$app->getSites()->getCurrentSite()->handle,
            'referrer' => StringHelper::safeTruncate((string)$request->getReferrer(), 500),
        ];

        if (!$subjectRequest->validate()) {
            return $this->asModelFailure(
                $subjectRequest,
                Craft::t('lock', 'That does not look like an email address.'),
                'lockRequest',
            );
        }

        Plugin::getInstance()->requests->create($subjectRequest);

        return $this->pretendSuccess($subjectRequest->reference);
    }

    /**
     * One answer for every outcome.
     *
     * Success, rate limit, and unknown address all land here with the same words. The reference is
     * only included when there genuinely is one, and even then it tells an attacker nothing they
     * did not already supply.
     */
    private function pretendSuccess(?string $reference = null): ?Response
    {
        $message = Craft::t('lock', 'Thank you. If we hold anything under that address, we have sent an email to it with the next step.');

        if ($this->request->getAcceptsJson()) {
            return $this->asJson(['success' => true, 'message' => $message, 'reference' => $reference]);
        }

        Craft::$app->getSession()->setNotice($message);

        return $this->redirectToPostedUrl();
    }

    /**
     * How many requests one address, or one IP, may start in an hour.
     *
     * Both, not either: limiting only by address lets a script walk a list, and limiting only by
     * IP lets a distributed one through.
     */
    private function withinRateLimit(string $email): bool
    {
        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();

        if ($settings->intakeRateLimit <= 0) {
            return true;
        }

        $cache = Craft::$app->getCache();
        $keys = [
            'lock.intake.email.' . md5($email),
            'lock.intake.ip.' . md5((string)$this->request->getUserIP()),
        ];

        foreach ($keys as $key) {
            $count = (int)$cache->get($key);

            if ($count >= $settings->intakeRateLimit) {
                return false;
            }

            $cache->set($key, $count + 1, 3600);
        }

        return true;
    }

    /**
     * Renders a portal page, letting the site override it.
     *
     * A site template at `lock/verify.twig` wins over the plugin's own. The built-in pages are
     * deliberately plain and unbranded — they work on a fresh install, which matters because the
     * link in a verification email must never 404 — but the page a data subject lands on is a page
     * on somebody's website, and they should be able to make it look like one.
     */
    private function renderPortal(string $template, array $variables): Response
    {
        $view = Craft::$app->getView();

        if ($view->doesTemplateExist("lock/$template", \craft\web\View::TEMPLATE_MODE_SITE)) {
            return $this->renderTemplate("lock/$template", $variables, \craft\web\View::TEMPLATE_MODE_SITE);
        }

        return $this->renderTemplate("lock/_portal/$template", $variables, \craft\web\View::TEMPLATE_MODE_CP);
    }

    /** Confirms identity by following the emailed link. */
    public function actionVerify(): Response
    {
        $request = Plugin::getInstance()->requests->getByToken((string)$this->request->getParam('code', ''));

        if ($request === null) {
            return $this->renderPortal('verified', [
                'ok' => false,
                'message' => Craft::t('lock', 'That link has expired or has already been used. Send the request again and we will email a fresh one.'),
            ]);
        }

        Plugin::getInstance()->requests->verify($request);

        return $this->renderPortal('verified', [
            'ok' => true,
            'request' => $request,
            'message' => Craft::t('lock', 'Thank you — that confirms it was you. We will answer by {date}.', [
                'date' => $request->dueAt?->format('j F Y') ?? '',
            ]),
        ]);
    }

    /** Lets a subject look up their own request with the reference and the address. */
    public function actionStatus(): Response
    {
        $reference = trim((string)$this->request->getParam('reference', ''));
        $email = mb_strtolower(trim((string)$this->request->getParam('email', '')));
        $found = null;

        if ($reference !== '' && $email !== '') {
            $candidate = Plugin::getInstance()->requests->getByReference($reference);

            // Both halves have to match. The reference alone is guessable — it is sequential by
            // design, because it is meant to be quoted in correspondence.
            if ($candidate !== null && hash_equals($candidate->email, $email)) {
                $found = $candidate;
            }
        }

        return $this->renderPortal('status', [
            'reference' => $reference,
            'email' => $email,
            'request' => $found,
            'searched' => $reference !== '' && $email !== '',
        ]);
    }

    /**
     * Records a consent decision from a front-end form or a banner.
     *
     * The ingest point a cookie panel posts to. It records against an *address*, which is the
     * thing a banner cannot do on its own — which is why a banner and this are complementary
     * rather than competing.
     */
    public function actionConsent(): ?Response
    {
        $this->requirePostRequest();

        $email = mb_strtolower(trim((string)$this->request->getBodyParam('email', '')));
        $identity = Craft::$app->getUser()->getIdentity();

        if ($email === '' && $identity !== null) {
            $email = mb_strtolower((string)$identity->email);
        }

        // A signed-in visitor may only record consent for themselves. Without this, the endpoint
        // is a way to record that somebody else agreed to marketing.
        if ($identity !== null && $email !== mb_strtolower((string)$identity->email)) {
            throw new ForbiddenHttpException(Craft::t('lock', 'You can only change your own preferences.'));
        }

        if ($email === '') {
            throw new ForbiddenHttpException(Craft::t('lock', 'An email address is needed to record a preference against.'));
        }

        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();
        $allowed = array_keys($settings->purposeOptions());
        $granted = (array)$this->request->getBodyParam('purposes', []);
        $consent = Plugin::getInstance()->consent;

        $evidence = [
            'ip' => $this->request->getUserIP(),
            'url' => $this->request->getReferrer(),
            'text' => StringHelper::safeTruncate((string)$this->request->getBodyParam('wording', ''), 2000) ?: null,
            'userAgent' => StringHelper::safeTruncate((string)$this->request->getUserAgent(), 500),
        ];

        foreach ($allowed as $purpose) {
            $wants = in_array($purpose, $granted, true) || (string)($granted[$purpose] ?? '') === '1';
            $current = $consent->allows($email, $purpose);

            // Only a *change* is written. Re-recording the same answer on every page view would
            // make the ledger unreadable and hide the decisions that matter in the noise.
            if ($wants === $current) {
                continue;
            }

            $consent->record(
                $email,
                $purpose,
                $wants ? ConsentEntry::STATE_GRANTED : ConsentEntry::STATE_WITHDRAWN,
                ConsentEntry::SOURCE_FORM,
                $evidence,
                policyVersion: (string)$this->request->getBodyParam('policyVersion', '') ?: null,
            );
        }

        if ($this->request->getAcceptsJson()) {
            return $this->asJson(['success' => true, 'consent' => $consent->state($email)]);
        }

        Craft::$app->getSession()->setNotice(Craft::t('lock', 'Your preferences have been saved.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * Hands a built dossier to the subject.
     *
     * Token-gated and single-purpose. The file is served from storage, never from a public path —
     * a dossier under the web root is one guessed filename away from being the site's worst
     * possible data breach.
     */
    public function actionDownload(string $code): Response
    {
        $request = Plugin::getInstance()->requests->getByToken($code);

        if ($request === null || $request->dossierPath === null) {
            throw new ForbiddenHttpException(Craft::t('lock', 'That download link is no longer valid.'));
        }

        $path = Plugin::getInstance()->dossiers->pathFor($request->dossierPath);

        if (!is_file($path)) {
            throw new ForbiddenHttpException(Craft::t('lock', 'That file is no longer available. Ask us and we will prepare it again.'));
        }

        Plugin::getInstance()->activity->log(
            ActivityRecord::CATEGORY_ACCESS,
            'dossier.downloaded',
            Craft::t('lock', '{reference} was downloaded by the subject.', ['reference' => $request->reference]),
            $request->getSubject(),
            $request->id,
        );

        return $this->response->sendFile($path, basename($path), ['inline' => false]);
    }
}
