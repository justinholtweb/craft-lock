<?php

namespace justinholtweb\lock\controllers;

use Craft;
use craft\web\Controller;
use craft\web\View;
use justinholtweb\lock\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Running retention rules, and looking at what they did.
 *
 * The rules themselves are edited under Settings, because they live in project config — a rule
 * that deletes data on a timer is a deployable behaviour, not a runtime preference. This screen
 * is where you preview one, run one by hand, and read the ledger afterwards.
 */
class RetentionController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        if (!Plugin::getInstance()->isPro()) {
            throw new ForbiddenHttpException(Craft::t('lock', 'Retention rules are a Pro feature.'));
        }

        return true;
    }

    public function actionIndex(): Response
    {
        $this->requirePermission(Plugin::PERMISSION_VIEW);

        $retention = Plugin::getInstance()->retention;
        $rules = $retention->rules();
        $lastRuns = [];

        foreach ($rules as $key => $rule) {
            $lastRuns[$key] = $retention->lastRun($key, true);
        }

        return $this->renderTemplate('lock/retention/index', [
            'rules' => $rules,
            'scopes' => Plugin::getInstance()->collectors->scopes(),
            'uncovered' => $retention->uncoveredScopes(),
            'lastRuns' => $lastRuns,
            'nextRun' => Plugin::getInstance()->schedules->next(),
            'history' => $retention->history(20),
        ]);
    }

    /** What a rule would do, without doing it. */
    public function actionPreview(): Response
    {
        $this->requirePermission(Plugin::PERMISSION_RETENTION);

        $rule = Plugin::getInstance()->retention->rule((string)$this->request->getParam('key', ''));

        if ($rule === null) {
            throw new NotFoundHttpException(Craft::t('lock', 'No such rule.'));
        }

        $plan = Plugin::getInstance()->retention->preview($rule);

        return $this->asJson([
            'counts' => [
                'erase' => $plan->countBy('erase'),
                'anonymise' => $plan->countBy('anonymise'),
                'skip' => $plan->countBy('skip'),
            ],
            'errors' => $plan->errors,
            'html' => $this->getView()->renderTemplate('lock/retention/_preview', [
                'rule' => $rule,
                'plan' => $plan,
            ], View::TEMPLATE_MODE_CP),
        ]);
    }

    public function actionRun(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_RETENTION);

        $rule = Plugin::getInstance()->retention->rule((string)$this->request->getBodyParam('key', ''));

        if ($rule === null) {
            throw new NotFoundHttpException(Craft::t('lock', 'No such rule.'));
        }

        $dryRun = (bool)$this->request->getBodyParam('dryRun', false);
        $outcome = Plugin::getInstance()->retention->run($rule, $dryRun);

        // The service forces a first run to be a dry run, so what was asked for and what happened
        // can differ — and if they do, saying so is the whole point of the guard.
        if (!$dryRun && $outcome->dryRun) {
            return $this->asSuccess(Craft::t('lock', 'That was the rule’s first run, so it reported instead of deleting: {summary}. Run it again to apply it.', [
                'summary' => $outcome->summary(),
            ]));
        }

        return $this->asSuccess($outcome->summary());
    }

    public function actionHistory(): Response
    {
        $this->requirePermission(Plugin::PERMISSION_VIEW);

        return $this->renderTemplate('lock/retention/history', [
            'runs' => Plugin::getInstance()->retention->history(200),
        ]);
    }
}
