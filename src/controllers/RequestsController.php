<?php

namespace justinholtweb\lock\controllers;

use Craft;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use DateTime;
use justinholtweb\lock\models\Request;
use justinholtweb\lock\models\Settings;
use justinholtweb\lock\Plugin;
use justinholtweb\lock\records\RequestEventRecord;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Working a request, from the desk side.
 *
 * The order of the screen is the order of the job: see who asked, assemble what is held, decide
 * what happens to it, tell them. Erasure is behind its own permission and its own preview, so
 * that "assemble a copy for somebody" and "delete somebody" are never one careless click apart.
 */
class RequestsController extends Controller
{
    public function actionIndex(): Response
    {
        $this->requirePermission(Plugin::PERMISSION_VIEW);

        $status = (string)$this->request->getParam('status', '');
        $search = (string)$this->request->getParam('search', '');

        $criteria = ['search' => $search];

        if ($status === 'open' || $status === '') {
            $criteria['open'] = true;
        } elseif ($status !== 'all') {
            $criteria['status'] = $status;
        }

        return $this->renderTemplate('lock/requests/index', [
            'requests' => Plugin::getInstance()->requests->find($criteria, 200),
            'status' => $status ?: 'open',
            'search' => $search,
            'statuses' => Request::statuses(),
        ]);
    }

