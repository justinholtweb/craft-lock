<?php

namespace justinholtweb\lock\events;

use yii\base\Event;

/**
 * Fired so a plugin or module can add a source of personal data Lock cannot know about.
 *
 * The one extension point that genuinely matters here: a disclosure is only as complete as the
 * list of places it looked, and every site has one more.
 */
class RegisterCollectorsEvent extends Event
{
    /** @var array<int, class-string<\justinholtweb\lock\collectors\CollectorInterface>|\justinholtweb\lock\collectors\CollectorInterface> */
    public array $collectors = [];
}
