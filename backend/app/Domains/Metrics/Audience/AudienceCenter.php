<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Audience;

use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Services\BoundAccountVisibility;
use App\Domains\Reports\Support\ReportReadMemo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * AUDIENCE-001 — the unified audience reading of one project, four KINDS kept apart.
 *
 *   targeting      what each ad set was SET to reach (external_ad_sets.targeting) — a setting, not delivery
 *   paid_delivery  what the platform reported it DELIVERED, by dimension (audience_breakdown_rows)
 *   site_events    what the site measured (GA4 sessions by country / device) — never ad delivery
 *   organic        unpaid views/shares — not fetched by this product, stated as unavailable
 *
 * Rules held here, not left to a renderer:
 *   - nothing is summed across platforms: every delivery group is ONE provider and ONE dimension;
 *   - a share is numerator ÷ denominator of the SAME group and the same metric (impressions), both shown;
 *   - reach is a daily unique count: it is shown only for a one-day window, never added across days;
 *   - money stays per currency with its source; a client-facing reading carries no money at all and
 *     no campaign or ad-set names;
 *   - an absent breakdown is `unavailable` with a reason, never a zero.
 *
 * Every query is pinned to the tenant AND the project passed in.
 */
final class AudienceCenter
{
    public const KINDS = ['targeting', 'paid_delivery', 'site_events', 'organic'];

    /** @var list<string>|null the account ceiling of the build in progress */
    private ?array $accounts = null;

    /** @var list<string>|null the unified-campaign ceiling of the build in progress */
    private ?array $campaigns = null;

    private string $project = '';

    /** Only these keys of a stored targeting summary ever leave the server (an audience name or id list never does). */
    private const TARGETING_KEYS = ['countries', 'regions', 'postal_codes', 'radius_m', 'excluded_countries', 'excluded_regions', 'cities', 'locations', 'age_min', 'age_max', 'age_groups', 'genders', 'gender', 'languages', 'platforms', 'placements', 'product_type'];

    /**
     * @param  list<string>|null  $providers  a narrower link's platforms, or null for all
     * @param  list<string>|null  $accountIds  the ad accounts (our ids) a link names, or null for all bound accounts
     * @param  list<string>|null  $campaignIds  the UNIFIED campaigns a link or report is bounded to, or null for all
     * @return array<string, mixed>
     */
    public function build(string $tenantId, string $projectId, Carbon $from, Carbon $to, bool $clientFacing = false, bool $hideMoney = false, ?array $providers = null, ?array $accountIds = null, ?array $campaignIds = null): array
    {
        $this->accounts = $accountIds;
        $this->campaigns = $campaignIds;
        $this->project = $projectId;
        $period = ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => (int) $from->diffInDays($to) + 1];
        /** @var list<array{provider: string, account: string}> $bindings */
        $bindings = ReportReadMemo::remember('audience-bindings|'.$tenantId.'|'.$projectId, static fn (): array => DB::table('project_integration_bindings')
            ->where('tenant_id', $tenantId)->where('project_id', $projectId)
            ->where('is_active', true)->whereIn('provider', array_keys(AudienceCapabilities::all()))
            ->get(['provider', 'external_account_id'])
            ->map(static fn ($b): array => ['provider' => (string) $b->provider, 'account' => (string) $b->external_account_id])->all());
        $bound = array_values(array_unique(array_column(array_filter($bindings, static fn (array $b): bool => $accountIds === null || in_array($b['account'], $accountIds, true)), 'provider')));
        if ($providers !== null) {
            $bound = array_values(array_intersect($bound, $providers));
        }
        sort($bound);

