<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Sending;

use App\Domains\Notifications\Engine\Contracts\DeliveryLog;
use App\Domains\Notifications\Engine\Contracts\EntitlementGate;
use App\Domains\Notifications\Engine\Contracts\EventRegistry;
use App\Domains\Notifications\Engine\Contracts\MessageComposer;
use App\Domains\Notifications\Engine\Contracts\PreSendCheck;
use App\Domains\Notifications\Engine\Contracts\Sender;
use App\Domains\Notifications\Engine\Contracts\UsageCounter;
use App\Domains\Notifications\Engine\Data\Delivery;
use App\Domains\Notifications\Engine\Data\DeliveryIntent;
use App\Domains\Notifications\Engine\Data\SendResult;
use App\Domains\Notifications\Engine\Enums\Channel;
use App\Domains\Notifications\Engine\Enums\DeliveryStatus;
use App\Domains\Notifications\Engine\Enums\EntitlementKey;
use App\Domains\Notifications\Engine\Enums\Scope;
use App\Domains\Notifications\Engine\NotificationEngine;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Drains the outbox (spec §4). Run by `notifications:v2-send` every minute.
 *
 * - **Batches, spread**: at most `batch_size` rows per run and `per_tenant_per_run` per workspace, critical first.
 * - **No duplicate sends**: a row is WON by an update that only matches `pending`; two workers cannot both send it. A
 *   worker that dies after winning leaves `claimed`; past `claim_timeout_minutes` it is logged `failed` (abandoned) and
 *   never re-sent — a missed message is recoverable, a duplicate cannot be recalled.
 * - **Server-side plan checks at send time** (a key switched off after the row was queued still stops it), and the
 *   monthly limits (`monthly_messages`, `monthly_push`) → `blocked_by_plan`.
 * - An external recipient or subscription no longer active → `skipped`.
 * - Pre-send checks (tag `notifications.presend`) may hold or skip a delivery; an external delivery whose words do not
 *   carry the mandatory external parts is skipped (`external_template_not_installed`) — the engine never improvises one.
 * - **Three attempts** with growing back-off (`backoff_minutes`); `sent` only on provider acceptance; a final external
 *   failure raises the internal `external.failed` event.
 */
final class SenderPipeline
{
    /** @var array<string, Sender> */
    private array $senders = [];

    /**
     * @param  iterable<Sender>  $senders
     * @param  iterable<PreSendCheck>  $checks
     */
    public function __construct(
        iterable $senders,
        private readonly iterable $checks,
        private readonly DeliveryLog $log,
        private readonly EntitlementGate $gate,
        private readonly UsageCounter $usage,
        private readonly EventRegistry $events,
        private readonly MessageComposer $composer,
        private readonly TenantContext $tenants,
    ) {
        foreach ($senders as $sender) {
            $this->senders[$sender->channel()->value] = $sender;
        }
    }

    /** @return array{sent: int, failed: int, retried: int, skipped: int, blocked: int, held: int, abandoned: int} */
    public function run(?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $out = ['sent' => 0, 'failed' => 0, 'retried' => 0, 'skipped' => 0, 'blocked' => 0, 'held' => 0, 'abandoned' => 0];
        $out['abandoned'] = $this->abandonStale($now);

        $cfg = (array) config('notifications_v2.sender');
        $rows = DB::table('notification_outbox')->where('state', 'pending')
            ->where(fn ($q) => $q->whereNull('not_before')->orWhere('not_before', '<=', $now))
            ->orderByRaw("case priority when 'critical' then 0 when 'important' then 1 else 2 end")->orderBy('created_at')
            ->limit((int) ($cfg['batch_size'] ?? 200) * 3)->get();

        $perTenant = [];
        $taken = 0;
        foreach ($rows as $row) {
            if ($taken >= (int) ($cfg['batch_size'] ?? 200)) {
                break;
            }
            $tenant = (string) $row->tenant_id;
            if (($perTenant[$tenant] ?? 0) >= (int) ($cfg['per_tenant_per_run'] ?? 50)) {
                continue;
            }

            $won = DB::table('notification_outbox')->where('id', $row->id)->where('state', 'pending')
                ->update(['state' => 'claimed', 'attempts' => DB::raw('attempts + 1'), 'updated_at' => $now]);
            if ($won === 0) {
                continue;
            }
            $perTenant[$tenant] = ($perTenant[$tenant] ?? 0) + 1;
            $taken++;

            $before = $this->tenants->tenantId();
            $this->tenants->setTenantId($tenant);
            try {
                $outcome = $this->deliver(Delivery::fromRow(DB::table('notification_outbox')->where('id', $row->id)->first()), $now);
            } catch (Throwable $e) {
                report($e);
                $outcome = $this->finishFailure(Delivery::fromRow(DB::table('notification_outbox')->where('id', $row->id)->first()), SendResult::failed('exception: '.class_basename($e)), $now);
            } finally {
                $this->tenants->setTenantId($before);
            }
            $out[$outcome]++;
        }

        return $out;
    }

