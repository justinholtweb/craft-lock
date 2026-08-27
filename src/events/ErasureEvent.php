<?php

namespace justinholtweb\lock\events;

use craft\events\CancelableEvent;
use justinholtweb\lock\models\ErasureOutcome;
use justinholtweb\lock\models\ErasurePlan;

/**
 * Fired before and after a plan runs.
 *
 * `isValid = false` on the before event stops the run — the hook a site needs when its own rules
 * about who may be erased are more complicated than a legal hold can express.
 */
class ErasureEvent extends CancelableEvent
{
    public ErasurePlan $plan;
    public ?ErasureOutcome $outcome = null;
    public bool $dryRun = false;
}
