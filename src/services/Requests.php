<?php

namespace justinholtweb\lock\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use DateInterval;
use DateTime;
use justinholtweb\lock\events\RequestEvent;
use justinholtweb\lock\helpers\Address;
use justinholtweb\lock\helpers\Reference;
use justinholtweb\lock\models\Request;
use justinholtweb\lock\models\Settings;
use justinholtweb\lock\models\Subject;
use justinholtweb\lock\Plugin;
use justinholtweb\lock\records\ActivityRecord;
use justinholtweb\lock\records\RequestEventRecord;
use justinholtweb\lock\records\RequestRecord;
use yii\base\InvalidCallException;

/**
 * The life of a data subject request, from the form to the answer.
 *
 * The deadline is the load-bearing part. Everything else here is bookkeeping; the thing a
 * supervisory authority actually checks is whether the site answered within a month, and the only
 * way to be sure of that is for the clock to start automatically, count in one place, and be
 * visible without anybody having to remember to look.
 */
class Requests extends Component
{
    public const EVENT_BEFORE_SAVE_REQUEST = 'beforeSaveRequest';
    public const EVENT_AFTER_SAVE_REQUEST = 'afterSaveRequest';

    /**
     * Takes a request in, whatever the door was.
     *
     * The token is generated here and returned on the model *in the clear once*, because that is
     * the only moment it exists in a form that can be put in an email. What is stored is the hash.
     */
    public function create(Request $request, bool $notify = true, bool $skipVerification = false): bool
    {
        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();

        $request->email = mb_strtolower(trim($request->email));
        $request->receivedAt ??= new DateTime();
        $request->reference = $request->reference ?: Reference::next();

        $subject = new Subject(email: $request->email, name: $request->name);
        $subject->resolve();
        $request->userId ??= $subject->userId;

        $needsVerification = $settings->requireVerification && !$skipVerification && !$this->isSelfService($request);

        if (!$needsVerification) {
            $request->status = Request::STATUS_OPEN;
            $request->verifiedAt = new DateTime();
        } else {
            $request->status = Request::STATUS_UNVERIFIED;
            $request->plainToken = StringHelper::UUID() . StringHelper::randomString(24);
        }

        $request->dueAt = $this->deadline($request);

        if (!$this->save($request)) {
            return false;
        }

        $this->addEvent($request, RequestEventRecord::TYPE_RECEIVED, Craft::t('lock', 'Received through {source}.', ['source' => $request->sourceLabel()]));

        Plugin::getInstance()->activity->log(
            ActivityRecord::CATEGORY_REQUEST,
            'request.received',
            Craft::t('lock', '{type} request {reference} received.', ['type' => $request->type, 'reference' => $request->reference]),
            $subject,
            $request->id,
            ['source' => $request->source, 'verified' => !$needsVerification],
        );

        if ($notify) {
            $notifications = Plugin::getInstance()->notifications;

            $needsVerification
                ? $notifications->sendVerification($request)
                : $notifications->sendAcknowledgement($request);

            $notifications->notifyStaff($request);
        }

        return true;
    }

    /**
     * A signed-in person asking about their own address has already proved who they are — asking
     * them to click a link in an email to confirm the address they are currently authenticated
     * with is theatre, and it delays the clock for no gain.
     */
    private function isSelfService(Request $request): bool
    {
        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->trustSignedInSubjects) {
            return false;
        }

        $identity = Craft::$app->getUser()->getIdentity();