    public function actionDetail(int $requestId): Response
    {
        $this->requirePermission(Plugin::PERMISSION_VIEW);

        $request = Plugin::getInstance()->requests->getById($requestId);

        if ($request === null) {
            throw new NotFoundHttpException(Craft::t('lock', 'That request does not exist.'));
        }

        return $this->renderTemplate('lock/requests/detail', [
            'request' => $request,
            'timeline' => Plugin::getInstance()->requests->timeline($requestId),
            'types' => Request::types(),
            'statuses' => Request::statuses(),
            'canErase' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_ERASE),
            'block' => Plugin::getInstance()->holds->blockFor($request->getSubject(), $requestId),
        ]);
    }

    /** Taking a request that arrived by post, by phone or by email. */
    public function actionEdit(): Response
    {
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        return $this->renderTemplate('lock/requests/edit', [
            'request' => new Request(['source' => Request::SOURCE_CP]),
            'types' => Request::types(),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        $id = $this->request->getBodyParam('requestId');
        $requests = Plugin::getInstance()->requests;
        $isNew = $id === null || $id === '';

        $request = $isNew ? new Request() : $requests->getById((int)$id);

        if ($request === null) {
            throw new NotFoundHttpException();
        }

        if ($isNew) {
            $request->email = (string)$this->request->getBodyParam('email', '');
            $request->name = (string)$this->request->getBodyParam('name') ?: null;
            $request->type = (string)$this->request->getBodyParam('type', Request::TYPE_ACCESS);
            $request->message = (string)$this->request->getBodyParam('message') ?: null;
            $request->source = Request::SOURCE_CP;

            // A request typed in by staff is one staff have already satisfied themselves about.
            // Emailing the subject a link to confirm they are who the operator just said they are
            // is noise, and it stops the clock on a request that has demonstrably been received —
            // so a control-panel intake skips verification and starts counting immediately.
            if (!$requests->create($request, (bool)$this->request->getBodyParam('notify', false), true)) {
                return $this->asModelFailure($request, Craft::t('lock', 'Could not save that request.'), 'request');
            }

            return $this->redirect(UrlHelper::cpUrl("lock/requests/$request->id"));
        }

        $request->note = (string)$this->request->getBodyParam('note', $request->note) ?: null;
        $request->assigneeId = ($assignee = $this->request->getBodyParam('assigneeId')) ? (int)(is_array($assignee) ? ($assignee[0] ?? 0) : $assignee) : null;

        if (!$requests->save($request)) {
            return $this->asModelFailure($request, Craft::t('lock', 'Could not save that request.'), 'request');
        }

        return $this->asSuccess(Craft::t('lock', 'Saved.'), ['request' => $request->id]);
    }

    /**
     * Assembles the dossier and writes the archive.
     *
     * The password is generated here and shown to the operator once. It is never stored — a
     * password kept next to the file it protects protects nothing — so an operator who loses it
     * rebuilds the dossier, which takes seconds.
     */
    public function actionAssemble(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        $request = $this->requestFromBody();
        $plugin = Plugin::getInstance();

        /** @var Settings $settings */
        $settings = $plugin->getSettings();

        $dossier = $plugin->dossiers->assemble($request->getSubject(), $request->id);
        $password = $settings->protectDossier ? $plugin->dossiers->generatePassword() : null;
        $filename = $plugin->dossiers->export($dossier, $password);

        $request->dossierPath = $filename;
        $request->dossierBuiltAt = new DateTime();

        if ($request->status === Request::STATUS_OPEN) {
            $request->status = Request::STATUS_ASSEMBLED;
        }

        $plugin->requests->save($request);
        $plugin->requests->addEvent(
            $request,
            RequestEventRecord::TYPE_ASSEMBLED,
            Craft::t('lock', '{n} records assembled from {sources} sources.', [
                'n' => $dossier->count(),
                'sources' => count($dossier->populatedBundles()),
            ]),
            ['partial' => $dossier->isPartial(), 'problems' => $dossier->problems()],
        );

        Craft::$app->getSession()->setNotice($password !== null
            ? Craft::t('lock', 'Assembled. The archive password is {password} — it is shown once and is not stored.', ['password' => $password])
            : Craft::t('lock', 'Assembled.'));

        if ($dossier->isPartial()) {
            Craft::$app->getSession()->setError(Craft::t('lock', 'Some sources could not be read, so this copy is incomplete. The covering note says which.'));
        }

        return $this->redirectToPostedUrl();
    }

    /** Staff download of a built dossier. Logged, because reading it all is itself processing. */
    public function actionDownload(): Response
    {
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        $request = $this->requestFromBody();

        if ($request->dossierPath === null) {
            throw new NotFoundHttpException(Craft::t('lock', 'Nothing has been assembled for this request yet.'));
        }

        $path = Plugin::getInstance()->dossiers->pathFor($request->dossierPath);

        if (!is_file($path)) {
            throw new NotFoundHttpException(Craft::t('lock', 'That archive has been cleaned up. Assemble it again.'));
        }

        Plugin::getInstance()->requests->addEvent($request, RequestEventRecord::TYPE_DOWNLOADED, Craft::t('lock', 'Archive downloaded.'));

        return $this->response->sendFile($path, basename($path), ['inline' => false]);
    }

    /** The erasure preview. Nothing is changed; the plan is returned for the operator to approve. */
    public function actionPlan(): Response
    {
        $this->requirePermission(Plugin::PERMISSION_ERASE);

        $request = $this->requestFromBody();
        $mode = (string)$this->request->getParam('mode', '');

        $plan = Plugin::getInstance()->erasure->plan(
            $request->getSubject(),
            $mode !== '' ? $mode : null,
            $request->id,
        );

        return $this->asJson([
            'fingerprint' => $plan->fingerprint(),
            'blocked' => $plan->blocked,
            'blockReason' => $plan->blockReason,
            'errors' => $plan->errors,
            'counts' => [
                'erase' => $plan->countBy('erase'),
                'anonymise' => $plan->countBy('anonymise'),
                'skip' => $plan->countBy('skip'),
            ],
            'html' => $this->getView()->renderTemplate('lock/requests/_plan', ['plan' => $plan], \craft\web\View::TEMPLATE_MODE_CP),
        ]);
    }

    /**
     * Carries the plan out.
     *
     * The fingerprint the operator approved is posted back and checked. If the data moved in
     * between, the run refuses rather than deleting a set nobody has seen.
     */
    public function actionErase(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_ERASE);

        $request = $this->requestFromBody();
        $mode = (string)$this->request->getBodyParam('mode', '');
        $fingerprint = (string)$this->request->getBodyParam('fingerprint', '');

        $plan = Plugin::getInstance()->erasure->plan($request->getSubject(), $mode !== '' ? $mode : null, $request->id);

        if ($plan->blocked) {
            return $this->asFailure($plan->blockReason ?? Craft::t('lock', 'This subject is on hold.'));
        }

        try {
            $outcome = Plugin::getInstance()->erasure->run($plan, false, $fingerprint !== '' ? $fingerprint : null);
        } catch (\yii\base\InvalidArgumentException $e) {
            return $this->asFailure($e->getMessage());
        }

        Plugin::getInstance()->requests->addEvent(
            $request,
            RequestEventRecord::TYPE_ERASED,
            $outcome->summary(),
            ['mode' => $plan->mode, 'fingerprint' => $outcome->planFingerprint],
        );

        if (!$outcome->isClean()) {
            Craft::$app->getSession()->setError(Craft::t('lock', '{n} records could not be dealt with. The run detail lists them.', ['n' => $outcome->failed]));

            return $this->redirectToPostedUrl();
        }

        return $this->asSuccess($outcome->summary());
    }

    public function actionExtend(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        $request = $this->requestFromBody();
        $reason = StringHelper::safeTruncate(trim((string)$this->request->getBodyParam('reason', '')), 1000);

        if ($reason === '') {
            return $this->asFailure(Craft::t('lock', 'Article 12(3) needs a reason for the extension, and the subject has to be told what it is.'));
        }

        if (!Plugin::getInstance()->requests->extend($request, $reason)) {
            return $this->asFailure(Craft::t('lock', 'This request has already been extended once. There is no second extension.'));
        }

        return $this->asSuccess(Craft::t('lock', 'Extended, and the subject has been told.'));
    }

    public function actionClose(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        $request = $this->requestFromBody();
        $status = (string)$this->request->getBodyParam('status', Request::STATUS_COMPLETED);
        $outcome = trim((string)$this->request->getBodyParam('outcome', '')) ?: null;

        if ($status === Request::STATUS_REFUSED && $outcome === null) {
            return $this->asFailure(Craft::t('lock', 'A refusal has to say why — Article 12(4).'));
        }

        Plugin::getInstance()->requests->close($request, $status, $outcome, (bool)$this->request->getBodyParam('notify', true));

        return $this->asSuccess(Craft::t('lock', 'Closed.'));
    }

    public function actionNote(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        $request = $this->requestFromBody();
        $note = trim((string)$this->request->getBodyParam('note', ''));

        if ($note !== '') {
            Plugin::getInstance()->requests->addEvent($request, RequestEventRecord::TYPE_NOTE, $note);
        }

        return $this->asSuccess(Craft::t('lock', 'Note added.'));
    }

    public function actionDelete(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        $id = (int)$this->request->getBodyParam('requestId');
        Plugin::getInstance()->requests->delete($id);

        return $this->asSuccess(Craft::t('lock', 'Request deleted. The ledger still records that it existed.'));
    }

    private function requestFromBody(): Request
    {
        $id = (int)$this->request->getParam('requestId');
        $request = Plugin::getInstance()->requests->getById($id);

        if ($request === null) {
            throw new NotFoundHttpException(Craft::t('lock', 'That request does not exist.'));
        }

        return $request;
    }
}
