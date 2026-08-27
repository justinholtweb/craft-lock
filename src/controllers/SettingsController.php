<?php

namespace justinholtweb\lock\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\lock\models\Request;
use justinholtweb\lock\models\RetentionRule;
use justinholtweb\lock\models\Settings;
use justinholtweb\lock\Plugin;
use justinholtweb\lock\records\ActivityRecord;
use yii\web\Response;

/**
 * Settings, in three screens because one would be unreadable.
 *
 * Everything here goes to project config. That includes the retention rules, which is the
 * decision most likely to be questioned: it means a rule cannot be added on a production site
 * with admin changes turned off. That is the intended answer. A rule that deletes personal data
 * on a schedule is a change to what the site does, and it should arrive by deploy, reviewed, like
 * every other one.
 */
class SettingsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireAdmin();

        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('lock/settings/index', [
            'settings' => Plugin::getInstance()->getSettings(),
            'requestTypes' => Request::types(),
            'isPro' => Plugin::getInstance()->isPro(),
        ]);
    }

    public function actionCollectors(): Response
    {
        $plugin = Plugin::getInstance();
        $collectors = [];

        foreach ($plugin->collectors->all() as $handle => $collector) {
            $collectors[$handle] = [
                'handle' => $handle,
                'label' => $collector->label(),
                'description' => $collector->description(),
                'available' => $collector->isAvailable(),
                'reason' => $collector->unavailableReason(),
                'scopes' => $collector->scopes(),
            ];
        }

        return $this->renderTemplate('lock/settings/collectors', [
            'settings' => $plugin->getSettings(),
            'collectors' => $collectors,
        ]);
    }

    public function actionRetention(): Response
    {
        $plugin = Plugin::getInstance();

        $scopeOptions = [];

        foreach ($plugin->collectors->scopes() as $key => $scope) {
            $scopeOptions[$key] = $scope->label . ' — ' . $scope->measuredFrom;
        }

        return $this->renderTemplate('lock/settings/retention', [
            'settings' => $plugin->getSettings(),
            'scopes' => $plugin->collectors->scopes(),
            'scopeOptions' => $scopeOptions,
            'isPro' => $plugin->isPro(),
            'units' => [
                RetentionRule::UNIT_DAYS => Craft::t('lock', 'days'),
                RetentionRule::UNIT_MONTHS => Craft::t('lock', 'months'),
                RetentionRule::UNIT_YEARS => Craft::t('lock', 'years'),
            ],
            'modes' => [
                RetentionRule::MODE_ANONYMISE => Craft::t('lock', 'Anonymise'),
                RetentionRule::MODE_ERASE => Craft::t('lock', 'Delete'),
                RetentionRule::MODE_REPORT => Craft::t('lock', 'Report only'),
            ],
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        /** @var Settings $settings */
        $settings = $plugin->getSettings();

        // Only the keys this screen posted are touched. A partial `savePluginSettings()` with a
        // whole fresh model would blank every setting belonging to the two screens that are not
        // on the page — which looks like nothing happening until somebody notices retention has
        // been switched off.
        $posted = $this->request->getBodyParam('settings', []);
        $settings->setAttributes($posted, false);

        if (!$settings->validate()) {
            return $this->asModelFailure($settings, Craft::t('lock', 'Could not save those settings.'), 'settings');
        }

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray())) {
            return $this->asModelFailure($settings, Craft::t('lock', 'Could not save those settings.'), 'settings');
        }

        $plugin->collectors->reset();

        $plugin->activity->log(
            ActivityRecord::CATEGORY_ADMIN,
            'settings.saved',
            Craft::t('lock', 'Lock’s settings were changed.'),
            null,
            null,
            ['keys' => array_keys($posted)],
        );

        Craft::$app->getSession()->setNotice(Craft::t('lock', 'Settings saved.'));

        return $this->redirectToPostedUrl();
    }
}
