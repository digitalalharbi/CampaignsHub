<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Support;

use Illuminate\Support\Carbon;

/**
 * INTEGRATION-SYNC-VISIBILITY-001 — «when will this update itself», answered by the schedule itself.
 *
 * ## The question the card could not answer
 *
 * A connected platform said when data LAST arrived and nothing about when more would. So the only
 * way to find out whether a quiet integration was broken or merely between runs was to press «Sync
 * now» — which is a real provider call, made by somebody who only wanted reassurance.
 *
 * ## Derived from the scheduler, never stored
 *
 * `routes/console.php` runs `integrations:sync` every thirty minutes, so the next run is the next
 * half-hour boundary. That is read from the same fact the scheduler uses rather than written into a
 * column, because a stored «next run» drifts the moment the cadence changes and nobody notices until
 * a customer is waiting for an email that already came.
 *
 * ## And it is only stated when it is TRUE
 *
 * The caller passes whether this connection is actually eligible — connected, authorised, with at
 * least one account bound. A revoked authorisation is still on the schedule in the sense that the
 * command will run; it is not going to sync anything, and «next sync in 12 minutes» over a
 * connection that needs re-authorising is the most confident kind of wrong. Null is the honest
 * answer there, and the card says «يحتاج إعادة ربط» instead.
 */
final class NextScheduledSync
{
    /** Matches `Schedule::command('integrations:sync')->everyThirtyMinutes()`. */
    private const EVERY_MINUTES = 30;

    public static function at(bool $eligible, ?Carbon $now = null): ?Carbon
    {
        if (! $eligible) {
            return null;
        }

        $now = ($now ?? Carbon::now())->copy()->seconds(0);
        $minute = (int) $now->format('i');
        $next = (intdiv($minute, self::EVERY_MINUTES) + 1) * self::EVERY_MINUTES;

        return $now->copy()->minutes(0)->addMinutes($next);
    }
}
