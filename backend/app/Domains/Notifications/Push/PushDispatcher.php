<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Push;

use App\Domains\Notifications\Models\AppNotification;
use App\Domains\Notifications\Services\AlertQuietHours;
use App\Domains\Tenancy\Context\TenantContext;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * NOTIF-PUSH-001 — sends the pushes for one notification, and holds the ones that must wait.
 *
 * Order, per person and per device:
 *
 *   1. quiet hours (default 22:00–08:00 on their clock) → the push is HELD, and goes out afterwards as one summary;
 *   2. the hourly ceiling → the same: held for the summary, never dropped silently;
 *   3. otherwise sent now, to every device they registered. A 404 or 410 from the push service deletes that device at once.
 *
 * A failure on one device never touches another, the bell, or the sweep that raised the notification.
 */
final class PushDispatcher
{
    /** Whether any device exists at all — asked once per instance, so a sweep that raises 400 alerts asks the table once. */
    private ?bool $anyDevice = null;

    public function __construct(
        private readonly PushTransport $transport,
        private readonly PushAudience $audience,
        private readonly AlertQuietHours $quiet,
    ) {}

    /** Cheap, in-memory: could this notification ring a phone at all? The queue is only touched when it could. */
    public function wants(AppNotification $n): bool
    {
        return PushTypes::of($n) !== null && $this->transport->configured() && $this->anyDevice();
    }

    /**
     * @return array{sent: int, held: int, removed: int, skipped: int}
     */
    public function send(AppNotification $n, ?Carbon $at = null): array
    {
        return $this->inTenant((string) $n->tenant_id, fn (): array => $this->sendNow($n, $at));
    }

    /**
     * @return array{sent: int, held: int, removed: int, skipped: int}
     */
    private function sendNow(AppNotification $n, ?Carbon $at): array
    {
        $out = ['sent' => 0, 'held' => 0, 'removed' => 0, 'skipped' => 0];
        $type = PushTypes::of($n);
        if ($type === null || ! $this->transport->configured()) {
            return $out;
        }

        $at ??= Carbon::now();
        $tenantId = (string) $n->tenant_id;

        foreach ($this->audience->for($n, $type) as $user) {
            // An alert that was cleared or closed before its push went out is not worth a phone's attention.
            if (! $this->stillWorthSending($n, $user)) {
                $out['skipped']++;

                continue;
            }

            $reason = $this->mustWait($user, $tenantId, $at);
            if ($reason !== null) {
                $this->hold($n, $user, $reason);
                $out['held']++;

                continue;
            }

            $payload = PushPayload::for($n, ($user->locale ?? 'ar') !== 'en', $this->audience->prefixFor($user, $tenantId));
            $r = $this->deliver($user, $payload, $type === PushTypes::CRITICAL ? 'high' : 'normal');
            $out['sent'] += $r['sent'];
            $out['removed'] += $r['removed'];
            if ($r['sent'] > 0) {
                DB::table('push_ledger')->insert(['user_id' => $user->getKey(), 'sent_at' => $at]);
            }
        }

        return $out;
    }

    /**
     * The one summary for a person whose held pushes may now go out. Returns how many held notifications it covered.
     *
     * @return array{covered: int, sent: int}
     */
    public function flushFor(User $user, string $tenantId, ?Carbon $at = null): array
    {
        return $this->inTenant($tenantId, fn (): array => $this->flushNow($user, $tenantId, $at));
    }

