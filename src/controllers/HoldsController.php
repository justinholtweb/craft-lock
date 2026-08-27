<?php

namespace justinholtweb\lock\controllers;

use Craft;
use craft\helpers\DateTimeHelper;
use craft\web\Controller;
use justinholtweb\lock\models\Hold;
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

        if (!Plugin::getInstance()->holds->save($hold)) {
            return $this->asModelFailure($hold, Craft::t('lock', 'A hold needs a reason. It is the thing that will be read a year from now.'), 'hold');
        }

        Plugin::getInstance()->activity->log(
            ActivityRecord::CATEGORY_ADMIN,
            'hold.placed',
            $hold->isSiteWide()
                ? Craft::t('lock', 'A site-wide hold was placed: {reason}', ['reason' => $hold->reason])
                : Craft::t('lock', 'A hold was placed on {email}: {reason}', ['email' => $hold->email, 'reason' => $hold->reason]),
        );

        return $this->asSuccess(Craft::t('lock', 'Hold placed.'));
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
            );
        }

        return $this->asSuccess(Craft::t('lock', 'Hold lifted.'));
    }
}
