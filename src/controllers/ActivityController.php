<?php

namespace justinholtweb\lock\controllers;

use Craft;
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
                ActivityRecord::CATEGORY_REQUEST => Craft::t('lock', 'Requests'),
                ActivityRecord::CATEGORY_ACCESS => Craft::t('lock', 'Access'),
                ActivityRecord::CATEGORY_ERASURE => Craft::t('lock', 'Erasure'),
                ActivityRecord::CATEGORY_CONSENT => Craft::t('lock', 'Consent'),
                ActivityRecord::CATEGORY_RETENTION => Craft::t('lock', 'Retention'),
                ActivityRecord::CATEGORY_ADMIN => Craft::t('lock', 'Administration'),
            ],
            'tally' => Plugin::getInstance()->activity->tally((new DateTime())->modify('-30 days')),
        ]);
    }
}
