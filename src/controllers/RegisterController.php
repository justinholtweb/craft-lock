<?php

namespace justinholtweb\lock\controllers;

use Craft;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use justinholtweb\lock\models\ProcessingActivity;
use justinholtweb\lock\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/** The Article 30 register. Pro, because it is paperwork rather than answering a request. */
class RegisterController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        if (!Plugin::getInstance()->isPro()) {
            throw new ForbiddenHttpException(Craft::t('lock', 'The record of processing activities is a Pro feature.'));
        }

        return true;
    }

    public function actionIndex(): Response
    {
        $this->requirePermission(Plugin::PERMISSION_VIEW);

        return $this->renderTemplate('lock/register/index', [
            'activities' => Plugin::getInstance()->register->all(),
            'gaps' => Plugin::getInstance()->register->gaps(),
        ]);
    }

    public function actionEdit(?int $activityId = null): Response
    {
        $this->requirePermission(Plugin::PERMISSION_REGISTER);

        $activity = $activityId !== null
            ? Plugin::getInstance()->register->get($activityId)
            : new ProcessingActivity();

        if ($activity === null) {
            throw new NotFoundHttpException();
        }

        $sources = [];

        foreach (Plugin::getInstance()->collectors->all() as $handle => $collector) {
            $sources[$handle] = $collector->label();
        }

        return $this->renderTemplate('lock/register/edit', [
            'activity' => $activity,
            'bases' => ProcessingActivity::bases(),
            'sources' => $sources,
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_REGISTER);

        $id = $this->request->getBodyParam('activityId');
        $activity = $id ? Plugin::getInstance()->register->get((int)$id) : new ProcessingActivity();

        if ($activity === null) {
            throw new NotFoundHttpException();
        }

        $activity->name = (string)$this->request->getBodyParam('name', '');
        $activity->purpose = (string)$this->request->getBodyParam('purpose', '');
        $activity->basis = (string)$this->request->getBodyParam('basis', ProcessingActivity::BASIS_LEGITIMATE);
        $activity->balancing = trim((string)$this->request->getBodyParam('balancing', '')) ?: null;
        $activity->dataCategories = $this->lines($this->request->getBodyParam('dataCategories', ''));
        $activity->subjectCategories = $this->lines($this->request->getBodyParam('subjectCategories', ''));
        $activity->recipients = $this->lines($this->request->getBodyParam('recipients', ''));
        $activity->transfers = trim((string)$this->request->getBodyParam('transfers', '')) ?: null;
        $activity->retention = (string)$this->request->getBodyParam('retention', '');
        $activity->safeguards = trim((string)$this->request->getBodyParam('safeguards', '')) ?: null;
        $activity->systems = array_values(array_filter((array)$this->request->getBodyParam('systems', [])));
        $activity->owner = trim((string)$this->request->getBodyParam('owner', '')) ?: null;
        $activity->enabled = (bool)$this->request->getBodyParam('enabled', true);
        $activity->sortOrder = (int)$this->request->getBodyParam('sortOrder', 0);

        if (!Plugin::getInstance()->register->save($activity)) {
            return $this->asModelFailure($activity, Craft::t('lock', 'Could not save that entry.'), 'activity');
        }

        return $this->redirect(UrlHelper::cpUrl('lock/register'));
    }

    /**
     * One value per line rather than a comma list.
     *
     * Register entries routinely name recipients like "Acme Fulfilment, Ltd" — splitting on commas
     * would turn one processor into two.
     *
     * @return string[]
     */
    private function lines(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('trim', $value)));
        }

        return array_values(array_filter(array_map('trim', preg_split('/\R/', (string)$value) ?: [])));
    }

    public function actionDelete(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_REGISTER);

        Plugin::getInstance()->register->delete((int)$this->request->getBodyParam('activityId'));

        return $this->asSuccess(Craft::t('lock', 'Removed from the register.'));
    }

    public function actionReview(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_REGISTER);

        Plugin::getInstance()->register->markReviewed();

        return $this->asSuccess(Craft::t('lock', 'Register marked as reviewed today.'));
    }

    /**
     * The register as a page you can print, or as JSON.
     *
     * A regulator asking for an Article 30 record wants a document, not a login — so this renders
     * standalone, with a stylesheet built for paper.
     */
    public function actionReport(): Response
    {
        $this->requirePermission(Plugin::PERMISSION_VIEW);

        $report = Plugin::getInstance()->register->report();

        if ($this->request->getParam('format') === 'json') {
            return $this->asRaw(Json::encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
                ->setDownloadHeaders('lock-register-' . date('Ymd') . '.json', 'application/json');
        }

        return $this->renderTemplate('lock/register/report', ['report' => $report]);
    }
}
