<?php

namespace justinholtweb\lock\helpers;

use Craft;

/**
 * The public doors' rate limit, in one place.
 *
 * Shared by the intake form, the consent endpoint, the status lookup, and consent captured from
 * Formie and Freeform — every way an anonymous visitor can make Lock write a row or send an email.
 */
abstract class RateLimit
{
    /**
     * Counts one attempt against every key, and says whether all of them are still in bounds.
     *
     * Every key is counted, not just the first one over — otherwise the IP counter stops moving
     * the moment the address counter trips, and a script learns which one it hit.
     *
     * The counter is an add-then-increment rather than a read-then-write: `add()` only succeeds
     * for the first hit in a window, so two simultaneous first hits cannot both believe they were
     * first. It is not a transaction — the cache API has no increment — but the window it leaves
     * is a handful of requests wide, not unbounded.
     *
     * @param array<string, string> $keys
     */
    public static function within(string $bucket, array $keys, int $perKey, int $global = 0): bool
    {
        $within = true;

        if ($perKey > 0) {
            foreach ($keys as $name => $value) {
                if ($value === '') {
                    continue;
                }

                if (self::hit("lock.rate.$bucket.$name." . hash('sha256', $value)) > $perKey) {
                    $within = false;
                }
            }
        }

        if ($global > 0 && self::hit("lock.rate.$bucket.global") > $global) {
            $within = false;
        }

        return $within;
    }

    /** One more attempt in a fixed one-hour window. Returns the count including this one. */
    private static function hit(string $key, int $window = 3600): int
    {
        $cache = Craft::$app->getCache();
        $now = time();

        if ($cache->add($key, ['n' => 1, 'until' => $now + $window], $window)) {
            return 1;
        }

        $current = $cache->get($key);
        $until = is_array($current) ? (int)($current['until'] ?? 0) : 0;

        if ($until <= $now) {
            $cache->set($key, ['n' => 1, 'until' => $now + $window], $window);

            return 1;
        }

        $count = (int)($current['n'] ?? 0) + 1;

        // The window keeps its original end. Re-arming the TTL on every hit would turn a steady
        // trickle into a limit that never resets — fatal for the site-wide counter.
        $cache->set($key, ['n' => $count, 'until' => $until], max(1, $until - $now));

        return $count;
    }

    /**
     * The address as a rate-limit key, with the cheap variations folded together.
     *
     * `Ada+1@x`, `ada+2@x` and `ADA@x` are one mailbox, and a limit that counts them separately
     * is a limit of five per spelling. Gmail ignores dots in the local part and answers to
     * googlemail.com as well, so those are folded too. Only the key is normalised — the request
     * keeps the address exactly as it was typed.
     */
    public static function keyForEmail(string $email): string
    {
        $email = mb_strtolower(trim($email));
        $at = strrpos($email, '@');

        if ($at === false) {
            return $email;
        }

        $local = substr($email, 0, $at);
        $domain = substr($email, $at + 1);

        if (($plus = strpos($local, '+')) !== false) {
            $local = substr($local, 0, $plus);
        }

        if ($domain === 'gmail.com' || $domain === 'googlemail.com') {
            $local = str_replace('.', '', $local);
            $domain = 'gmail.com';
        }

        return "$local@$domain";
    }
}
