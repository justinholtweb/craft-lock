<?php

namespace justinholtweb\lock\controllers;

use Craft;
use craft\helpers\StringHelper;
use craft\web\Controller;
use craft\web\View;
use justinholtweb\lock\helpers\RateLimit;
use justinholtweb\lock\models\ConsentEntry;
use justinholtweb\lock\models\Request as SubjectRequest;
use justinholtweb\lock\models\Settings;
use justinholtweb\lock\Plugin;
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
 * - **Rate limited by address, by IP and in total**, because an intake form that emails anybody
 *   you name is otherwise a mail cannon.
 * - **Nothing changes state on a GET.** Mail scanners and link previewers follow every link in an
 *   email, so the verification link opens a page with a button rather than confirming anything.
 */
class PortalController extends Controller
{
    protected array|bool|int $allowAnonymous = [
        'submit' => self::ALLOW_ANONYMOUS_LIVE,
        'verify' => self::ALLOW_ANONYMOUS_LIVE,
        'status' => self::ALLOW_ANONYMOUS_LIVE,
        'consent' => self::ALLOW_ANONYMOUS_LIVE,
        'confirm' => self::ALLOW_ANONYMOUS_LIVE,
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

        $limited = !$this->withinRateLimit('intake', [
            'email' => self::rateKeyForEmail($email),
            'ip' => (string)$this->request->getUserIP(),
        ], $settings->intakeRateLimit, $settings->intakeGlobalLimit);

        if ($limited) {
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

        return $this->pretendSuccess();
    }

    /**
     * One answer for every outcome.
     *
     * Success, rate limit, honeypot and unknown address all land here with the same words and the
     * same shape. That includes the reference: a reference only in the genuine case is a field
     * whose presence says "this one landed". The subject gets theirs by email.
     */
    private function pretendSuccess(): Response
    {
        $message = Craft::t('lock', 'Thank you. If we hold anything under that address, we have sent an email to it with the next step.');

        if ($this->request->getAcceptsJson()) {
            return $this->asJson(['success' => true, 'message' => $message]);
        }

        Craft::$app->getSession()->setNotice($message);

        return $this->redirectToPostedUrl();
    }

    /** @param array<string, string> $keys */
    private function withinRateLimit(string $bucket, array $keys, int $perKey, int $global = 0): bool
    {
        return RateLimit::within($bucket, $keys, $perKey, $global);
    }

    /** Kept for anything that called it here before the rate limit moved to {@see RateLimit}. */
    public static function rateKeyForEmail(string $email): string
    {
        return RateLimit::keyForEmail($email);
    }

    /**
     * Renders a portal page, letting the site override it.
     *
     * A site template at `lock/<page>.twig` — `lock/verify.twig`, `lock/verified.twig`,
     * `lock/status.twig`, `lock/confirm.twig`, `lock/confirmed.twig` — wins over the plugin's own. The built-in pages are deliberately plain
     * and unbranded — they work on a fresh install, which matters because the link in a
     * verification email must never 404 — but the page a data subject lands on is a page on
     * somebody's website, and they should be able to make it look like one.
     */
    private function renderPortal(string $template, array $variables): Response
    {
        $view = Craft::$app->getView();

        if ($view->doesTemplateExist("lock/$template", View::TEMPLATE_MODE_SITE)) {
            return $this->renderTemplate("lock/$template", $variables, View::TEMPLATE_MODE_SITE);
        }

        return $this->renderTemplate("lock/_portal/$template", $variables, View::TEMPLATE_MODE_CP);
    }

    /**
     * Confirms identity from the emailed link — in two steps.
     *
     * A GET only shows a page with a button. Mail security gateways, link previewers and
     * "safe links" rewriters fetch every URL in an inbound message, and a confirmation that
     * fires on GET would be confirmed by the subject's mail filter before the subject ever saw
     * it — which is not the subject confirming anything. The POST is what verifies.
     */
    public function actionVerify(?string $code = null): Response
    {
        // The code arrives as an action argument from the `lock/verify/<code>` route — Craft does
        // not copy route params into the query string, so `getParam('code')` never sees it — or
        // as a body param from the confirm form, which posts to the action directly.
        $code = (string)($this->request->getBodyParam('code') ?? $code ?? $this->request->getQueryParam('code', ''));
        $request = Plugin::getInstance()->requests->getByToken($code);

        if ($request === null) {
            return $this->renderPortal('verified', [
                'ok' => false,
                'message' => Craft::t('lock', 'That link has expired or has already been used. Send the request again and we will email a fresh one.'),
            ]);
        }

        if (!$this->request->getIsPost()) {
            return $this->renderPortal('verify', [
                'code' => $code,
                'request' => $request,
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

    /**
     * Lets a subject look up their own request with the reference and the address.
     *
     * Rate limited by IP and by reference. References are sequential by design — they are meant
     * to be quoted — so without a limit this is a way to test addresses against every request of
     * the year. A limited lookup gets the same "could not match" as a wrong one.
     */
    public function actionStatus(): Response
    {
        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();

        $reference = StringHelper::safeTruncate(trim((string)$this->request->getParam('reference', '')), 32);
        $email = mb_strtolower(trim((string)$this->request->getParam('email', '')));
        $searched = $reference !== '' && $email !== '';
        $found = null;

        if ($searched) {
            $limit = $settings->intakeRateLimit > 0 ? $settings->intakeRateLimit * 4 : 0;
            $within = $this->withinRateLimit('status', [
                'ip' => (string)$this->request->getUserIP(),
                'reference' => mb_strtoupper($reference),
            ], $limit);

            $candidate = $within ? Plugin::getInstance()->requests->getByReference($reference) : null;

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
            'searched' => $searched,
        ]);
    }

    /**
     * Records a consent decision from a front-end form.
     *
     * **Signed in:** the decision is recorded against the account's own address, whatever was
     * posted, and grants and withdrawals both count. That is the only case where Lock knows whose
     * decision it is.
     *
     * **Signed out:** withdrawal only, and the answer never says whether anything was recorded.
     * A grant from an anonymous form is a forged consent waiting to happen — anybody could sign
     * anybody up to marketing — so it is ignored. A withdrawal is honoured, because Article 7(3)
     * requires withdrawing to be as easy as giving and an unsubscribe form cannot demand a login;
     * the worst a forged withdrawal does is stop the site emailing somebody. It is marked as
     * unverified in the evidence so the ledger never claims more than it knows.
     *
     * Either way the current state is never echoed back to an anonymous caller: "here is what
     * this address has agreed to" for any address you type is a lookup service.
     */
    public function actionConsent(): ?Response
    {
        $this->requirePostRequest();

        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();
        $identity = Craft::$app->getUser()->getIdentity();
        $signedIn = $identity !== null;

        $email = $signedIn
            ? mb_strtolower(trim((string)$identity->email))
            : mb_strtolower(trim((string)$this->request->getBodyParam('email', '')));

        $limit = $settings->intakeRateLimit > 0 ? $settings->intakeRateLimit * 4 : 0;
        $within = $this->withinRateLimit('consent', [
            'email' => self::rateKeyForEmail($email),
            'ip' => (string)$this->request->getUserIP(),
        ], $limit);

        if ($email === '' && $signedIn) {
            throw new ForbiddenHttpException(Craft::t('lock', 'An email address is needed to record a preference against.'));
        }

        $allowed = array_keys($settings->purposeOptions());
        $granted = (array)$this->request->getBodyParam('purposes', []);
        $consent = Plugin::getInstance()->consent;
        $valid = filter_var($email, FILTER_VALIDATE_EMAIL) !== false;

        $evidence = [
            'ip' => $this->request->getUserIP(),
            'url' => StringHelper::safeTruncate((string)$this->request->getReferrer(), 500) ?: null,
            'text' => StringHelper::safeTruncate((string)$this->request->getBodyParam('wording', ''), 2000) ?: null,
            'userAgent' => StringHelper::safeTruncate((string)$this->request->getUserAgent(), 500),
        ];

        if (!$signedIn) {
            $evidence['verified'] = false;
        }

        $policyVersion = StringHelper::safeTruncate(trim((string)$this->request->getBodyParam('policyVersion', '')), 64) ?: null;

        if ($within && $valid) {
            foreach ($allowed as $purpose) {
                $wants = in_array($purpose, $granted, true) || (string)($granted[$purpose] ?? '') === '1';

                // Signed out, only a withdrawal is acted on. See the method docblock.
                if (!$signedIn && $wants) {
                    continue;
                }

                $current = $consent->allows($email, $purpose);

                // Only a *change* is written. Re-recording the same answer on every page view
                // would make the ledger unreadable and hide the decisions that matter in the noise.
                if ($wants === $current) {
                    continue;
                }

                $consent->record(
                    $email,
                    $purpose,
                    $wants ? ConsentEntry::STATE_GRANTED : ConsentEntry::STATE_WITHDRAWN,
                    ConsentEntry::SOURCE_FORM,
                    $evidence,
                    userId: $signedIn ? (int)$identity->id : null,
                    policyVersion: $policyVersion,
                );
            }
        }

        if (!$signedIn) {
            // One answer, whatever happened: recorded, nothing to change, rate limited, or an
            // address that was never on file.
            $message = Craft::t('lock', 'Thank you. If that address is on our list, your preferences have been updated.');

            if ($this->request->getAcceptsJson()) {
                return $this->asJson(['success' => true, 'message' => $message]);
            }

            Craft::$app->getSession()->setNotice($message);

            return $this->redirectToPostedUrl();
        }

        if (!$within) {
            return $this->asFailure(Craft::t('lock', 'Too many changes in a short time. Try again in a while.'));
        }

        if ($this->request->getAcceptsJson()) {
            return $this->asJson(['success' => true, 'consent' => $consent->state($email)]);
        }

        Craft::$app->getSession()->setNotice(Craft::t('lock', 'Your preferences have been saved.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * Confirms consent ticked on a Formie or Freeform form, from the emailed link — in two steps,
     * like {@see actionVerify()}: a GET only shows a button, because mail scanners open every link.
     *
     * Rate limited by IP. The code is 256 bits, so this is not about guessing it; it stops the
     * endpoint being a cheap way to make the database hash things. A limited caller is told the
     * link has expired, the same as a wrong one.
     */
    public function actionConfirm(?string $code = null): Response
    {
        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();
        $service = Plugin::getInstance()->formConsent;

        $code = (string)($this->request->getBodyParam('code') ?? $code ?? $this->request->getQueryParam('code', ''));
        $limit = $settings->intakeRateLimit > 0 ? $settings->intakeRateLimit * 4 : 0;
        $within = $this->withinRateLimit('confirm', ['ip' => (string)$this->request->getUserIP()], $limit);
        $pending = $within ? $service->getPendingByCode($code) : null;

        // Nothing about the page is worth caching or passing on: the URL carries the code.
        $this->response->getHeaders()
            ->set('Cache-Control', 'no-store, private')
            ->set('Referrer-Policy', 'no-referrer')
            ->set('X-Robots-Tag', 'noindex, nofollow');

        if ($pending === null) {
            return $this->renderPortal('confirmed', [
                'ok' => false,
                'message' => Craft::t('lock', 'That link has expired or has already been used. Fill in the form again and we will email a fresh one.'),
            ]);
        }

        if (!$this->request->getIsPost()) {
            return $this->renderPortal('confirm', [
                'code' => $code,
                'purposes' => $service->purposeLabels($pending),
            ]);
        }

        $recorded = $service->confirm($pending);

        if ($recorded === null) {
            // Spent by a second tab or a double click between the lookup and the button.
            return $this->renderPortal('confirmed', [
                'ok' => false,
                'message' => Craft::t('lock', 'That link has expired or has already been used. Fill in the form again and we will email a fresh one.'),
            ]);
        }

        return $this->renderPortal('confirmed', [
            'ok' => true,
            'purposes' => $service->purposeLabels($pending),
            'message' => Craft::t('lock', 'Thank you — that is confirmed. You can change your mind at any time.'),
        ]);
    }
}
