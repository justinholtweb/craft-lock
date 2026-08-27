<?php

namespace justinholtweb\lock;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\UrlHelper;
use craft\log\MonologTarget;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use justinholtweb\lock\models\Settings;
use justinholtweb\lock\services\Activity;
use justinholtweb\lock\services\Collectors;
use justinholtweb\lock\services\Consent;
use justinholtweb\lock\services\Dossiers;
use justinholtweb\lock\services\Erasure;
use justinholtweb\lock\services\Holds;
use justinholtweb\lock\services\Notifications;
use justinholtweb\lock\services\Register;
use justinholtweb\lock\services\Requests;
use justinholtweb\lock\services\Retention;
use justinholtweb\lock\services\Schedules;
use justinholtweb\lock\twig\LockVariable;
use yii\base\Event;

/**
 * Lock — GDPR and DSAR governance for Craft.
 *
 * Craft ships with cookie banners in the plugin store and nothing behind them. Lock is the part
 * that makes a privacy policy true: a door for subject access requests, an assembly of everything
 * the site actually holds about a person across every source it has, the choice between
 * anonymising and erasing, a ledger of consent and processing that can be shown to a regulator,
 * and retention rules that delete on a timer instead of on a good intention.
 *
 * Three ideas hold it together, and each is written up where it lives:
 *
 * 1. **One collector per source, doing all three jobs** — find, plan, act. Splitting them is how
 *    a disclosure and an erasure come to disagree. See {@see collectors\CollectorInterface}.
 * 2. **The plan is materialised before anything happens**, and execution reads it rather than
 *    re-searching. See {@see services\Erasure}.
 * 3. **Retention is erasure with a different way of choosing records**, so an automated nightly
 *    purge is held to every guarantee a hand-run erasure gets. See {@see services\Retention}.
 *
 * @property-read Requests $requests
 * @property-read Dossiers $dossiers
 * @property-read Collectors $collectors
 * @property-read Erasure $erasure
 * @property-read Consent $consent
 * @property-read Activity $activity
 * @property-read Register $register
 * @property-read Retention $retention
 * @property-read Holds $holds
 * @property-read Notifications $notifications
 * @property-read Schedules $schedules
 * @property-read Settings $settings
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const EDITION_LITE = 'lite';
    public const EDITION_PRO = 'pro';

    /** See requests, the ledger and the register. */
    public const PERMISSION_VIEW = 'lock:view';

    /** Work a request: assemble, export, correspond, close. */
    public const PERMISSION_MANAGE = 'lock:manage';

    /** Actually anonymise or delete somebody's data. Deliberately separate from working a request. */
    public const PERMISSION_ERASE = 'lock:erase';

    /** Place and lift legal holds. */
    public const PERMISSION_HOLDS = 'lock:holds';

    /** Edit the Article 30 register. */
    public const PERMISSION_REGISTER = 'lock:register';

    /** Run retention rules by hand. */
    public const PERMISSION_RETENTION = 'lock:retention';

    public const LOG_CATEGORY = 'lock';

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;

    public static function editions(): array
    {
        return [
            self::EDITION_LITE,
            self::EDITION_PRO,
        ];
    }

    public static function config(): array
    {
        return [
            'components' => [
                'requests' => Requests::class,
                'dossiers' => Dossiers::class,
                'collectors' => Collectors::class,
                'erasure' => Erasure::class,
                'consent' => Consent::class,
                'activity' => Activity::class,
                'register' => Register::class,
                'retention' => Retention::class,
                'holds' => Holds::class,
                'notifications' => Notifications::class,
                'schedules' => Schedules::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerLogging();
        $this->registerUrlRules();
        $this->registerPermissions();
        $this->registerTwigVariable();
        $this->registerScheduleTrigger();
    }

    /**
     * The edition gate, in one method.
     *
     * **Everything needed to answer a request lawfully is in Lite** — intake, assembly, export,
     * anonymise, erase, the consent ledger, the activity ledger, legal holds. Pro is the
     * paperwork and the automation: retention rules that run on a timer, the Article 30 register,
     * and the deadline reminders. A site that has bought the cheaper edition can still comply; it
     * just has to do the recurring parts by hand.
     */
    public function isPro(): bool
    {
        return $this->is(self::EDITION_PRO, '>=');
    }

    /**
     * Lock keeps its own log.
     *
     * A tool whose job is deleting personal data needs a trail that survives the database it was
     * deleting from. `storage/logs/lock.log` is that trail; the ledger in the database is the
     * convenient copy, not the authoritative one.
     */
    private function registerLogging(): void
    {
        /** @var Settings $settings */
        $settings = $this->getSettings();

        Craft::getLogger()->dispatcher->targets[] = new MonologTarget([
            'name' => self::LOG_CATEGORY,
            'categories' => [self::LOG_CATEGORY],
            'level' => $settings->logLevel,
            'logContext' => false,
            'allowLineBreaks' => true,
            'maxFiles' => 90,
        ]);
    }

    private function registerTwigVariable(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function(Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('lock', LockVariable::class);
            },
        );
    }

    /**
     * Fires due retention rules from control-panel traffic when the site has no cron.
     *
     * Control panel only, and never on an AJAX request. A front-end page load that triggers a
     * purge would put the work behind a page cache and make it fire unpredictably; the control
     * panel is at least visited by somebody who could be told about it.
     */
    private function registerScheduleTrigger(): void
    {
        /** @var Settings $settings */
        $settings = $this->getSettings();

        if (!$this->isPro() || !$settings->scheduleEnabled || $settings->scheduleTrigger !== Settings::TRIGGER_WEB) {
            return;
        }

        $request = Craft::$app->getRequest();

        if ($request->getIsConsoleRequest() || !$request->getIsCpRequest() || $request->getIsAjax()) {
            return;
        }

        Craft::$app->onAfterRequest(function() {
            $this->schedules->queueIfDue();
        });
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $user = Craft::$app->getUser();

        $subnav = [
            'overview' => ['label' => Craft::t('lock', 'Overview'), 'url' => 'lock'],
            'requests' => ['label' => Craft::t('lock', 'Requests'), 'url' => 'lock/requests'],
        ];

        // The badge is the deadline, which is the only number on this screen a regulator will ever
        // ask about — so it is in the nav, where it is seen without going looking.
        $overdue = $this->requests->countOverdue();

        if ($overdue > 0) {
            $item['badgeCount'] = $overdue;
        }

        if ($user->checkPermission(self::PERMISSION_VIEW)) {
            $subnav['consent'] = ['label' => Craft::t('lock', 'Consent'), 'url' => 'lock/consent'];
            $subnav['activity'] = ['label' => Craft::t('lock', 'Activity'), 'url' => 'lock/activity'];
        }

        if ($this->isPro() && $user->checkPermission(self::PERMISSION_REGISTER)) {
            $subnav['register'] = ['label' => Craft::t('lock', 'Register'), 'url' => 'lock/register'];
        }

        if ($this->isPro() && $user->checkPermission(self::PERMISSION_RETENTION)) {
            $subnav['retention'] = ['label' => Craft::t('lock', 'Retention'), 'url' => 'lock/retention'];
        }

        if ($user->checkPermission(self::PERMISSION_HOLDS)) {
            $subnav['holds'] = ['label' => Craft::t('lock', 'Holds'), 'url' => 'lock/holds'];
        }

        if ($user->getIsAdmin()) {
            $subnav['settings'] = ['label' => Craft::t('lock', 'Settings'), 'url' => 'lock/settings'];
        }

        $item['subnav'] = $subnav;

        return $item;
    }

    protected function createSettingsModel(): Settings
    {
        return new Settings();
    }

    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('lock/settings'));
    }

    private function registerUrlRules(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function(RegisterUrlRulesEvent $event) {
                $event->rules['lock'] = 'lock/overview/index';
                $event->rules['lock/requests'] = 'lock/requests/index';
                $event->rules['lock/requests/new'] = 'lock/requests/edit';
                $event->rules['lock/requests/<requestId:\d+>'] = 'lock/requests/detail';
                $event->rules['lock/consent'] = 'lock/consent/index';
                $event->rules['lock/activity'] = 'lock/activity/index';
                $event->rules['lock/register'] = 'lock/register/index';
                $event->rules['lock/register/new'] = 'lock/register/edit';
                $event->rules['lock/register/<activityId:\d+>'] = 'lock/register/edit';
                $event->rules['lock/register/report'] = 'lock/register/report';
                $event->rules['lock/retention'] = 'lock/retention/index';
                $event->rules['lock/retention/history'] = 'lock/retention/history';
                $event->rules['lock/holds'] = 'lock/holds/index';
                $event->rules['lock/settings'] = 'lock/settings/index';
                $event->rules['lock/settings/collectors'] = 'lock/settings/collectors';
                $event->rules['lock/settings/retention'] = 'lock/settings/retention';
            },
        );

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            static function(RegisterUrlRulesEvent $event) {
                // Real routes, not just action URLs. The verification link goes in an email, gets
                // forwarded, and turns up in a support ticket six months later — it should look
                // like a page on the site, because that is what it is.
                //
                // The parameter is `code`, not `token`, and that is not a style choice: `token` is
                // Craft's own preview-token parameter. Craft reads it before routing and answers
                // `400 Invalid token` to every verification link, so a plugin that uses the
                // obvious name ships an intake flow whose emails all dead-end.
                $event->rules['lock/verify/<code:[^\/]+>'] = 'lock/portal/verify';
                $event->rules['lock/verify'] = 'lock/portal/verify';
                $event->rules['lock/status'] = 'lock/portal/status';
                $event->rules['lock/download/<code:[^\/]+>'] = 'lock/portal/download';
            },
        );
    }

    private function registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            static function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('lock', 'Lock'),
                    'permissions' => [
                        self::PERMISSION_VIEW => [
                            'label' => Craft::t('lock', 'See requests, the ledger and the register'),
                            'nested' => [
                                self::PERMISSION_MANAGE => [
                                    'label' => Craft::t('lock', 'Work requests: assemble, export and answer'),
                                    'nested' => [
                                        self::PERMISSION_ERASE => [
                                            'label' => Craft::t('lock', 'Anonymise and delete personal data'),
                                        ],
                                    ],
                                ],
                                self::PERMISSION_HOLDS => [
                                    'label' => Craft::t('lock', 'Place and lift legal holds'),
                                ],
                                self::PERMISSION_REGISTER => [
                                    'label' => Craft::t('lock', 'Edit the record of processing activities'),
                                ],
                                self::PERMISSION_RETENTION => [
                                    'label' => Craft::t('lock', 'Run retention rules'),
                                ],
                            ],
                        ],
                    ],
                ];
            },
        );
    }
}
