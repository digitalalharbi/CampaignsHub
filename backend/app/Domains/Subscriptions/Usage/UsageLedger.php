<?php

declare(strict_types=1);

namespace App\Domains\Subscriptions\Usage;

use App\Domains\Subscriptions\Notifications\SubscriptionNotifier;
use App\Domains\Subscriptions\Services\SubscriptionService;
use App\Domains\Tenancy\Models\Tenant;
use App\Support\Frontend;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * PRICING-USAGE-CREDITS-001 — the one place metered usage is recorded and credits move.
 *
 * - **Idempotent.** Every act carries a key unique in the table; recording it twice (a retried job, a double click) returns the
 *   first row and charges nothing more. Credit movements are keyed the same way.
 * - **Atomic.** The event, the credit it takes and the balance after it are written in one transaction under the workspace's
 *   credit row lock, so two concurrent acts can never spend the same riyal.
 * - **Actual cost, and only the platform's.** A unit costs what the operator entered for the meter, captured on the row. Usage
 *   on the customer's own AI key is recorded (they see it; their own cap is enforced on it) and NEVER takes credits.
 * - **Never negative, never blocking.** Usage beyond the balance is recorded as `uncovered`; nothing the customer is doing is
 *   stopped by this ledger — whether to stop is the caller's decision (the AI key's own cap is).
 * - **Alerts once.** 75/90/100% of a monthly cap is told to the owner once per month per meter (and per key), through the
 *   subscription notifier, after the transaction commits.
 */
