<?php

namespace justinholtweb\lock\twig;

use Craft;
use craft\helpers\UrlHelper;
use justinholtweb\lock\models\ConsentEntry;
use justinholtweb\lock\models\Request;
use justinholtweb\lock\models\Settings;
use justinholtweb\lock\Plugin;
use yii\base\Behavior;

/**
 * `craft.lock` — what a template needs.
 *
 * Two audiences again. A **privacy page** needs the request form and the list of request types. A
 * **marketing template or a tag manager** needs one question answered before it does anything:
 * may I process this person for this purpose. That question is `craft.lock.allows()`, and it is
 * the only line of Twig most sites will ever need from this plugin.
 */
class LockVariable extends Behavior
{
    public function settings(): Settings
    {
        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();

        return $settings;
    }

    /**
     * Whether this person currently allows this purpose.
     *
     * Answers **false** for an address nobody has ever recorded a decision for. Silence is not
     * consent — Article 4(11) is explicit about that, and a helper that defaults to true would
     * turn every unknown visitor into a lawful marketing target.
     */
    public function allows(?string $email, string $purpose): bool
    {
        if ($email === null || trim($email) === '') {
            return false;
        }

        return Plugin::getInstance()->consent->allows($email, $purpose);
    }

    /**
     * The signed-in user's answer, which is what a preferences page wants.
     *
     * @return array<string, bool>
     */
    public function consent(?string $email = null): array
    {
        $email ??= Craft::$app->getUser()->getIdentity()?->email;

        return $email !== null ? Plugin::getInstance()->consent->state($email) : [];
    }

    /** @return ConsentEntry[] */
    public function consentHistory(?string $email = null, int $limit = 50): array
    {
        $email ??= Craft::$app->getUser()->getIdentity()?->email;

        return $email !== null ? Plugin::getInstance()->consent->history($email, $limit) : [];
    }

    /** @return array<string, string> */
    public function purposes(): array
    {
        return $this->settings()->purposeOptions();
    }

    /**
     * The request types the public form is allowed to offer.
     *
     * @return array<string, string>
     */
    public function requestTypes(): array
    {
        $settings = $this->settings();
        $all = Request::types();
        $offered = [];

        foreach ($settings->intakeTypes as $type) {
            if (isset($all[$type])) {
                $offered[$type] = $all[$type];
            }
        }

        return $offered;
    }

    public function intakeEnabled(): bool
    {
        return $this->settings()->intakeEnabled;
    }

    /**
     * Whether this address was erased and must not be brought back.
     *
     * The check an import script or a signup form should make before writing a row. It reads a
     * keyed hash — Lock does not keep the address to compare against.
     */
    public function isSuppressed(string $email): bool
    {
        return Plugin::getInstance()->holds->isSuppressed($email);
    }

    /**
     * The signed-in person's own requests, for a "your data" page.
     *
     * @return Request[]
     */
    public function myRequests(): array
    {
        $email = Craft::$app->getUser()->getIdentity()?->email;

        return $email !== null ? Plugin::getInstance()->requests->find(['email' => $email], 50) : [];
    }

    /** Where a request form should post to. */
    public function actionUrl(): string
    {
        return UrlHelper::actionUrl('lock/portal/submit');
    }

    /** Where somebody checks on a request they already made. */
    public function statusUrl(): string
    {
        return UrlHelper::siteUrl('lock/status');
    }

    public function contactEmail(): string
    {
        return $this->settings()->resolvedContactEmail();
    }
}