        return [
            'period' => $period,
            'providers' => $bound,
            'cross_platform' => ['summed' => false, 'reason' => 'definitions_and_unique_reach_differ'],
            'targeting' => $this->targeting($tenantId, $projectId, $bound, $clientFacing),
            'paid_delivery' => $this->delivery($tenantId, $projectId, $bound, $period, $clientFacing || $hideMoney),
            'site_events' => $this->site($tenantId, $projectId, $period),
            'organic' => ['state' => 'unavailable', 'reason' => 'not_fetched'],
            'capabilities' => array_intersect_key(AudienceCapabilities::all(), array_flip($bound)),
        ];
    }

    /** @param list<string> $providers @return array<string, mixed> */
    private function targeting(string $tenantId, string $projectId, array $providers, bool $clientFacing): array
    {
        if ($providers === []) {
            return ['state' => 'unavailable', 'reason' => 'no_bound_platform', 'items' => []];
        }

        $rows = DB::table('external_ad_sets as s')
            ->join('external_campaigns as c', 'c.id', '=', 's.external_campaign_id')
            ->where('s.tenant_id', $tenantId)->where('s.project_id', $projectId)
            ->where('c.tenant_id', $tenantId)->where('c.project_id', $projectId)
            ->where('s.is_demo', false)->where('s.status', 'active')
            ->whereIn('s.provider', $providers)
            ->tap(fn ($q) => BoundAccountVisibility::apply($q, 'c'))
            ->when($this->accounts !== null, fn ($q) => $q->whereIn('c.external_account_id', $this->accounts))
            ->when($this->campaigns !== null, fn ($q) => $q->whereIn('c.unified_campaign_id', $this->campaigns))
            ->orderBy('s.provider')->orderBy('s.name')->limit(200)
            ->get(['s.id', 's.provider', 's.name', 's.targeting', 's.last_synced_at', 'c.name as campaign_name', 'c.external_account_id']);

        $items = [];
        foreach ($rows as $r) {
            $settings = json_decode((string) ($r->targeting ?? 'null'), true);
            $cap = AudienceCapabilities::for((string) $r->provider);
            $item = [
                'kind' => 'targeting',
                'provider' => (string) $r->provider,
                'level' => 'ad_set',
                'settings' => is_array($settings) ? (array_intersect_key($settings, array_flip(self::TARGETING_KEYS)) ?: null) : null,
                'not_stored' => $cap['targeting_lost'] ?? [],
                'synced_at' => $r->last_synced_at === null ? null : Carbon::parse((string) $r->last_synced_at)->toIso8601String(),
            ];
            if (! $clientFacing) {
                $item += ['ad_set_id' => (string) $r->id, 'ad_set' => (string) $r->name, 'campaign' => (string) $r->campaign_name, 'account_id' => (string) $r->external_account_id];
            }
            $items[] = $item;
        }

        return ['state' => $items === [] ? 'unavailable' : 'ready', 'reason' => $items === [] ? 'no_active_ad_set' : null, 'items' => $items];
    }

    /**
     * @param  list<string>  $providers
     * @param  array{from: string, to: string, days: int}  $period
     * @return array<string, mixed>
     */
    private function delivery(string $tenantId, string $projectId, array $providers, array $period, bool $noMoney): array
    {
        $rows = $providers === [] ? collect() : DB::table('audience_breakdown_rows')
            ->where('tenant_id', $tenantId)->where('project_id', $projectId)
            ->whereIn('provider', $providers)
            ->tap(fn ($q) => BoundAccountVisibility::apply($q, 'audience_breakdown_rows'))
            ->when($this->accounts !== null, fn ($q) => $q->whereIn('external_account_id', $this->accounts))
            ->when($this->campaigns !== null, fn ($q) => $q->whereIn('external_campaign_id', $this->campaignSubquery()))
            ->whereBetween('metric_date', [$period['from'], $period['to']])
            ->get(['provider', 'dimension', 'value', 'source_field', 'external_ad_set_id', 'metric_date', 'impressions', 'reach', 'clicks', 'spend_original', 'original_currency', 'currency_source', 'synced_at']);

        $groups = [];
        foreach ($rows->groupBy(static fn ($r): string => $r->provider.'|'.$r->dimension) as $key => $set) {
            [$provider, $dimension] = explode('|', (string) $key, 2);
            $impressionRows = $set->filter(static fn ($r): bool => $r->impressions !== null);
            $denominator = (float) $impressionRows->sum(static fn ($r): float => (float) $r->impressions);
            $oneDay = $period['days'] === 1;

            $values = [];
            foreach ($set->groupBy('value') as $value => $vs) {
                $num = $vs->filter(static fn ($r): bool => $r->impressions !== null);
                $n = $num->isEmpty() ? null : (float) $num->sum(static fn ($r): float => (float) $r->impressions);
                $clicks = $vs->filter(static fn ($r): bool => $r->clicks !== null);
                $row = [
                    'value' => (string) $value,
                    'impressions' => $n,
                    'share' => $n === null || $denominator <= 0 ? null : ['numerator' => $n, 'denominator' => $denominator, 'ratio' => round($n / $denominator, 6)],
                    'clicks' => $clicks->isEmpty() ? null : (float) $clicks->sum(static fn ($r): float => (float) $r->clicks),
                    // A unique count: one day AND one ad set only — never added across days or across ad sets (people overlap).
                    'reach' => $oneDay && $vs->pluck('external_ad_set_id')->unique()->count() === 1 && $vs->contains(static fn ($r): bool => $r->reach !== null)
                        ? (float) $vs->sum(static fn ($r): float => (float) $r->reach) : null,
                ];
                if (! $noMoney) {
                    $row['spend'] = $vs->filter(static fn ($r): bool => $r->spend_original !== null && $r->original_currency !== null)
                        ->groupBy('original_currency')
                        ->map(static fn ($c, $cur): array => ['currency' => (string) $cur, 'amount' => round((float) $c->sum(static fn ($r): float => (float) $r->spend_original), 2), 'source' => (string) $c->first()->currency_source])
                        ->values()->all();
                }
                $values[] = $row;
            }
            usort($values, static fn (array $a, array $b): int => ($b['impressions'] ?? -1) <=> ($a['impressions'] ?? -1));

            $groups[] = [
                'kind' => 'paid_delivery',
                'provider' => $provider,
                'dimension' => $dimension,
                'metric' => 'impressions',
                'source_field' => (string) $set->first()->source_field,
                'denominator' => $denominator,
                'reach_note' => ! $oneDay ? 'reach_not_additive_across_days' : ($set->pluck('external_ad_set_id')->unique()->count() > 1 ? 'reach_not_additive_across_ad_sets' : null),
                'coverage' => [
                    'days_with_rows' => $set->pluck('metric_date')->unique()->count(),
                    'days_in_period' => $period['days'],
                    'ad_sets_with_rows' => $set->pluck('external_ad_set_id')->filter()->unique()->count(),
                    'ad_sets_delivering' => $this->deliveringAdSets($tenantId, $projectId, $provider, $period),
                ],
                'synced_at' => ($m = $set->max('synced_at')) === null ? null : Carbon::parse((string) $m)->toIso8601String(),
                'rows' => $values,
            ];
        }

        $missing = [];
        foreach ($providers as $p) {
            foreach (AudienceCapabilities::for($p)['dimensions'] ?? [] as $dim => $facts) {
                if (! collect($groups)->contains(static fn (array $g): bool => $g['provider'] === $p && $g['dimension'] === $dim)) {
                    $missing[] = ['provider' => $p, 'dimension' => $dim, 'state' => $facts['delivery'] === 'stored' ? 'no_rows_in_period' : $facts['delivery']];
                }
            }
        }

        return ['state' => $groups === [] ? 'unavailable' : 'ready', 'groups' => $groups, 'unavailable' => $missing];
    }

    /** The external campaigns behind the unified-campaign ceiling — a subquery, so no extra statement. */
    private function campaignSubquery(): \Closure
    {
        $campaigns = $this->campaigns ?? [];
        $project = $this->project;

        return static fn ($q) => $q->select('id')->from('external_campaigns')->where('project_id', $project)->whereIn('unified_campaign_id', $campaigns);
    }

    /** @param array{from: string, to: string, days: int} $period */
    private function deliveringAdSets(string $tenantId, string $projectId, string $provider, array $period): int
    {
        return (int) DB::table('entity_daily_metrics')
            ->where('tenant_id', $tenantId)->where('project_id', $projectId)->where('provider', $provider)
            ->tap(fn ($q) => BoundAccountVisibility::applyToEntityMetrics($q, 'entity_daily_metrics'))
            ->when($this->accounts !== null, fn ($q) => $q->whereIn('external_account_id', $this->accounts))
            ->when($this->campaigns !== null, fn ($q) => $q->whereIn('external_campaign_id', $this->campaignSubquery()))
            ->where('entity_type', 'ad_set')->where('impressions', '>', 0)
            ->whereBetween('metric_date', [$period['from'], $period['to']])
            ->distinct()->count('entity_id');
    }

    /**
     * GA4 sessions by country and device — the SITE's visitors, never the ads' audience.
     *
     * @param  array{from: string, to: string, days: int}  $period
     * @return array<string, mixed>
     */
    private function site(string $tenantId, string $projectId, array $period): array
    {
        // The same memoised read the site and store readings use: one per build (PERF-RECOVERY-001).
        $accountId = ReportReadMemo::remember('ga4-binding|'.$tenantId.'|'.$projectId, static fn (): mixed => ProjectIntegrationBinding::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('project_id', $projectId)
            ->where('is_active', true)->where('provider', 'ga4')
            ->orderBy('created_at')->value('external_account_id'));
        if (! is_string($accountId) || $accountId === '') {
            return ['state' => 'unavailable', 'reason' => 'no_ga4_property', 'groups' => []];
        }

        $rows = DB::table('measurement_dimension_rows')
            ->where('tenant_id', $tenantId)->where('project_id', $projectId)->where('external_account_id', $accountId)
            ->whereIn('breakdown', ['country', 'device'])
            ->whereBetween('metric_date', [$period['from'], $period['to']])
            ->get(['breakdown', 'dimension_1', 'metrics']);

        $groups = [];
        foreach ($rows->groupBy('breakdown') as $breakdown => $set) {
            $by = [];
            foreach ($set as $r) {
                $s = ((array) json_decode((string) $r->metrics, true))['sessions'] ?? null;
                if (is_numeric($s)) {
                    $by[(string) $r->dimension_1] = ($by[(string) $r->dimension_1] ?? 0.0) + (float) $s;
                }
            }
            $den = array_sum($by);
            arsort($by);
            $groups[] = [
                'kind' => 'site_events',
                'provider' => 'ga4',
                'dimension' => $breakdown === 'device' ? 'device' : 'country',
                'metric' => 'sessions',
                'denominator' => $den,
                'rows' => array_map(static fn (int|string $v, float $n): array => ['value' => (string) $v, 'sessions' => $n, 'share' => $den > 0 ? ['numerator' => $n, 'denominator' => $den, 'ratio' => round($n / $den, 6)] : null], array_keys($by), array_values($by)),
            ];
        }

        return ['state' => $groups === [] ? 'unavailable' : 'ready', 'reason' => $groups === [] ? 'no_rows_in_period' : null, 'groups' => $groups];
    }
}
