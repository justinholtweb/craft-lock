<?php

namespace justinholtweb\lock\events;

use craft\events\CancelableEvent;
use justinholtweb\lock\models\Request;

/**
 * Fired around the life of a data subject request.
 *
 * Cancelable, and it extends Craft's `CancelableEvent` rather than plain `Event` for a specific
 * reason: `$event->isValid` does not exist on `yii\base\Event`, and reading it throws. A veto
 * hook written against a plain event is a hook that fatals the first time anything listens.
 */
class RequestEvent extends CancelableEvent
{
    public Request $request;

    /** @var bool Whether the request is new. */
    public bool $isNew = false;
}
