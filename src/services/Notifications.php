<?php

namespace justinholtweb\lock\services;

use Craft;
use craft\base\Component;
use craft\helpers\UrlHelper;
use craft\web\View;
use justinholtweb\lock\models\Request;
use justinholtweb\lock\models\Settings;
use justinholtweb\lock\Plugin;
use justinholtweb\lock\records\RequestEventRecord;
use Throwable;

/**
 * The email a request generates.
 *
 * Two audiences with opposite needs. The **subject** gets plain language, a reference to quote,
 * and a date by which they will hear back — Article 12(1) asks for "concise, transparent,
 * intelligible", which rules out most of what a compliance workflow would like to say. **Staff**
 * get the deadline and a link.
 *
 * Nothing here throws. An unreachable SMTP server must not stop a request being recorded: the
 * legal obligation is to have received it and to answer within the month, and a failed
 * acknowledgement email is a nuisance, while a request that failed to save because of one is a
 * breach waiting to be found.
 */
class Notifications extends Component
{
    public function sendVerification(Request $request): bool
    {
        $url = Plugin::getInstance()->requests->verificationUrl($request);

        if ($url === null) {
            return false;
        }

        return $this->toSubject($request, 'verify', Craft::t('lock', 'Confirm your data protection request ({reference})', [
            'reference' => $request->reference,
        ]), ['url' => $url]);
    }

    public function sendAcknowledgement(Request $request): bool
    {
        return $this->toSubject($request, 'acknowledge', Craft::t('lock', 'We have your request ({reference})', [
            'reference' => $request->reference,
        ]));
    }

    public function sendCompletion(Request $request): bool
    {
        return $this->toSubject($request, 'complete', Craft::t('lock', 'Your request {reference} has been answered', [
            'reference' => $request->reference,
        ]));
    }

    public function sendExtension(Request $request, string $reason): bool
    {
        return $this->toSubject($request, 'extend', Craft::t('lock', 'We need longer with request {reference}', [
            'reference' => $request->reference,
        ]), ['reason' => $reason]);
    }

    /** Tells staff a request has arrived. */
    public function notifyStaff(Request $request): bool
    {
        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->notifyStaff) {
            return false;
        }

        return $this->send(
            $settings->resolvedStaffRecipients(),
            Craft::t('lock', '[{type}] {reference} — due {date}', [
                'type' => strtoupper($request->type),
                'reference' => $request->reference,
                'date' => $request->dueAt?->format('j M Y') ?? '',
            ]),
            'staff',
            ['request' => $request, 'url' => UrlHelper::cpUrl("lock/requests/$request->id")],
        );
    }

    /**
     * The deadline nudge. Increments the counter so the same threshold is not sent twice.
     *
     * Pro, and it goes to staff, so it respects the staff switch like every other staff email.
     */
    public function sendReminder(Request $request): bool
    {
        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();

        if (!Plugin::getInstance()->isPro() || !$settings->notifyStaff) {
            return false;
        }

        $sent = $this->send(
            $settings->resolvedStaffRecipients(),
            Craft::t('lock', '{days} days left on {reference}', [
                'days' => max(0, (int)$request->daysRemaining()),
                'reference' => $request->reference,
            ]),
            'reminder',
            ['request' => $request, 'url' => UrlHelper::cpUrl("lock/requests/$request->id")],
        );

        if ($sent) {
            $request->remindersSent++;
            Plugin::getInstance()->requests->save($request);
        }

        return $sent;
    }

    /** @param array<string, \justinholtweb\lock\models\ErasureOutcome> $outcomes */
    public function sendRetentionReport(array $outcomes): bool
    {
        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->notifyStaff || $outcomes === []) {
            return false;
        }

        return $this->send(
            $settings->resolvedStaffRecipients(),
            Craft::t('lock', 'Retention run: {n} rules', ['n' => count($outcomes)]),
            'retention',
            ['outcomes' => $outcomes, 'url' => UrlHelper::cpUrl('lock/retention')],
        );
    }

    private function toSubject(Request $request, string $template, string $subjectLine, array $variables = []): bool
    {
        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->notifySubject) {
            return false;
        }

        $sent = $this->send([$request->email], $subjectLine, $template, $variables + ['request' => $request]);

        if ($sent) {
            Plugin::getInstance()->requests->addEvent(
                $request,
                RequestEventRecord::TYPE_EMAILED,
                Craft::t('lock', 'Sent “{subject}”.', ['subject' => $subjectLine]),
                ['template' => $template],
            );
        }

        return $sent;
    }

    /**
     * @param string[] $to
     */
    private function send(array $to, string $subject, string $template, array $variables = []): bool
    {
        if ($to === []) {
            return false;
        }

        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();

        try {
            $view = Craft::$app->getView();

            // Rendered in CP mode. A site template mode would look for the email in the *site's*
            // template folder, where it isn't, and would let a site's own `_layouts` wrap a legal
            // notice in whatever the marketing site does.
            $body = $view->renderTemplate(
                "lock/_email/$template",
                $variables + ['settings' => $settings],
                View::TEMPLATE_MODE_CP,
            );

            $message = Craft::$app->getMailer()->compose()
                ->setTo($to)
                ->setSubject($subject)
                ->setHtmlBody($body)
                ->setTextBody(trim(html_entity_decode(strip_tags(preg_replace('/<(br|\/p|\/div|\/li|\/h\d)[^>]*>/i', "\n", $body) ?? $body))));

            $replyTo = $settings->resolvedContactEmail();

            if ($replyTo !== '') {
                $message->setReplyTo($replyTo);
            }

            return $message->send();
        } catch (Throwable $e) {
            Craft::error("Lock could not send the “{$template}” email: " . $e->getMessage(), Plugin::LOG_CATEGORY);

            return false;
        }
    }
}
