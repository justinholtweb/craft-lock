<?php

namespace justinholtweb\lock\controllers;

use craft\web\Controller;
use DateTime;
use justinholtweb\lock\Plugin;
use justinholtweb\lock\records\ActivityRecord;
use yii\web\Response;

/**
 * The ledger, read-only on purpose.
 *
 * There is no delete button and no edit button on this screen, and that is the feature. An audit
 * trail a site's own administrators can quietly tidy is not evidence of anything.
 */
class ActivityController extends Controller
{
    public function actionIndex(): Response
    {
        $this->requirePermission(Plugin::PERMISSION_VIEW);

        $category = (string)$this->request->getParam('category', '');

        return $this->renderTemplate('lock/activity/index', [
            'entries' => Plugin::getInstance()->activity->recent(300, $category !== '' ? $category : null),
            'category' => $category,
            'categories' => [
                ActivityRecord::CATEGORY_REQUEST => 'Requests',
                ActivityRecord::CATEGORY_ACCESS => 'Access',
                ActivityRecord::CATEGORY_ERASURE => 'Erasure',
                ActivityRecord::CATEGORY_CONSENT => 'Consent',
                ActivityRecord::CATEGORY_RETENTION => 'Retention',
                ActivityRecord::CATEGORY_ADMIN => 'Administration',
            ],
            'tally' => Plugin::getInstance()->activity->tally((new DateTime())->modify('-30 days')),
        ]);
    }
}
