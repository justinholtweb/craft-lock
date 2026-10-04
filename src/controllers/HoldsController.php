<?php

namespace justinholtweb\lock\controllers;

use Craft;
use craft\helpers\DateTimeHelper;
use craft\web\Controller;
use justinholtweb\lock\models\Hold;
use justinholtweb\lock\models\Subject;
use justinholtweb\lock\Plugin;
use justinholtweb\lock\records\ActivityRecord;
use yii\web\Response;

/**
 * Legal holds — the override that beats everything else in the plugin.
 *
 * A hold outranks a retention rule and outranks an erasure request, because "we deleted it under
 * our automated retention policy" is not a defence to a preservation order, and a subject's right
 * to erasure yields to the establishment or defence of legal claims under Article 17(3)(e).
 */
class HoldsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();

        return true;
    }

    public function actionIndex(): Response
    {
        $this->requirePermission(Plugin::PERMISSION_HOLDS);

        return $this->renderTemplate('lock/holds/index', [
            'holds' => Plugin::getInstance()->holds->all(),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_HOLDS);

        $hold = new Hold();
        $hold->id = ($id = $this->request->getBodyParam('holdId')) ? (int)$id : null;
        $hold->email = mb_strtolower(trim((string)$this->request->getBodyParam('email', '')));
        $hold->reason = trim((string)$this->request->getBodyParam('reason', ''));

        $expires = trim((string)$this->request->getBodyParam('expiresAt', ''));

        if ($expires !== '') {
            $date = DateTimeHelper::toDateTime($expires);
            $hold->expiresAt = $date === false ? null : $date;
        }

        $isNew = $hold->id === null;

        if (!Plugin::getInstance()->holds->save($hold)) {
            return $this->asModelFailure($hold, Craft::t('lock', 'A hold needs a reason. It is the thing that will be read a year from now.'), 'hold');
        }

        // The person is recorded as the subject — a keyed hash — and never in the summary text.
        // The ledger is append-only, so an address written into a sentence here would outlive
        // any erasure of the person it names.
        Plugin::getInstance()->activity->log(
            ActivityRecord::CATEGORY_ADMIN,
            $isNew ? 'hold.placed' : 'hold.updated',
            match (true) {
                $hold->isSiteWide() && $isNew => Craft::t('lock', 'A site-wide hold was placed: {reason}', ['reason' => $hold->reason]),
                $hold->isSiteWide() => Craft::t('lock', 'A site-wide hold was changed: {reason}', ['reason' => $hold->reason]),
                $isNew => Craft::t('lock', 'A hold was placed on one person: {reason}', ['reason' => $hold->reason]),
                default => Craft::t('lock', 'A hold on one person was changed: {reason}', ['reason' => $hold->reason]),
            },
            $hold->isSiteWide() ? null : new Subject(email: $hold->email),
            null,
            ['holdId' => $hold->id],
        );

        return $this->asSuccess($isNew ? Craft::t('lock', 'Hold placed.') : Craft::t('lock', 'Hold updated.'));
    }

    public function actionDelete(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_HOLDS);

        $id = (int)$this->request->getBodyParam('holdId');
        $hold = Plugin::getInstance()->holds->get($id);

        if ($hold !== null) {
            Plugin::getInstance()->holds->delete($id);

            Plugin::getInstance()->activity->log(
                ActivityRecord::CATEGORY_ADMIN,
                'hold.lifted',
                Craft::t('lock', 'A hold was lifted: {reason}', ['reason' => $hold->reason]),
                $hold->isSiteWide() ? null : new Subject(email: $hold->email),
                null,
                ['holdId' => $hold->id],
            );
        }

        return $this->asSuccess(Craft::t('lock', 'Hold lifted.'));
    }
}