    /** @return 'sent'|'failed'|'retried'|'skipped'|'blocked'|'held' */
    private function deliver(Delivery $d, Carbon $now): string
    {
        $event = $this->events->get($d->eventKey);
        $keys = [$event->entitlement ?? ($d->scope === Scope::External ? EntitlementKey::External : EntitlementKey::Internal)];
        $keys[] = $d->scope === Scope::External ? EntitlementKey::External : EntitlementKey::Internal;
        if ($d->channel === Channel::Push) {
            $keys[] = EntitlementKey::Push;
        }
        foreach ($keys as $key) {
            if (! $this->gate->allows($d->tenantId, $key)) {
                return $this->finish($d, DeliveryStatus::BlockedByPlan, 'plan:'.$key->value, null, 'blocked');
            }
        }

        $limit = match (true) {
            $d->scope === Scope::External => ['monthly_messages', UsageCounter::EXTERNAL_MESSAGES],
            $d->channel === Channel::Push => ['monthly_push', UsageCounter::PUSH_NOTIFICATIONS],
            default => null,
        };
        if ($limit !== null) {
            $cap = $this->gate->limit($d->tenantId, $limit[0]);
            if ($cap !== null && $this->usage->current($d->tenantId, $limit[1], $now) >= $cap) {
                return $this->finish($d, DeliveryStatus::BlockedByPlan, 'limit:'.$limit[0], null, 'blocked');
            }
        }

        if ($d->scope === Scope::External && ! $this->externalStillActive($d)) {
            return $this->finish($d, DeliveryStatus::Skipped, 'recipient_not_active', null, 'skipped');
        }

        foreach ($this->checks as $check) {
            $verdict = $check->check($d);
            if ($verdict !== null) {
                return $this->finish($d, $verdict[0], $verdict[1], null, $verdict[0] === DeliveryStatus::Skipped ? 'skipped' : 'held');
            }
        }
        if ($d->scope === Scope::External && ! $this->composer->compose($d)->externalReady) {
            return $this->finish($d, DeliveryStatus::Skipped, 'external_template_not_installed', null, 'skipped');
        }

        $sender = $this->senders[$d->channel->value] ?? null;
        if ($sender === null) {
            return $this->finish($d, DeliveryStatus::Skipped, 'no_sender', null, 'skipped');
        }

        $result = $sender->send($d);
        if ($result->accepted) {
            if ($limit !== null) {
                $this->usage->increment($d->tenantId, $limit[1], 1, $now);
            }

            return $this->finish($d, DeliveryStatus::Sent, null, $result, 'sent');
        }
        if ($result->skipped) {
            return $this->finish($d, DeliveryStatus::Skipped, $result->reason, null, 'skipped');
        }

        return $this->finishFailure($d, $result, $now);
    }

    /** @return 'failed'|'retried' */
    private function finishFailure(Delivery $d, SendResult $result, Carbon $now): string
    {
        $max = (int) config('notifications_v2.sender.max_attempts', 3);
        if (! $result->permanent && $d->attempts < $max) {
            $backoff = (array) config('notifications_v2.sender.backoff_minutes', [5, 30]);
            $wait = (int) ($backoff[$d->attempts - 1] ?? end($backoff) ?: 30);
            DB::table('notification_outbox')->where('id', $d->id)->where('state', 'claimed')
                ->update(['state' => 'pending', 'not_before' => $now->copy()->addMinutes($wait), 'updated_at' => $now]);

            return 'retried';
        }

        $this->finish($d, DeliveryStatus::Failed, $result->reason ?? 'failed', null, 'failed');

        if ($d->scope === Scope::External && $d->projectId !== null) {
            app(NotificationEngine::class)->raise(new DeliveryIntent(
                eventKey: 'external.failed', tenantId: $d->tenantId, projectId: $d->projectId,
                objectType: 'delivery', objectId: $d->id, payload: ['recipient' => (string) $d->recipient->address, 'reference' => $d->reference],
            ));
        }

        return 'failed';
    }

    /**
     * @template T of string
     *
     * @param  T  $outcome
     * @return T
     */
    private function finish(Delivery $d, DeliveryStatus $status, ?string $reason, ?SendResult $result, string $outcome): string
    {
        DB::table('notification_outbox')->where('id', $d->id)->update(['state' => 'done', 'updated_at' => Carbon::now()]);
        $this->log->record($d, $status, $reason, $result);

        return $outcome;
    }

    private function externalStillActive(Delivery $d): bool
    {
        $recipient = DB::table('notification_external_recipients')->where('id', $d->recipient->externalId)->where('tenant_id', $d->tenantId)->value('status');
        if ($recipient !== 'active') {
            return false;
        }

        return $d->subscriptionId === null
            || DB::table('notification_subscriptions')->where('id', $d->subscriptionId)->where('tenant_id', $d->tenantId)->value('status') === 'active';
    }

    private function abandonStale(Carbon $now): int
    {
        $limit = $now->copy()->subMinutes((int) config('notifications_v2.sender.claim_timeout_minutes', 15));
        $stale = DB::table('notification_outbox')->where('state', 'claimed')->where('updated_at', '<', $limit)->limit(500)->get();

        foreach ($stale as $row) {
            if (DB::table('notification_outbox')->where('id', $row->id)->where('state', 'claimed')->update(['state' => 'done', 'updated_at' => $now]) === 1) {
                $this->log->record(Delivery::fromRow($row), DeliveryStatus::Failed, 'abandoned_after_claim');
            }
        }

        return $stale->count();
    }
}
