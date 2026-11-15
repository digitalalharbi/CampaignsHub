<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Entitlements;

use App\Domains\Notifications\Engine\Contracts\EntitlementGate;
use App\Domains\Notifications\Engine\Data\DeliveryIntent;
use App\Domains\Notifications\Engine\Enums\EntitlementKey;
use App\Domains\Notifications\Engine\Enums\EntitlementMode;
use App\Domains\Notifications\Engine\NotificationEngine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Spec §1: «an in-app heads-up goes to the workspace owner before the grace period ends». Once per workspace and key,
 * `heads_up_days_before_grace_end` days ahead. The event has no project, so the router sends it to the profile's
 * responsible role (owner / admin) only. Recorded on `workspace_entitlement_states.heads_up_sent_at`.
 */
final class GraceHeadsUp
{
    public function __construct(private readonly EntitlementGate $gate, private readonly NotificationEngine $engine) {}

    public function run(?Carbon $now = null): int
    {
        $now ??= Carbon::now();
        $days = (int) config('notifications_v2.entitlements.heads_up_days_before_grace_end', 3);
        $sent = 0;

        foreach (DB::table('tenants')->whereNull('deleted_at')->orderBy('id')->pluck('id') as $tenantId) {
            foreach (EntitlementKey::cases() as $key) {
                $state = $this->gate->state((string) $tenantId, $key);
                if ($state->mode !== EntitlementMode::Grace || $state->graceEndsAt === null || $state->graceEndsAt->gt($now->copy()->addDays($days))) {
                    continue;
                }
                $row = DB::table('workspace_entitlement_states')->where('tenant_id', $tenantId)->where('key', $key->value)->first();
                if ($row !== null && $row->heads_up_sent_at !== null) {
                    continue;
                }

                $this->engine->raise(new DeliveryIntent(
                    eventKey: 'plan.grace_ending', tenantId: (string) $tenantId, objectType: 'entitlement', objectId: $key->value,
                    payload: ['date' => $state->graceEndsAt->toDateString(), 'entitlement' => $key->value, 'url' => '/app/subscriptions'],
                    occurredAt: $now,
                ));

                if ($row === null) {
                    DB::table('workspace_entitlement_states')->insert([
                        'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'key' => $key->value, 'mode' => 'grace',
                        'grace_ends_at' => $state->graceEndsAt, 'heads_up_sent_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                } else {
                    DB::table('workspace_entitlement_states')->where('id', $row->id)->update(['heads_up_sent_at' => $now, 'updated_at' => $now]);
                }
                $sent++;
            }
        }

        return $sent;
    }
}