        return $identity !== null && mb_strtolower((string)$identity->email) === $request->email;
    }

    /**
     * When the answer is due.
     *
     * Counted from receipt by default rather than from verification. Recital 64 lets a controller
     * ask for proof of identity, but a clock that only starts once the subject clicks a link is a
     * clock a subject can leave stopped forever — and a regulator asked to believe that a request
     * received in March was not "received" until June will want a very good reason.
     */
    public function deadline(Request $request): DateTime
    {
        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();

        $from = $settings->clockStartsOnReceipt
            ? ($request->receivedAt ?? new DateTime())
            : ($request->verifiedAt ?? $request->receivedAt ?? new DateTime());

        $days = $settings->responseDays + ($request->extendedAt !== null ? $settings->extensionDays : 0);

        return (clone $from)->add(new DateInterval("P{$days}D"));
    }

    public function save(Request $request): bool
    {
        if (!$request->validate()) {
            return false;
        }

        $isNew = $request->id === null;
        $record = $isNew ? new RequestRecord() : RequestRecord::findOne($request->id);

        if ($record === null) {
            return false;
        }

        $event = new RequestEvent(['request' => $request, 'isNew' => $isNew]);
        $this->trigger(self::EVENT_BEFORE_SAVE_REQUEST, $event);

        if (!$event->isValid) {
            return false;
        }

        $record->reference = $request->reference;
        $record->type = $request->type;
        $record->status = $request->status;
        $record->source = $request->source;
        $record->email = $request->email;
        $record->name = $request->name;
        $record->userId = $request->userId;
        $record->message = $request->message;
        $record->note = $request->note;
        $record->outcome = $request->outcome;
        $record->assigneeId = $request->assigneeId;
        $record->receivedAt = Db::prepareDateForDb($request->receivedAt ?? new DateTime());
        $record->verifiedAt = $request->verifiedAt !== null ? Db::prepareDateForDb($request->verifiedAt) : null;
        $record->dueAt = $request->dueAt !== null ? Db::prepareDateForDb($request->dueAt) : null;
        $record->extendedAt = $request->extendedAt !== null ? Db::prepareDateForDb($request->extendedAt) : null;
        $record->closedAt = $request->closedAt !== null ? Db::prepareDateForDb($request->closedAt) : null;
        $record->dossierPath = $request->dossierPath;
        $record->dossierBuiltAt = $request->dossierBuiltAt !== null ? Db::prepareDateForDb($request->dossierBuiltAt) : null;
        $record->context = $request->context === [] ? null : $request->context;
        $record->remindersSent = $request->remindersSent;

        if ($request->plainToken !== null) {
            /** @var Settings $settings */
            $settings = Plugin::getInstance()->getSettings();
            $record->tokenHash = hash('sha256', $request->plainToken);
            $record->tokenExpiresAt = Db::prepareDateForDb(
                (new DateTime())->add(new DateInterval("PT{$settings->verificationTtl}H")),
            );
        }

        if (!$record->save(false)) {
            return false;
        }

        $request->id = $record->id;
        $request->uid = $record->uid;

        $this->trigger(self::EVENT_AFTER_SAVE_REQUEST, $event);

        return true;
    }

    public function getById(int $id): ?Request
    {
        $record = RequestRecord::findOne($id);

        return $record !== null ? $this->toModel($record) : null;
    }

    public function getByReference(string $reference): ?Request
    {
        $record = RequestRecord::findOne(['reference' => $reference]);

        return $record !== null ? $this->toModel($record) : null;
    }

    /**
     * Looks a request up by the token in a verification link.
     *
     * Hashed on the way in and compared as a hash, so a stolen database gives an attacker no
     * usable links, and expired tokens simply do not match anything.
     */
    public function getByToken(string $token): ?Request
    {
        if (trim($token) === '') {
            return null;
        }

        $record = RequestRecord::find()
            ->where(['tokenHash' => hash('sha256', $token)])
            ->andWhere(['>', 'tokenExpiresAt', Db::prepareDateForDb(new DateTime())])
            ->one();

        return $record instanceof RequestRecord ? $this->toModel($record) : null;
    }

    public function verify(Request $request): bool
    {
        if ($request->isVerified()) {
            return true;
        }

        $request->verifiedAt = new DateTime();
        $request->status = Request::STATUS_OPEN;
        $request->plainToken = null;

        if (!$this->save($request)) {
            return false;
        }

        // The token is single use. Left alive, the link in the subject's inbox stays a way into
        // their own request for as long as the mailbox exists.
        Craft::$app->getDb()->createCommand()
            ->update(RequestRecord::tableName(), ['tokenHash' => null, 'tokenExpiresAt' => null], ['id' => $request->id])
            ->execute();

        $this->addEvent($request, RequestEventRecord::TYPE_VERIFIED, Craft::t('lock', 'Identity confirmed by email.'));

        Plugin::getInstance()->activity->log(
            ActivityRecord::CATEGORY_REQUEST,
            'request.verified',
            Craft::t('lock', '{reference} verified.', ['reference' => $request->reference]),
            $request->getSubject(),
            $request->id,
        );

        Plugin::getInstance()->notifications->sendAcknowledgement($request);

        return true;
    }

    /** Claims the Article 12(3) extension, which has to be a deliberate act with a reason. */
    public function extend(Request $request, string $reason): bool
    {
        if ($request->wasExtended()) {
            return false;
        }

        $request->extendedAt = new DateTime();
        $request->dueAt = $this->deadline($request);

        if (!$this->save($request)) {
            return false;
        }

        $this->addEvent($request, RequestEventRecord::TYPE_EXTENDED, $reason);
        Plugin::getInstance()->notifications->sendExtension($request, $reason);

        return true;
    }

    public function close(Request $request, string $status, ?string $outcome = null, bool $notify = true): bool
    {
        $request->status = $status;
        $request->outcome = $outcome ?? $request->outcome;
        $request->closedAt = new DateTime();

        if (!$this->save($request)) {
            return false;
        }

        $this->addEvent($request, RequestEventRecord::TYPE_CLOSED, Craft::t('lock', 'Closed as {status}.', ['status' => $request->statusLabel()]));

        Plugin::getInstance()->activity->log(
            ActivityRecord::CATEGORY_REQUEST,
            'request.closed',
            Craft::t('lock', '{reference} closed as {status}.', ['reference' => $request->reference, 'status' => $status]),
            $request->getSubject(),
            $request->id,
        );

        if ($notify) {
            Plugin::getInstance()->notifications->sendCompletion($request);
        }

        // An answered erasure request is the last place the address survives. The collectors
        // cannot reach it — an open request is retained so the answer has somewhere to go — so it
        // is done here, once the answer has gone.
        if ($request->type === Request::TYPE_ERASURE && $status === Request::STATUS_COMPLETED) {
            $this->anonymise($request);
        }

        return true;
    }

    /**
     * Takes the person out of a finished request, leaving the evidence that it was handled.
     *
     * What stays: the reference, the type, every date, the outcome and the timeline — which is
     * what a supervisory authority asks for. What goes: the address (replaced with the same
     * pseudonym every other source got, so the request still lines up with the erasure
     * certificate), the name, what they wrote, where they wrote it from, and any dossier built
     * for them, file and all.
     */
    public function anonymise(Request $request): bool
    {
        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();
        $subject = $request->getSubject();
        $email = $subject->normalisedEmail();

        if ($request->id === null || $email === '') {
            return false;
        }

        $domain = $settings->anonymousDomain ?: 'anonymised.invalid';

        // Already done — the address is somebody's pseudonym, not somebody.
        if (str_starts_with($email, 'anon-') && str_ends_with($email, '@' . $domain)) {
            return true;
        }

        $pseudonym = $subject->pseudonym() . '@' . $domain;

        Plugin::getInstance()->dossiers->deleteFor($subject, $request->dossierPath);

        $db = Craft::$app->getDb();

        $db->createCommand()->update(RequestRecord::tableName(), [
            'email' => $pseudonym,
            'name' => null,
            'userId' => null,
            'message' => null,
            'note' => $request->note !== null ? Address::replace($request->note, $email, $pseudonym) : null,
            'outcome' => $request->outcome !== null ? Address::replace($request->outcome, $email, $pseudonym) : null,
            'context' => null,
            'tokenHash' => null,
            'tokenExpiresAt' => null,
            'dossierPath' => null,
        ], ['id' => $request->id])->execute();

        // The timeline is free text typed by staff and by Lock; the address is taken out of it
        // wherever it was written down, and nothing else in it changes.
        foreach ($this->timeline($request->id) as $event) {
            $message = $event->message !== null ? Address::replace($event->message, $email, $pseudonym) : null;
            $data = is_array($event->data) ? Address::replaceDeep($event->data, $email, $pseudonym) : $event->data;

            if ($message !== $event->message || $data !== $event->data) {
                $db->createCommand()->update(RequestEventRecord::tableName(), [
                    'message' => $message,
                    'data' => is_array($data) ? Json::encode($data) : $data,
                ], ['id' => $event->id])->execute();
            }
        }

        $request->email = $pseudonym;
        $request->name = null;
        $request->userId = null;
        $request->message = null;
        $request->context = [];
        $request->dossierPath = null;

        $this->addEvent($request, RequestEventRecord::TYPE_NOTE, Craft::t('lock', 'The requester’s details were anonymised once the erasure was answered.'));

        Plugin::getInstance()->activity->log(
            ActivityRecord::CATEGORY_ERASURE,
            'request.anonymised',
            Craft::t('lock', '{reference} anonymised after the erasure was answered.', ['reference' => $request->reference]),
            $subject,
            $request->id,
        );

        return true;
    }

    /**
     * Assembles a request's dossier and writes the archive.
     *
     * Returns the dossier and the one-time archive password (or null when archives are not
     * protected). The password is never stored — a password kept next to the file it protects
     * protects nothing — so an operator who loses it assembles again, which takes seconds.
     *
     * @return array{0: \justinholtweb\lock\models\Dossier, 1: string|null, 2: string}
     * @throws InvalidCallException if the request has not been confirmed
     */
    public function assemble(Request $request): array
    {
        if (!$request->isActionable()) {
            throw new InvalidCallException(Craft::t('lock', 'This request has not been confirmed by the person who made it. Nothing can be assembled or erased until it is.'));
        }

        $plugin = Plugin::getInstance();

        [$dossier, $password, $filename] = $plugin->dossiers->build($request->getSubject(), $request->id);

        // One archive per request. The previous one is the same person's data a few minutes
        // staler, and there is no reason for two copies of it to exist.
        if ($request->dossierPath !== null && $request->dossierPath !== $filename) {
            $previous = $plugin->dossiers->pathFor($request->dossierPath);

            if (is_file($previous)) {
                @unlink($previous);
            }
        }

        $request->dossierPath = $filename;
        $request->dossierBuiltAt = new DateTime();

        if ($request->status === Request::STATUS_OPEN) {
            $request->status = Request::STATUS_ASSEMBLED;
        }

        $this->save($request);
        $this->addEvent(
            $request,
            RequestEventRecord::TYPE_ASSEMBLED,
            Craft::t('lock', '{n} records assembled from {sources} sources.', [
                'n' => $dossier->count(),
                'sources' => count($dossier->populatedBundles()),
            ]),
            ['partial' => $dossier->isPartial(), 'problems' => $dossier->problems()],
        );

        return [$dossier, $password, $filename];
    }

    public function addEvent(Request $request, string $type, ?string $message = null, array $data = []): void
    {
        $record = new RequestEventRecord();
        $record->requestId = (int)$request->id;
        $record->type = $type;
        $record->message = $message;
        $record->data = $data === [] ? null : $data;
        $record->userId = Craft::$app->getUser()->getId();
        $record->save(false);
    }

    /** @return RequestEventRecord[] */
    public function timeline(int $requestId): array
    {
        /** @var RequestEventRecord[] $events */
        $events = RequestEventRecord::find()
            ->where(['requestId' => $requestId])
            ->orderBy(['dateCreated' => SORT_ASC, 'id' => SORT_ASC])
            ->all();

        return $events;
    }

    /**
     * @param array<string, mixed> $criteria
     * @return Request[]
     */
    public function find(array $criteria = [], int $limit = 100, int $offset = 0): array
    {
        $query = RequestRecord::find();

        if (isset($criteria['status'])) {
            $query->andWhere(['status' => $criteria['status']]);
        }

        if (!empty($criteria['open'])) {
            $query->andWhere(['status' => [
                Request::STATUS_UNVERIFIED,
                Request::STATUS_OPEN,
                Request::STATUS_ASSEMBLED,
                Request::STATUS_AWAITING,
            ]]);
        }

        if (isset($criteria['type'])) {
            $query->andWhere(['type' => $criteria['type']]);
        }

        if (isset($criteria['email'])) {
            $query->andWhere(['email' => mb_strtolower(trim((string)$criteria['email']))]);
        }

        if (isset($criteria['search']) && trim((string)$criteria['search']) !== '') {
            $search = trim((string)$criteria['search']);
            $query->andWhere(['or', ['like', 'email', $search], ['like', 'reference', $search], ['like', 'name', $search]]);
        }

        // Open requests first, then by how little time is left. A list sorted by date puts the
        // request that is due tomorrow underneath thirty that are finished.
        $records = $query
            ->orderBy(['closedAt' => SORT_ASC, 'dueAt' => SORT_ASC, 'id' => SORT_DESC])
            ->limit($limit)
            ->offset($offset)
            ->all();

        return array_map(fn(RequestRecord $r) => $this->toModel($r), $records);
    }

    public function countOpen(): int
    {
        return (int)(new Query())
            ->from([RequestRecord::tableName()])
            ->where(['status' => [
                Request::STATUS_UNVERIFIED,
                Request::STATUS_OPEN,
                Request::STATUS_ASSEMBLED,
                Request::STATUS_AWAITING,
            ]])
            ->count();
    }

    public function countOverdue(): int
    {
        return (int)(new Query())
            ->from([RequestRecord::tableName()])
            ->where(['status' => [
                Request::STATUS_UNVERIFIED,
                Request::STATUS_OPEN,
                Request::STATUS_ASSEMBLED,
                Request::STATUS_AWAITING,
            ]])
            ->andWhere(['<', 'dueAt', Db::prepareDateForDb(new DateTime())])
            ->count();
    }

    /**
     * Open requests that have crossed a reminder threshold and not yet been nudged for it.
     *
     * `remindersSent` is a count, not a flag, so a site that adds a third reminder threshold later
     * does not re-send the first two to every request already in flight.
     *
     * @return Request[]
     */
    public function dueForReminder(?DateTime $now = null): array
    {
        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();
        $now ??= new DateTime();

        $thresholds = $settings->reminderDays;
        rsort($thresholds);

        if ($thresholds === []) {
            return [];
        }

        $due = [];

        foreach ($this->find(['open' => true], 500) as $request) {
            $remaining = $request->daysRemaining($now);

            if ($remaining === null) {
                continue;
            }

            $crossed = 0;

            foreach ($thresholds as $threshold) {
                if ($remaining <= $threshold) {
                    $crossed++;
                }
            }

            if ($crossed > $request->remindersSent) {
                $due[] = $request;
            }
        }

        return $due;
    }

    /**
     * Marks unverified requests dead once their link has expired.
     *
     * They are not deleted. "Somebody asked and never confirmed it was them" is itself a thing
     * worth being able to show, particularly if the address belonged to somebody else.
     */
    public function expireUnverified(): int
    {
        /** @var RequestRecord[] $records */
        $records = RequestRecord::find()
            ->where(['status' => Request::STATUS_UNVERIFIED])
            ->andWhere(['<', 'tokenExpiresAt', Db::prepareDateForDb(new DateTime())])
            ->all();

        foreach ($records as $record) {
            $record->status = Request::STATUS_EXPIRED;
            $record->closedAt = Db::prepareDateForDb(new DateTime());
            $record->tokenHash = null;
            $record->save(false);
        }

        return count($records);
    }

    public function delete(int $id): bool
    {
        $record = RequestRecord::findOne($id);

        if ($record === null) {
            return false;
        }

        // Logged before the delete, and the ledger has no foreign key to the request, so the line
        // saying it was deleted outlives the row it is about.
        Plugin::getInstance()->activity->log(
            ActivityRecord::CATEGORY_ADMIN,
            'request.deleted',
            Craft::t('lock', 'Request {reference} was deleted.', ['reference' => $record->reference]),
            new Subject(email: $record->email),
            $id,
        );

        return $record->delete() !== false;
    }

    public function toModel(RequestRecord $record): Request
    {
        $request = new Request();
        $request->id = $record->id;
        $request->uid = $record->uid;
        $request->reference = $record->reference;
        $request->type = $record->type;
        $request->status = $record->status;
        $request->source = $record->source;
        $request->email = $record->email;
        $request->name = $record->name;
        $request->userId = $record->userId;
        $request->message = $record->message;
        $request->note = $record->note;
        $request->outcome = $record->outcome;
        $request->assigneeId = $record->assigneeId;
        $request->receivedAt = $this->date($record->receivedAt);
        $request->verifiedAt = $this->date($record->verifiedAt);
        $request->dueAt = $this->date($record->dueAt);
        $request->extendedAt = $this->date($record->extendedAt);
        $request->closedAt = $this->date($record->closedAt);
        $request->dossierPath = $record->dossierPath;
        $request->dossierBuiltAt = $this->date($record->dossierBuiltAt);
        $request->context = is_array($record->context) ? $record->context : [];
        $request->remindersSent = (int)$record->remindersSent;

        return $request;
    }

    private function date(mixed $value): ?DateTime
    {
        if ($value === null || $value === '') {
            return null;
        }

        $date = DateTimeHelper::toDateTime($value);

        return $date === false ? null : $date;
    }

    /** The link that confirms a request, built once and never stored. */
    public function verificationUrl(Request $request): ?string
    {
        if ($request->plainToken === null) {
            return null;
        }

        // In the path, and named `code` — see the routing note in Plugin::registerUrlRules().
        return UrlHelper::siteUrl('lock/verify/' . $request->plainToken);
    }
}
