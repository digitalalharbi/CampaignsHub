<?php

declare(strict_types=1);

namespace App\Domains\Subscriptions\Seats;

use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Subscriptions\Exceptions\PlanLimitReached;
use App\Domains\Subscriptions\Services\SubscriptionService;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * PRICING-SEATS-001 — ad-account seats: what a workspace may connect, and how a seat is reused.
 *
 * - A seat is the real ad account: (provider, external id). The same account reached through a second connection, re-consented
 *   or moved between projects is the SAME seat and costs nothing.
 * - Releasing an account (detaching it, deleting its project, revoking its connection) does not hand the seat to a different
 *   account at once: a released seat counts for 30 days. A different account may take its place only through the plan's
 *   replacement allowance (`limits.ad_account_replacements` per 30 days — Solo 1, Team 3, Agency 10; none set = unlimited).
 * - Renewal resets nothing: seats are state, not a monthly counter.
 * - Releasing, revoking and deleting are never limited — only connecting a DIFFERENT account is.
 *
 * Every call runs inside the caller's transaction, after it has locked the tenant row, so concurrent confirmations serialise.
 * Seats are reconciled from the bindings each time rather than hooked into every place a binding is switched off: those are mass
 * updates in eight places and would miss events silently. History (`ad_account_seat_events`) is never deleted.
 */
final class AdAccountSeats
{
    public const WINDOW_DAYS = 30;

    public function __construct(private readonly SubscriptionService $subscriptions) {}

    /** Bring the seats in line with the active bindings: new active accounts get a seat; ones no longer bound are released. */
    public function reconcile(Tenant $tenant, ?int $actorId = null): void
    {
        $tenantId = (string) $tenant->getKey();
        $now = Carbon::now();
        $active = $this->activeIdentities($tenantId);
        $seats = AdAccountSeat::query()->where('tenant_id', $tenantId)->get()->keyBy(fn (AdAccountSeat $s) => $s->provider."\0".$s->external_id);

        foreach ($active as $key => [$provider, $externalId]) {
            $seat = $seats->get($key);
            if ($seat === null) {
                $seat = AdAccountSeat::query()->create([
                    'tenant_id' => $tenantId, 'provider' => $provider, 'external_id' => $externalId, 'status' => 'active',
                    'first_bound_at' => $now, 'last_bound_at' => $now,
                ]);
                $this->event($seat, 'claimed', $actorId);
            } elseif ($seat->status !== 'active') {
                $seat->forceFill(['status' => 'active', 'last_bound_at' => $now, 'released_at' => null])->save();
                $this->event($seat, 'reactivated', $actorId);
            }
        }

        foreach ($seats as $key => $seat) {
            if ($seat->status === 'active' && ! $active->has($key)) {
                $seat->forceFill(['status' => 'released', 'released_at' => $now])->save();
                $this->event($seat, 'released', $actorId);
            }
        }
    }

    /**
     * May these accounts be connected now? Throws PlanLimitReached (metric `ad_accounts` or `ad_account_replacements`) before
     * anything is written; otherwise records each new seat and any replacement it used. Call under the tenant row lock.
     *
     * @param  Collection<int, ExternalAccount>  $accounts  the accounts about to be bound
     */
    public function claim(Tenant $tenant, Collection $accounts, int $requested, ?int $actorId = null): void
    {
        $this->reconcile($tenant, $actorId);

        $tenantId = (string) $tenant->getKey();
        $cap = $this->subscriptions->effectiveLimit($tenant, 'ad_accounts');
        $allowance = $this->subscriptions->effectiveLimit($tenant, 'ad_account_replacements');
        $since = Carbon::now()->subDays(self::WINDOW_DAYS);

        $seats = AdAccountSeat::query()->where('tenant_id', $tenantId)->get()->keyBy(fn (AdAccountSeat $s) => $s->provider."\0".$s->external_id);
        $activeCount = $seats->where('status', 'active')->count();
        $holding = $seats->filter(fn (AdAccountSeat $s) => $s->status === 'released' && $s->replaced_at === null && $s->released_at !== null && $s->released_at->greaterThan($since))
            ->sortBy('released_at')->values();
        $replacementsUsed = DB::table('ad_account_seat_events')->where('tenant_id', $tenantId)->where('type', 'replaced')
            ->where('occurred_at', '>', $since)->count();

        $plan = [];
        foreach ($accounts->filter(fn (ExternalAccount $a) => $a->account_type === 'ad_account')->unique(fn (ExternalAccount $a) => $a->provider."\0".$a->external_id) as $account) {
            $key = $account->provider."\0".$account->external_id;
            $seat = $seats->get($key);
            if ($seat !== null && $seat->status === 'active') {
                continue; // the same account, already held: free
            }
            if ($cap !== null && $activeCount >= $cap) {
                throw $this->overCap($activeCount, $cap, $requested);
            }
            $activeCount++;
            if ($seat !== null) {
                $plan[] = ['reactivate', $seat, $account];

                continue; // the same account coming back is never a replacement
            }
            if ($cap !== null && $activeCount + $holding->count() > $cap) {
                if ($allowance !== null && $replacementsUsed >= $allowance) {
                    throw $this->noReplacementLeft($tenantId, $allowance, $requested);
                }
                $replacementsUsed++;
                $plan[] = ['replace', $holding->shift(), $account];

                continue;
            }
            $plan[] = ['new', null, $account];
        }

        $now = Carbon::now();
        foreach ($plan as [$kind, $seat, $account]) {
            if ($kind === 'reactivate') {
                $seat->forceFill(['status' => 'active', 'last_bound_at' => $now, 'released_at' => null])->save();
                $this->event($seat, 'reactivated', $actorId);

                continue;
            }
            if ($kind === 'replace' && $seat instanceof AdAccountSeat) {
                $seat->forceFill(['replaced_at' => $now])->save();
            }
            $new = AdAccountSeat::query()->create([
                'tenant_id' => $tenantId, 'provider' => $account->provider, 'external_id' => $account->external_id, 'status' => 'active',
                'first_bound_at' => $now, 'last_bound_at' => $now,
            ]);
            $this->event($new, $kind === 'replace' ? 'replaced' : 'claimed', $actorId);
        }
    }

