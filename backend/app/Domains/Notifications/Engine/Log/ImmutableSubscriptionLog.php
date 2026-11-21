<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Log;

use App\Domains\Notifications\Engine\Contracts\SubscriptionLog;
use App\Domains\Notifications\Engine\Enums\SubscriptionLogAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Appends to `notification_subscription_log` (spec §4). Immutable by trigger; who acted is snapshotted by name. */
final class ImmutableSubscriptionLog implements SubscriptionLog
{
    public function record(string $tenantId, SubscriptionLogAction $action, ?string $recipientId = null, ?string $subscriptionId = null, array $detail = [], ?int $actorUserId = null): string
    {
        $id = (string) Str::uuid();
        $label = $actorUserId === null ? 'system' : (string) (DB::table('users')->where('id', $actorUserId)->value('name') ?? 'user:'.$actorUserId);

        DB::table('notification_subscription_log')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'recipient_id' => $recipientId,
            'subscription_id' => $subscriptionId,
            'action' => $action->value,
            'detail' => $detail === [] ? null : json_encode($detail, JSON_UNESCAPED_UNICODE),
            'actor_user_id' => $actorUserId,
            'actor_label' => mb_substr($label, 0, 120),
            'created_at' => Carbon::now(),
        ]);

        return $id;
    }
}