final class UsageLedger
{
    public const THRESHOLDS = [75, 90, 100];

    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly SubscriptionNotifier $notifier,
    ) {}

    /**
     * Record one metered act. Returns null for an unknown or inactive meter, or a non-positive quantity — metering must never
     * break the act it measures.
     *
     * @param  int|null  $cap  an explicit monthly cap for this scope (the customer's own key); otherwise the plan's allowance
     */
    public function record(
        string $tenantId,
        string $meterCode,
        int $quantity,
        string $idempotencyKey,
        ?string $scope = null,
        ?int $cap = null,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?Carbon $at = null,
    ): ?UsageEvent {
        if ($quantity <= 0) {
            return null;
        }
        $meter = UsageMeter::query()->where('code', $meterCode)->where('is_active', true)->first();
        if ($meter === null) {
            return null;
        }
        $at ??= Carbon::now();

        $alert = null;
        $event = DB::transaction(function () use ($tenantId, $meter, $quantity, $idempotencyKey, $scope, $cap, $referenceType, $referenceId, $at, &$alert): UsageEvent {
            $unitCost = $meter->funded_by === UsageMeter::PLATFORM && $meter->unit_cost !== null ? (string) $meter->unit_cost : null;
            $cost = $unitCost === null ? '0' : bcmul((string) $quantity, $unitCost, 4);

            $inserted = DB::table('usage_events')->insertOrIgnore([
                'tenant_id' => $tenantId, 'meter_code' => $meter->code, 'scope' => $scope, 'quantity' => $quantity,
                'funded_by' => $meter->funded_by, 'unit_cost' => $unitCost, 'cost' => $cost, 'credits_charged' => 0, 'uncovered' => 0,
                'currency' => $meter->currency, 'idempotency_key' => $idempotencyKey, 'reference_type' => $referenceType,
                'reference_id' => $referenceId, 'occurred_at' => $at, 'created_at' => Carbon::now(),
            ]);
            /** @var UsageEvent $event */
            $event = UsageEvent::query()->where('idempotency_key', $idempotencyKey)->firstOrFail();
            if ($inserted === 0) {
                return $event; // already recorded: nothing more is charged or alerted
            }

            if ($meter->charges() && bccomp($cost, '0', 4) > 0) {
                $balance = $this->lockedBalance($tenantId, $meter->currency);
                $covered = bccomp($balance, $cost, 4) >= 0 ? $cost : $balance;
                if (bccomp($covered, '0', 4) > 0) {
                    $after = bcsub($balance, $covered, 4);
                    DB::table('credit_accounts')->where('tenant_id', $tenantId)->update(['balance' => $after, 'updated_at' => Carbon::now()]);
                    CreditTransaction::query()->create([
                        'tenant_id' => $tenantId, 'kind' => 'consume', 'amount' => bcmul($covered, '-1', 4), 'balance_after' => $after,
                        'currency' => $meter->currency, 'idempotency_key' => 'usage:'.$event->id, 'usage_event_id' => $event->id,
                        'reason' => $meter->code,
                    ]);
                }
                $event->forceFill(['credits_charged' => $covered, 'uncovered' => bcsub($cost, $covered, 4)])->save();
            }

            $alert = $this->crossedThreshold($tenantId, $meter, $scope, $cap, $quantity, $at);

            return $event;
        });

        if ($alert !== null) {
            $this->tell($tenantId, $meter, $scope, $alert);
        }

        return $event;
    }

    /**
     * Add (positive) or take back (negative) credits by hand. Idempotent on `$idempotencyKey`; refuses to go below zero.
     */
    public function adjust(string $tenantId, string $amount, string $reason, ?int $actorId, string $idempotencyKey, string $currency = 'SAR'): CreditTransaction
    {
        if (! is_numeric($amount) || bccomp($amount, '0', 4) === 0) {
            throw new InvalidArgumentException('credits_amount_invalid');
        }

        return DB::transaction(function () use ($tenantId, $amount, $reason, $actorId, $idempotencyKey, $currency): CreditTransaction {
            $existing = CreditTransaction::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                return $existing;
            }
            $balance = $this->lockedBalance($tenantId, $currency);
            $after = bcadd($balance, $amount, 4);
            if (bccomp($after, '0', 4) < 0) {
                throw new InvalidArgumentException('credits_balance_negative');
            }
            DB::table('credit_accounts')->where('tenant_id', $tenantId)->update(['balance' => $after, 'updated_at' => Carbon::now()]);

            return CreditTransaction::query()->create([
                'tenant_id' => $tenantId, 'kind' => bccomp($amount, '0', 4) > 0 ? 'grant' : 'adjust', 'amount' => bcadd($amount, '0', 4),
                'balance_after' => $after, 'currency' => $currency, 'idempotency_key' => $idempotencyKey, 'reason' => $reason,
                'actor_id' => $actorId,
            ]);
        });
    }

    public function balance(string $tenantId): string
    {
        return bcadd((string) (DB::table('credit_accounts')->where('tenant_id', $tenantId)->value('balance') ?? '0'), '0', 2);
    }

    /** Units of a meter used in the calendar month of `$at` (UTC), optionally for one scope. */
    public function usedThisMonth(string $tenantId, string $meterCode, ?string $scope = null, ?Carbon $at = null): int
    {
        $at ??= Carbon::now();

        return (int) UsageEvent::query()
            ->where('tenant_id', $tenantId)->where('meter_code', $meterCode)
            ->when($scope !== null, fn ($q) => $q->where('scope', $scope))
            ->where('occurred_at', '>=', $at->copy()->utc()->startOfMonth())
            ->where('occurred_at', '<', $at->copy()->utc()->startOfMonth()->addMonth())
            ->sum('quantity');
    }

    /**
     * What a workspace has used this month, meter by meter, with the plan allowance, what it cost and what credits paid for.
     *
     * @return list<array<string, mixed>>
     */
    public function summary(Tenant $tenant): array
    {
        $tenantId = (string) $tenant->getKey();
        $plan = $this->subscriptions->currentPlan($tenant)?->code;
        $start = Carbon::now()->utc()->startOfMonth();

        return UsageMeter::query()->where('is_active', true)->orderBy('sort_order')->get()
            ->map(function (UsageMeter $meter) use ($tenantId, $plan, $start): array {
                $rows = UsageEvent::query()->where('tenant_id', $tenantId)->where('meter_code', $meter->code)->where('occurred_at', '>=', $start);

                return [
                    'code' => $meter->code, 'name' => $meter->name, 'name_ar' => $meter->name_ar, 'unit' => $meter->unit,
                    'funded_by' => $meter->funded_by, 'charges_credits' => $meter->charges(),
                    'unit_cost' => $meter->unit_cost === null ? null : (string) $meter->unit_cost, 'currency' => $meter->currency,
                    'used' => (int) (clone $rows)->sum('quantity'),
                    'included' => $meter->includedFor($plan),
                    'cost' => bcadd((string) ((clone $rows)->sum('cost') ?: '0'), '0', 2),
                    'credits_charged' => bcadd((string) ((clone $rows)->sum('credits_charged') ?: '0'), '0', 2),
                    'uncovered' => bcadd((string) ((clone $rows)->sum('uncovered') ?: '0'), '0', 2),
                ];
            })->values()->all();
    }

    /** The workspace's balance, row-locked for the rest of the transaction; the account is opened at zero when missing. */
    private function lockedBalance(string $tenantId, string $currency): string
    {
        DB::table('credit_accounts')->insertOrIgnore([
            'tenant_id' => $tenantId, 'balance' => 0, 'currency' => $currency, 'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
        ]);

        return (string) DB::table('credit_accounts')->where('tenant_id', $tenantId)->lockForUpdate()->value('balance');
    }

    /**
     * The highest threshold this act newly crossed, recorded once per month; null when none.
     *
     * @return array{threshold: int, used: int, cap: int, own_key: bool}|null
     */
    private function crossedThreshold(string $tenantId, UsageMeter $meter, ?string $scope, ?int $cap, int $quantity, Carbon $at): ?array
    {
        if ($cap === null) {
            $tenant = Tenant::query()->find($tenantId);
            $cap = $tenant === null ? null : $meter->includedFor($this->subscriptions->currentPlan($tenant)?->code);
        }
        if ($cap === null || $cap <= 0) {
            return null;
        }
        $used = $this->usedThisMonth($tenantId, $meter->code, $scope, $at);
        $period = $at->copy()->utc()->format('Y-m');
        $newest = null;
        foreach (self::THRESHOLDS as $threshold) {
            if ($used * 100 < $cap * $threshold) {
                break;
            }
            $inserted = DB::table('usage_alerts')->insertOrIgnore([
                'tenant_id' => $tenantId, 'meter_code' => $meter->code, 'scope' => $scope ?? '', 'period' => $period,
                'threshold' => $threshold, 'used' => $used, 'cap' => $cap, 'created_at' => Carbon::now(),
            ]);
            if ($inserted === 1) {
                $newest = $threshold;
            }
        }

        return $newest === null ? null : ['threshold' => $newest, 'used' => $used, 'cap' => $cap, 'own_key' => $meter->funded_by === UsageMeter::CUSTOMER];
    }

    /** @param  array{threshold: int, used: int, cap: int, own_key: bool}  $alert */
    private function tell(string $tenantId, UsageMeter $meter, ?string $scope, array $alert): void
    {
        $tenant = Tenant::query()->find($tenantId);
        if ($tenant === null) {
            return;
        }
        try {
            $this->notifier->notifyTenant($tenant, 'usage_threshold', [
                'meter' => $meter->name, 'meter_ar' => $meter->name_ar, 'threshold' => $alert['threshold'], 'used' => $alert['used'], 'cap' => $alert['cap'],
                'own_key' => $alert['own_key'], 'url' => Frontend::origin().'/app/subscriptions',
            ], occasion: implode(':', [$tenantId, $meter->code, $scope ?? '', Carbon::now()->utc()->format('Y-m'), $alert['threshold']]));
        } catch (Throwable $e) {
            report($e); // the usage is recorded; a failed notice must not undo it
        }
    }
}
