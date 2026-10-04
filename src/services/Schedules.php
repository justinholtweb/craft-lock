<?php

namespace justinholtweb\lock\services;

use Craft;
use craft\base\Component;
use DateTime;
use justinholtweb\lock\models\Settings;
use justinholtweb\lock\Plugin;
use justinholtweb\lock\queue\ApplyRetentionRules;

/**
 * Firing the scheduled work on a site with no cron.
 *
 * Never does the work inside a page request — it queues a job, and only after the response has
 * been sent. A visitor's page load must not be the thing that deletes ten thousand rows, and a
 * request that fails halfway through leaves a half-finished purge with nobody watching.
 *
 * The due check is a single cached read, so on the overwhelming majority of requests, where
 * nothing is owed, it costs nothing.
 */
class Schedules extends Component
{
    private const CACHE_KEY = 'lock.schedule.checked';

    /** How long to go without asking again. */
    private const CACHE_TTL = 300;

    public function queueIfDue(): bool
    {
        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->scheduleEnabled || !Plugin::getInstance()->isPro()) {
            return false;
        }

        $cache = Craft::$app->getCache();

        if ($cache->get(self::CACHE_KEY) !== false) {
            return false;
        }

        $cache->set(self::CACHE_KEY, true, self::CACHE_TTL);

        $due = [];

        foreach (Plugin::getInstance()->retention->enabledRules() as $key => $rule) {
            if (Plugin::getInstance()->retention->isDue($rule)) {
                $due[] = $key;
            }
        }

        if ($due === []) {
            return false;
        }

        Craft::$app->getQueue()->push(new ApplyRetentionRules(['ruleKeys' => $due]));

        return true;
    }

    /** When the next scheduled run is expected. */
    public function next(): ?DateTime
    {
        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();

        return $settings->scheduleEnabled ? Plugin::getInstance()->retention->nextRun() : null;
    }
}
