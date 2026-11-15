<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Contracts;

use App\Domains\Notifications\Engine\Data\Subscription;
use Illuminate\Support\Carbon;

/** Periodic digests (spec §10): daily (weekdays) / weekly / monthly at a time + timezone per subscription. */
interface Scheduler
{
    /** @return list<Subscription> active subscriptions whose next run is at or before `$now` */
    public function due(Carbon $now): array;

    /** The next run strictly after `$after`, or null when the combination can never run. */
    public function nextRun(Subscription $subscription, Carbon $after): ?Carbon;

    /**
     * The complete-days period a run at `$runAt` covers, or null when it would be empty or include today.
     *
     * @return array{key: string, from: string, to: string}|null
     */
    public function period(Subscription $subscription, Carbon $runAt): ?array;
}