    /**
     * What the workspace holds, for the console and the usage card.
     *
     * @return array{active: int, holding: int, replacements_used: int, replacements_allowed: int|null, next_replacement_at: string|null}
     */
    public function summary(Tenant $tenant): array
    {
        $tenantId = (string) $tenant->getKey();
        $since = Carbon::now()->subDays(self::WINDOW_DAYS);
        $allowance = $this->subscriptions->effectiveLimit($tenant, 'ad_account_replacements');
        $used = DB::table('ad_account_seat_events')->where('tenant_id', $tenantId)->where('type', 'replaced')->where('occurred_at', '>', $since);

        return [
            'active' => AdAccountSeat::query()->where('tenant_id', $tenantId)->where('status', 'active')->count(),
            'holding' => AdAccountSeat::query()->where('tenant_id', $tenantId)->where('status', 'released')->whereNull('replaced_at')->where('released_at', '>', $since)->count(),
            'replacements_used' => (clone $used)->count(),
            'replacements_allowed' => $allowance,
            'next_replacement_at' => $this->nextReplacementAt($tenantId, $allowance),
        ];
    }

    /** @return Collection<string, array{0: string, 1: string}> keyed "provider\0external_id" */
    private function activeIdentities(string $tenantId): Collection
    {
        return DB::table('project_integration_bindings')
            ->join('external_accounts', 'external_accounts.id', '=', 'project_integration_bindings.external_account_id')
            ->where('project_integration_bindings.tenant_id', $tenantId)
            ->where('project_integration_bindings.is_active', true)
            ->where('external_accounts.account_type', 'ad_account')
            ->select('external_accounts.provider', 'external_accounts.external_id')->distinct()->get()
            ->mapWithKeys(fn ($r) => [$r->provider."\0".$r->external_id => [(string) $r->provider, (string) $r->external_id]]);
    }

    private function event(AdAccountSeat $seat, string $type, ?int $actorId): void
    {
        DB::table('ad_account_seat_events')->insert([
            'tenant_id' => $seat->tenant_id, 'seat_id' => $seat->getKey(), 'type' => $type, 'provider' => $seat->provider,
            'external_id' => $seat->external_id, 'actor_id' => $actorId, 'occurred_at' => Carbon::now(),
        ]);
    }

    private function nextReplacementAt(string $tenantId, ?int $allowance): ?string
    {
        if ($allowance === null) {
            return null;
        }
        $since = Carbon::now()->subDays(self::WINDOW_DAYS);
        $events = DB::table('ad_account_seat_events')->where('tenant_id', $tenantId)->where('type', 'replaced')
            ->where('occurred_at', '>', $since)->orderBy('occurred_at')->pluck('occurred_at');
        if ($events->count() < $allowance) {
            return null;
        }

        return Carbon::parse((string) $events->first())->addDays(self::WINDOW_DAYS)->toIso8601String();
    }

    private function overCap(int $used, int $cap, int $requested): PlanLimitReached
    {
        return new PlanLimitReached('ad_accounts', $used, $cap, __('billing.ad_accounts_selection_exceeds_plan', [
            'requested' => $requested, 'remaining' => max(0, $cap - $used), 'limit' => $cap,
        ]), requested: $requested);
    }

    private function noReplacementLeft(string $tenantId, int $allowance, int $requested): PlanLimitReached
    {
        $next = $this->nextReplacementAt($tenantId, $allowance);

        return new PlanLimitReached('ad_account_replacements', $allowance, $allowance, __('billing.ad_account_replacements_used', [
            'allowance' => $allowance,
            'date' => $next === null ? '' : Carbon::parse($next)->format('Y-m-d'),
        ]), requested: $requested);
    }
}