    /**
     * @return array{covered: int, sent: int}
     */
    private function flushNow(User $user, string $tenantId, ?Carbon $at): array
    {
        $at ??= Carbon::now();
        if ($this->quiet->isQuiet((int) $user->getKey(), $tenantId, $at)) {
            return ['covered' => 0, 'sent' => 0];
        }

        $held = DB::table('push_deferred')->where('user_id', $user->getKey())->where('tenant_id', $tenantId)->get(['id', 'notification_id']);
        if ($held->isEmpty()) {
            return ['covered' => 0, 'sent' => 0];
        }

        // Only what is still open: a notification read or closed while it waited does not count toward «you have N».
        $open = AppNotification::withoutGlobalScopes()->whereIn('id', $held->pluck('notification_id'))->where('status', 'unread')->count();
        DB::table('push_deferred')->whereIn('id', $held->pluck('id'))->delete();
        if ($open === 0) {
            return ['covered' => 0, 'sent' => 0];
        }

        $payload = PushPayload::summary($open, ($user->locale ?? 'ar') !== 'en', $this->audience->prefixFor($user, $tenantId));

        return ['covered' => $open, 'sent' => $this->deliver($user, $payload, 'normal')['sent']];
    }

    /**
     * Run with the notification's tenant bound.
     *
     * A queue worker and the scheduler have no tenant, and `TenantScope` FAILS CLOSED without one — the membership lookups below
     * would return nothing and every push would be silently skipped. The tenant that was bound before is put back.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function inTenant(string $tenantId, callable $work): mixed
    {
        $context = app(TenantContext::class);
        $before = $context->tenantId();
        $context->setTenantId($tenantId);
        try {
            return $work();
        } finally {
            $context->setTenantId($before);
        }
    }

    /** A harmless test message to ONE device the person owns («جرّب الإشعار»). */
    public function test(PushSubscription $device, bool $ar, string $portalPrefix): PushResult
    {
        $result = $this->transport->send($device, (string) json_encode(PushPayload::test($ar, $portalPrefix), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ['urgency' => 'normal', 'ttl' => 300]);
        $this->record($device, $result);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{sent: int, removed: int}
     */
    private function deliver(User $user, array $payload, string $urgency): array
    {
        $json = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $sent = 0;
        $removed = 0;

        foreach (PushSubscription::query()->where('user_id', $user->getKey())->get() as $device) {
            $result = $this->transport->send($device, $json, ['urgency' => $urgency, 'topic' => substr((string) $payload['tag'], 0, 32)]);
            $this->record($device, $result);
            $result->ok ? $sent++ : null;
            $removed += $result->gone ? 1 : 0;
        }

        return ['sent' => $sent, 'removed' => $removed];
    }

    /** Success stamps the device; 404 / 410 removes it; any other failure is counted and logged without the endpoint. */
    private function record(PushSubscription $device, PushResult $result): void
    {
        if ($result->gone) {
            $device->delete();

            return;
        }
        if ($result->ok) {
            $device->forceFill(['last_used_at' => now(), 'failure_count' => 0])->save();

            return;
        }
        $device->forceFill(['last_failure_at' => now(), 'failure_count' => ((int) $device->failure_count) + 1])->save();
        Log::warning('Web push was not delivered.', ['device' => (string) $device->id, 'status' => $result->status, 'reason' => $result->reason]);
    }

    private function mustWait(User $user, string $tenantId, Carbon $at): ?string
    {
        if ($this->quiet->isQuiet((int) $user->getKey(), $tenantId, $at)) {
            return 'quiet_hours';
        }

        $recent = DB::table('push_ledger')->where('user_id', $user->getKey())->where('sent_at', '>=', $at->copy()->subHour())->count();

        return $recent >= (int) config('webpush.ceiling_per_hour', 6) ? 'ceiling' : null;
    }

    private function hold(AppNotification $n, User $user, string $reason): void
    {
        try {
            DB::table('push_deferred')->insertOrIgnore([
                'id' => (string) Str::uuid(), 'tenant_id' => $n->tenant_id, 'user_id' => $user->getKey(),
                'notification_id' => $n->id, 'reason' => $reason, 'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function stillWorthSending(AppNotification $n, User $user): bool
    {
        $fresh = AppNotification::withoutGlobalScopes()->whereKey($n->id)->first(['status']);

        return $fresh !== null && $fresh->status === 'unread' && DB::table('notification_reads')->where('notification_id', $n->id)->where('user_id', $user->getKey())->doesntExist();
    }

    private function anyDevice(): bool
    {
        return $this->anyDevice ??= DB::table('push_subscriptions')->exists();
    }
}
