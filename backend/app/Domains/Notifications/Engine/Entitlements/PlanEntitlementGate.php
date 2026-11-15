<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Entitlements;

use App\Domains\Notifications\Engine\Contracts\EntitlementGate;
use App\Domains\Notifications\Engine\Contracts\WorkspaceTypes;
use App\Domains\Notifications\Engine\Data\EntitlementState;
use App\Domains\Notifications\Engine\Enums\EntitlementKey;
use App\Domains\Notifications\Engine\Enums\EntitlementMode;
use App\Domains\Notifications\Engine\Exceptions\EntitlementBlocked;
use App\Domains\Subscriptions\Services\SubscriptionService;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Server-side plan entitlement (spec §1): free now, priceable later with no code change.
 *
 * ## Resolution, in order
 *
 * 1. `workspace_entitlement_states` — an explicit state for this workspace (admin decision, or a recorded transition):
 *    `grandfathered` → usable; `grace` → usable until `grace_ends_at`, then view-only; `view_only` → view-only.
 * 2. The most specific `notification_plan_features` row: (plan, type) → (plan, any) → (any, type) → (any, any).
 * 3. `config('notifications_v2.entitlements.defaults')` — every key on except removing the footprint.
 *
 * ## «Never break» when a key becomes paid
 *
 * A row switched off WITH `paid_from` set describes a key that used to be free. A workspace that existed before
 * `paid_from` is not cut off: `keep_existing` → grandfathered; otherwise grace for `grace_days`, then view-only (data
 * and settings kept and readable, nothing new sent, edits refused, «ترقية»). A row switched off with no `paid_from`
 * is a plain «off» (e.g. the footprint removal, which no workspace ever held).
 *
 * ## Limits
 *
 * Config default ← the row's `limits` ← `tenant_limit_overrides` metric `notif.<limit>` (unexpired). null = unlimited.
 */
final class PlanEntitlementGate implements EntitlementGate
{
    /** @var array<string, EntitlementState> */
    private array $memo = [];

    /** @var array<string, string|null> */
    private array $planCodes = [];

    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly WorkspaceTypes $types,
    ) {}

    public function state(string $tenantId, EntitlementKey $key): EntitlementState
    {
        return $this->memo[$tenantId.'|'.$key->value] ??= $this->resolve($tenantId, $key);
    }

    public function allows(string $tenantId, EntitlementKey $key): bool
    {
        return $this->state($tenantId, $key)->allows();
    }

    public function limit(string $tenantId, string $limitKey): ?int
    {
        $owner = (string) (config('notifications_v2.entitlements.limit_keys')[$limitKey] ?? EntitlementKey::External->value);
        $key = EntitlementKey::tryFrom($owner) ?? EntitlementKey::External;
        $limits = $this->state($tenantId, $key)->limits;

        return array_key_exists($limitKey, $limits) ? $limits[$limitKey] : null;
    }

    public function assertAllows(string $tenantId, EntitlementKey $key): void
    {
        $state = $this->state($tenantId, $key);

        if (! $state->allows()) {
            throw new EntitlementBlocked($state);
        }
    }

    /** Forget memoised answers (after an admin edit, or between test steps). */
    public function flush(): void
    {
        $this->memo = [];
        $this->planCodes = [];
    }

    private function resolve(string $tenantId, EntitlementKey $key): EntitlementState
    {
        $now = Carbon::now();
        $row = $this->planRow($tenantId, $key);
        $limits = $this->limits($tenantId, $key, $row);

        $explicit = DB::table('workspace_entitlement_states')->where('tenant_id', $tenantId)->where('key', $key->value)->first();
        if ($explicit !== null) {
            $graceEnds = $explicit->grace_ends_at === null ? null : Carbon::parse((string) $explicit->grace_ends_at);

            return match ((string) $explicit->mode) {
                'grandfathered' => new EntitlementState($key, EntitlementMode::Grandfathered, $limits),
                'grace' => $graceEnds !== null && $graceEnds->gt($now)
                    ? new EntitlementState($key, EntitlementMode::Grace, $limits, $graceEnds)
                    : new EntitlementState($key, EntitlementMode::ViewOnly, $limits, $graceEnds),
                default => new EntitlementState($key, EntitlementMode::ViewOnly, $limits, $graceEnds),
            };
        }

        $enabled = $row !== null
            ? (bool) $row->enabled
            : (bool) (config('notifications_v2.entitlements.defaults')[$key->value] ?? false);

        if ($enabled) {
            return new EntitlementState($key, EntitlementMode::Full, $limits);
        }

        if ($row === null || $row->paid_from === null) {
            return new EntitlementState($key, EntitlementMode::Off, $limits);
        }

        $paidFrom = Carbon::parse((string) $row->paid_from);
        $createdAt = DB::table('tenants')->where('id', $tenantId)->value('created_at');
        $existedBefore = $createdAt !== null && Carbon::parse((string) $createdAt)->lt($paidFrom);

        if (! $existedBefore) {
            return new EntitlementState($key, EntitlementMode::Off, $limits);
        }
        if ((bool) $row->keep_existing) {
            return new EntitlementState($key, EntitlementMode::Grandfathered, $limits);
        }

        $graceEnds = $paidFrom->copy()->addDays((int) $row->grace_days);

        return $graceEnds->gt($now)
            ? new EntitlementState($key, EntitlementMode::Grace, $limits, $graceEnds)
            : new EntitlementState($key, EntitlementMode::ViewOnly, $limits, $graceEnds);
    }

    private function planRow(string $tenantId, EntitlementKey $key): ?object
    {
        $plan = $this->planCode($tenantId);
        $type = $this->types->profileFor($tenantId)->key;

        $rows = DB::table('notification_plan_features')->where('key', $key->value)
            ->where(fn ($q) => $q->whereNull('plan_code')->orWhere('plan_code', $plan))
            ->where(fn ($q) => $q->whereNull('workspace_type')->orWhere('workspace_type', $type))
            ->get();

        return $rows->sortByDesc(static fn (object $r): int => ($r->plan_code !== null ? 2 : 0) + ($r->workspace_type !== null ? 1 : 0))->first();
    }

    private function planCode(string $tenantId): ?string
    {
        if (! array_key_exists($tenantId, $this->planCodes)) {
            $tenant = Tenant::query()->find($tenantId);
            $this->planCodes[$tenantId] = $tenant === null ? null : $this->subscriptions->currentPlan($tenant)?->code;
        }

        return $this->planCodes[$tenantId];
    }

    /** @return array<string, int|null> */
    private function limits(string $tenantId, EntitlementKey $key, ?object $row): array
    {
        $owners = (array) config('notifications_v2.entitlements.limit_keys', []);
        $defaults = (array) config('notifications_v2.entitlements.limits', []);
        $fromRow = $row === null || $row->limits === null ? [] : (is_string($row->limits) ? (array) json_decode($row->limits, true) : (array) $row->limits);

        $limits = [];
        foreach ($defaults as $limitKey => $value) {
            if (($owners[$limitKey] ?? null) !== $key->value) {
                continue;
            }
            $limits[(string) $limitKey] = array_key_exists($limitKey, $fromRow) ? ($fromRow[$limitKey] === null ? null : (int) $fromRow[$limitKey]) : ($value === null ? null : (int) $value);

            $override = DB::table('tenant_limit_overrides')->where('tenant_id', $tenantId)->where('metric', 'notif.'.$limitKey)
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', Carbon::now()))->first();
            if ($override !== null) {
                $limits[(string) $limitKey] = $override->limit_value === null ? null : (int) $override->limit_value;
            }
        }

        return $limits;
    }
}
