<?php

declare(strict_types=1);

namespace App\Domains\Notifications\External\Review;

use App\Domains\Notifications\Engine\Data\Delivery;
use App\Domains\Notifications\Engine\Data\DeliveryIntent;
use App\Domains\Notifications\Engine\NotificationEngine;
use App\Domains\Notifications\External\Recipients\ExternalSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Review-before-send (spec §9). A held external delivery keeps its immutable `held_for_review` log row; this queue holds
 * the decision: «أرسل» queues a NEW delivery marked released (it still passes the privacy check, never overridable),
 * «تخطَّ هذه المرة» closes it, and no decision inside the window (default 4 h, workspace setting) closes it as skipped
 * with an internal notice. Each hold raises `external.held_for_review` (a follow-up task through builder P's contracts —
 * the catalog event has `creates_task`).
 */
final class ReviewQueue
{
    public function __construct(private readonly ExternalSettings $settings, private readonly NotificationEngine $engine) {}

    public function hold(Delivery $d, string $reason, ?Carbon $now = null): string
    {
        $now ??= Carbon::now();
        $existing = DB::table('notification_external_reviews')->where('delivery_id', $d->id)->value('id');
        if ($existing !== null) {
            return (string) $existing;
        }
        $hours = (int) ($this->settings->for($d->tenantId)['external']['review_window_hours'] ?? config('notifications_v2.review.window_hours', 4));
        $id = (string) Str::uuid();
        DB::table('notification_external_reviews')->insert([
            'id' => $id, 'tenant_id' => $d->tenantId, 'delivery_id' => $d->id, 'reference' => $d->reference, 'event_key' => $d->eventKey,
            'subscription_id' => $d->subscriptionId, 'recipient_id' => $d->recipient->externalId, 'project_id' => $d->projectId,
            'reason' => mb_substr($reason, 0, 80), 'status' => 'pending', 'expires_at' => $now->copy()->addHours(max(1, $hours)),
            'created_at' => $now, 'updated_at' => $now,
        ]);
        if ($d->projectId !== null) {
            $this->engine->raise(new DeliveryIntent(
                eventKey: 'external.held_for_review', tenantId: $d->tenantId, projectId: $d->projectId, objectType: 'external_review', objectId: $id,
                payload: ['recipient' => (string) $d->recipient->address, 'reference' => $d->reference, 'reason' => $reason, 'review_id' => $id], occurredAt: $now,
            ));
        }

        return $id;
    }

    /** «أرسل»: queue the same message again, released from review. @return string|null the new delivery id */
    public function send(string $tenantId, string $reviewId, int $actorUserId, ?Carbon $now = null): ?string
    {
        $now ??= Carbon::now();
        $review = DB::table('notification_external_reviews')->where('tenant_id', $tenantId)->where('id', $reviewId)->where('status', 'pending')->first();
        if ($review === null || Carbon::parse((string) $review->expires_at)->lte($now)) {
            return null;
        }
        $row = DB::table('notification_outbox')->where('tenant_id', $tenantId)->where('id', $review->delivery_id)->first();
        if ($row === null) {
            return null;
        }
        $payload = is_string($row->payload) ? (array) json_decode($row->payload, true) : (array) $row->payload;
        $payload['_review_released'] = $reviewId;
        $new = (string) Str::uuid();

        DB::transaction(function () use ($row, $payload, $new, $now, $reviewId, $actorUserId, $tenantId): void {
            DB::table('notification_outbox')->insert([
                'id' => $new, 'tenant_id' => $row->tenant_id, 'reference' => NotificationEngine::reference(), 'event_key' => $row->event_key,
                'scope' => $row->scope, 'channel' => $row->channel, 'priority' => $row->priority, 'recipient_kind' => $row->recipient_kind,
                'recipient_user_id' => $row->recipient_user_id, 'recipient_external_id' => $row->recipient_external_id, 'subscription_id' => $row->subscription_id,
                'recipient_address' => $row->recipient_address, 'project_id' => $row->project_id, 'dedup_key' => mb_substr($row->dedup_key.'|released', 0, 191),
                'group_key' => null, 'group_count' => $row->group_count, 'payload' => json_encode($payload), 'content_version' => $row->content_version,
                'state' => 'pending', 'attempts' => 0, 'not_before' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('notification_external_reviews')->where('id', $reviewId)->where('tenant_id', $tenantId)->update([
                'status' => 'sent', 'outcome_reason' => 'decided', 'decided_by' => $actorUserId, 'decided_at' => $now, 'released_delivery_id' => $new, 'updated_at' => $now,
            ]);
        });

        return $new;
    }

    /** «تخطَّ هذه المرة». */
    public function skip(string $tenantId, string $reviewId, int $actorUserId, ?Carbon $now = null): bool
    {
        $now ??= Carbon::now();

        return DB::table('notification_external_reviews')->where('tenant_id', $tenantId)->where('id', $reviewId)->where('status', 'pending')
            ->update(['status' => 'skipped', 'outcome_reason' => 'decided', 'decided_by' => $actorUserId, 'decided_at' => $now, 'updated_at' => $now]) === 1;
    }

    /** No decision inside the window → skipped + an internal notice. @return int closed */
    public function expire(?Carbon $now = null): int
    {
        $now ??= Carbon::now();
        $rows = DB::table('notification_external_reviews')->where('status', 'pending')->where('expires_at', '<=', $now)->limit(500)->get();
        $n = 0;
        foreach ($rows as $r) {
            $won = DB::table('notification_external_reviews')->where('id', $r->id)->where('status', 'pending')
                ->update(['status' => 'skipped', 'outcome_reason' => 'window_expired', 'decided_at' => $now, 'updated_at' => $now]);
            if ($won === 1 && $r->project_id !== null) {
                $this->engine->raise(new DeliveryIntent(
                    eventKey: 'external.review_skipped', tenantId: (string) $r->tenant_id, projectId: (string) $r->project_id, objectType: 'external_review', objectId: (string) $r->id,
                    payload: ['reference' => (string) $r->reference], occurredAt: $now,
                ));
                $n++;
            }
        }

        return $n;
    }

    /** @return list<array<string, mixed>> */
    public function list(string $tenantId, string $status = 'pending'): array
    {
        return DB::table('notification_external_reviews as v')
            ->leftJoin('notification_external_recipients as r', 'r.id', '=', 'v.recipient_id')
            ->leftJoin('projects as p', 'p.id', '=', 'v.project_id')
            ->where('v.tenant_id', $tenantId)->where('v.status', $status)->orderBy('v.expires_at')->limit(200)
            ->get(['v.id', 'v.reference', 'v.event_key', 'v.reason', 'v.status', 'v.outcome_reason', 'v.expires_at', 'v.decided_by', 'v.decided_at', 'v.subscription_id', 'v.project_id', 'p.name as project_name', 'r.email as recipient_email', 'r.name as recipient_name'])
            ->map(static fn ($r): array => (array) $r)->all();
    }
}
