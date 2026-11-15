<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Scheduling;

use App\Domains\Notifications\Engine\Contracts\Scheduler;
use App\Domains\Notifications\Engine\Data\DeliveryIntent;
use App\Domains\Notifications\Engine\NotificationEngine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Turns due subscriptions into `digest.periodic` intents, one per (non-archived) project, carrying the complete-days
 * period. The plan is checked by the router (a blocked run is logged `blocked_by_plan`, never silently dropped) and
 * again by the sender. The next run is always advanced, so a failure never fires the same run twice.
 */
final class DigestRunner
{
    public function __construct(private readonly Scheduler $scheduler, private readonly NotificationEngine $engine) {}

    /** @return array{subscriptions: int, intents: int, skipped_empty_period: int} */
    public function run(?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $out = ['subscriptions' => 0, 'intents' => 0, 'skipped_empty_period' => 0];

        foreach ($this->scheduler->due($now) as $subscription) {
            $out['subscriptions']++;
            $period = $this->scheduler->period($subscription, $now);

            if ($period === null) {
                $out['skipped_empty_period']++;
            } else {
                $live = DB::table('projects')->where('tenant_id', $subscription->tenantId)->whereIn('id', $subscription->projectIds)
                    ->where('status', '!=', 'archived')->whereNull('deleted_at')->orderBy('id')->pluck('id');
                foreach ($live as $projectId) {
                    $this->engine->raise(new DeliveryIntent(
                        eventKey: 'digest.periodic',
                        tenantId: $subscription->tenantId,
                        projectId: (string) $projectId,
                        objectType: 'subscription',
                        objectId: $subscription->id,
                        threshold: $period['key'].':'.$period['from'].':'.$period['to'],
                        payload: ['period' => $period, 'digest_types' => $subscription->digestTypes],
                        occurredAt: $now,
                        subscriptionId: $subscription->id,
                    ));
                    $out['intents']++;
                }
            }

            DB::table('notification_subscriptions')->where('id', $subscription->id)->update([
                'last_run_at' => $now, 'next_run_at' => $this->scheduler->nextRun($subscription, $now), 'updated_at' => $now,
            ]);
        }

        return $out;
    }
}
