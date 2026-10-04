<?php

namespace justinholtweb\lock\controllers;

use Craft;
use craft\helpers\Json;
use craft\web\Controller;
use justinholtweb\lock\models\ConsentEntry;
use justinholtweb\lock\models\Settings;
use justinholtweb\lock\Plugin;
use justinholtweb\lock\records\ActivityRecord;
use yii\web\Response;

/** The consent ledger, from the desk side: who allows what, and what they were shown. */
class ConsentController extends Controller
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
        $this->requirePermission(Plugin::PERMISSION_VIEW);

        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();

        $criteria = [
            'purpose' => (string)$this->request->getParam('purpose', ''),
            'state' => (string)$this->request->getParam('state', ''),
            'search' => (string)$this->request->getParam('search', ''),
        ];

        return $this->renderTemplate('lock/consent/index', [
            'entries' => Plugin::getInstance()->consent->find($criteria, 200),
            'tally' => Plugin::getInstance()->consent->tally(),
            'stale' => Plugin::getInstance()->consent->stale(200),
            'purposes' => $settings->purposeOptions(),
            'criteria' => $criteria,
        ]);
    }

    /**
     * Records a decision by hand — the paper form, the phone call, the unsubscribe that came in
     * by email. Marked as recorded in the control panel by a named person, because the source of
     * a consent record is part of the evidence.
     */
    public function actionRecord(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        $email = mb_strtolower(trim((string)$this->request->getBodyParam('email', '')));
        $purpose = (string)$this->request->getBodyParam('purpose', '');
        $state = (string)$this->request->getBodyParam('state', ConsentEntry::STATE_GRANTED);

        $entry = Plugin::getInstance()->consent->record($email, $purpose, $state, ConsentEntry::SOURCE_CP, [
            'recordedBy' => Craft::$app->getUser()->getIdentity()?->username,
            'text' => trim((string)$this->request->getBodyParam('evidence', '')) ?: null,
        ]);

        if ($entry === null) {
            return $this->asFailure(Craft::t('lock', 'That needs a valid email address and a purpose.'));
        }

        return $this->asSuccess(Craft::t('lock', 'Recorded.'));
    }

    /**
     * The whole ledger as JSON, for a regulator, an auditor, or a migration off this site.
     *
     * Needs the manage permission, not just view: this is every address on the ledger in one
     * file, which is a bulk export of personal data, and it is logged as one.
     */
    public function actionExport(): Response
    {
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        $rows = array_map(static fn(ConsentEntry $e) => [
            'email' => $e->email,
            'purpose' => $e->purpose,
            'state' => $e->state,
            'source' => $e->source,
            'recorded' => $e->recordedAt?->format(\DateTime::ATOM),
            'expires' => $e->expiresAt?->format(\DateTime::ATOM),
            'policy_version' => $e->policyVersion,
            'evidence' => $e->evidence,
        ], Plugin::getInstance()->consent->find([], 100000));

        Plugin::getInstance()->activity->log(
            ActivityRecord::CATEGORY_ACCESS,
            'consent.exported',
            Craft::t('lock', 'The consent ledger was exported ({n} records).', ['n' => count($rows)]),
            null,
            null,
            ['rows' => count($rows)],
        );

        return $this->asRaw(Json::encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
            ->setDownloadHeaders('lock-consent-' . date('Ymd') . '.json', 'application/json');
    }
}
