<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Measurement;

use App\Domains\Commerce\Models\CommerceOrder;
use App\Domains\Commerce\Services\ProjectOrders;
use App\Domains\Commerce\Services\ProjectStores;
use App\Domains\Integrations\Services\BoundAccountVisibility;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Services\ReportingTimezone;
use App\Support\AdPlatforms;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * GA4-GAP-EXPLAINED-001 — gathers the scoped inputs of {@see Ga4GapExplainer}: the attribution match, the
 * platforms' declared attribution windows, the landing sessions without any tag, the returning share of
 * purchases, and a store total ONLY if the system holds one for the project (no Salla / Zid API required).
 */
final class Ga4GapExplain
{
    public function __construct(
        private readonly Ga4AttributionMatch $match,
        private readonly Ga4GapExplainer $explainer,
        private readonly ReportingTimezone $timezones,
        private readonly ProjectOrders $orders,
        private readonly ProjectStores $stores,
    ) {}

    /** @return array<string, mixed> */
    public function build(string $tenantId, string $projectId, Carbon $from, Carbon $to, bool $internal = true): array
    {
        $match = $this->match->build($tenantId, $projectId, $from, $to, ['internal' => true]);
        $period = ['from' => $from->toDateString(), 'to' => $to->toDateString()];
        if (($match['state'] ?? null) !== 'ready') {
            return ['state' => $match['state'] ?? 'not_connected', 'period' => $period, 'basis' => 'ga4', 'order_level' => false];
        }

        $out = $this->explainer->explain($match, $this->context($tenantId, $projectId, $from, $to, $match), ! $internal);

        return ['state' => 'ready', 'period' => $period, 'property' => $match['property'], 'last_synced_at' => $match['last_synced_at']] + $out;
    }

    /**
     * @param  array<string, mixed>  $match
     * @return array<string, mixed>
     */
    private function context(string $tenantId, string $projectId, Carbon $from, Carbon $to, array $match): array
    {
        $window = $this->timezones->window($projectId, $from, $to);
        $accountId = DB::table('project_integration_bindings')->where('tenant_id', $tenantId)->where('project_id', $projectId)->where('provider', 'ga4')->where('is_active', true)->orderBy('created_at')->value('external_account_id');

        $sessionsOf = static function (string $breakdown, ?string $d1 = null) use ($tenantId, $projectId, $accountId, $window) {
            $q = DB::table('measurement_dimension_rows')->where('tenant_id', $tenantId)->where('project_id', $projectId)->where('external_account_id', $accountId)
                ->where('breakdown', $breakdown)->whereBetween('metric_date', [$window['from_date'], $window['to_date']]);
            if ($d1 !== null) {
                $q->where('dimension_1', $d1);
            }

            return $q;
        };

        // Landing sessions that carried no click id and no UTM, among the landing queries GA4 returned.
        $all = (float) $sessionsOf('landing_query')->sum(DB::raw("NULLIF(metrics->>'sessions','')::float8"));
        $none = (float) $sessionsOf('landing_query', '')->sum(DB::raw("NULLIF(metrics->>'sessions','')::float8"));

        $purchases = (float) $sessionsOf('user_type')->sum(DB::raw("NULLIF(metrics->>'purchases','')::float8"));
        $returning = (float) $sessionsOf('user_type', 'returning')->sum(DB::raw("NULLIF(metrics->>'purchases','')::float8"));

        $windows = [];
        $rows = DailyMetric::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('project_id', $projectId)
            ->whereBetween('metric_date', [$window['from_date'], $window['to_date']])
            ->tap(fn ($q) => BoundAccountVisibility::apply($q, 'daily_metrics'))
            ->whereNotNull('attribution_window')->where('attribution_window', '!=', 'default')
            ->distinct()->get(['provider', 'attribution_window']);
        foreach ($rows as $r) {
            $windows[AdPlatforms::canonical((string) $r->provider)][] = (string) $r->attribution_window;
        }
        ksort($windows);

        $store = null;
        if ($this->stores->forProject($tenantId, $projectId)->isNotEmpty()) {
            $live = $this->orders->forWindow($tenantId, $projectId, $window['from'], $window['to'])['orders']->filter(static fn (CommerceOrder $o): bool => $o->cancelled_at === null);
            $store = ['orders' => $live->count(), 'revenue' => round((float) $live->sum(static fn (CommerceOrder $o) => $o->netRevenue() ?? 0.0), 2)];
        }

        return [
            'untagged_share' => $all > 0 ? round($none / $all, 4) : null,
            'returning_share' => $purchases > 0 ? round($returning / $purchases, 4) : null,
            'windows' => $windows,
            'store' => $store,
            'currency' => $match['property']['currency'] ?? null,
            'usd_basis' => 'daily',
        ];
    }
}
