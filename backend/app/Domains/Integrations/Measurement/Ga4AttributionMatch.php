<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Measurement;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Services\BoundAccountVisibility;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Services\CurrencyConverter;
use App\Domains\Metrics\Services\ReportingTimezone;
use App\Support\AdPlatforms;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * GA4-ATTRIBUTION-MATCH-001 — gathers the scoped inputs of {@see Ga4AttributionMatcher}.
 *
 * Scope: tenant + project + the project's own selected property + the project's own campaigns, all in
 * the WHERE. A project with no property gets `not_connected` / `not_selected`, never another project's
 * rows. Platform figures come under the bound-account ceiling the other readings use, in the
 * project's reporting calendar. Money is converted to USD per day at that day's rate; a day with no
 * rate withholds the whole figure (null), never a partial sum.
 */
final class Ga4AttributionMatch
{
    /** @var array<string, ?float> */
    private array $rates = [];

    public function __construct(
        private readonly Ga4AttributionMatcher $matcher,
        private readonly Ga4PlatformGap $gap,
        private readonly CurrencyConverter $converter,
        private readonly ReportingTimezone $timezones,
    ) {}

    /**
     * @param  array{platform?: ?string, channel?: ?string, campaign?: ?string, compare?: bool, internal?: bool}  $o
     * @return array<string, mixed>
     */
    public function build(string $tenantId, string $projectId, Carbon $from, Carbon $to, array $o = []): array
    {
        $property = $this->property($tenantId, $projectId);
        $period = ['from' => $from->toDateString(), 'to' => $to->toDateString()];
        if ($property === null) {
            $connected = ExternalAccount::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('account_type', Ga4PropertyDiscovery::ACCOUNT_TYPE)->exists();

            return ['state' => $connected ? 'not_selected' : 'not_connected', 'period' => $period, 'basis' => 'ga4', 'order_level' => false];
        }

        $match = $this->read($tenantId, $projectId, $property, $from, $to, $o);
        $rows = $match['rows'];
        $out = $match['match'] + [
            'state' => $rows === 0 ? 'empty' : 'ready',
            'period' => $period,
            'property' => ['id' => (string) $property->external_id, 'name' => $property->name, 'currency' => $property->currency],
            'last_synced_at' => $property->last_synced_at?->toIso8601String(),
            'evidence' => $match['evidence'],
            'filters' => ['platform' => $o['platform'] ?? null, 'channel' => $o['channel'] ?? null, 'campaign' => $o['campaign'] ?? null],
            'model_note' => ['en' => 'GA4 session-scoped source / medium and campaign. No model switch: the Data API reports one.', 'ar' => 'مصدر الجلسة ووسطها وحملتها كما يسجّلها GA4. لا تبديل للنموذج لأن الواجهة تعيد نموذجًا واحدًا.'],
        ];

        if (! empty($o['compare'])) {
            $days = $from->diffInDays($to) + 1;
            $pTo = $from->copy()->subDay();
            $pFrom = $pTo->copy()->subDays($days - 1);
            $prev = $this->read($tenantId, $projectId, $property, $pFrom, $pTo, $o)['match'];
            $out['previous'] = [
                'period' => ['from' => $pFrom->toDateString(), 'to' => $pTo->toDateString()],
                'platforms' => array_map(static fn (array $p): array => ['platform' => $p['platform'], 'ga4' => $p['ga4']], $prev['platforms']),
            ];
        }

        if (empty($o['internal'])) {
            $client = $this->matcher->forClient($out);
            $out = array_diff_key($out, ['platforms' => 1, 'unmatched' => 1, 'other_paid' => 1, 'unattributed' => 1]) + $client;
            unset($out['evidence']['campaigns_without_trace']);
        }

        return $out;
    }

    /** @return array{match: array<string, mixed>, evidence: array<string, mixed>, rows: int} */
    private function read(string $tenantId, string $projectId, ExternalAccount $property, Carbon $from, Carbon $to, array $o): array
    {
        $window = $this->timezones->window($projectId, $from, $to);
        $dims = DB::table('measurement_dimension_rows')
            ->where('tenant_id', $tenantId)->where('project_id', $projectId)->where('external_account_id', $property->getKey())
            ->whereIn('breakdown', ['campaign', 'campaign_id', 'google_ads_campaign', 'landing_query', 'channel_source'])
            ->whereBetween('metric_date', [$window['from_date'], $window['to_date']])
            ->get(['metric_date', 'breakdown', 'dimension_1', 'dimension_2', 'metrics']);

        $channelSm = null;
        if (! empty($o['channel'])) {
            $channelSm = $dims->where('breakdown', 'channel_source')->where('dimension_1', $o['channel'])->pluck('dimension_2')->map(static fn ($v): string => (string) $v)->unique()->flip()->all();
        }

        $units = [];
        $ids = [];
        $clicks = [];
        $parser = new Ga4LandingQuery;
        foreach ($dims as $r) {
            $m = (array) json_decode((string) $r->metrics, true);
            $date = substr((string) $r->metric_date, 0, 10);
            switch ($r->breakdown) {
                case 'campaign':
                    if ($channelSm !== null && ! isset($channelSm[(string) $r->dimension_2])) {
                        break;
                    }
                    [$source, $medium] = array_pad(explode(' / ', (string) $r->dimension_2, 2), 2, '');
                    $k = $r->dimension_1.'|'.$r->dimension_2;
                    $rev = (float) ($m['revenue'] ?? 0);
                    $usd = $rev == 0.0 ? 0.0 : $this->usd($rev, $property->currency, $date);
                    $u = $units[$k] ?? ['name' => (string) $r->dimension_1, 'source' => $source, 'medium' => $medium, 'sessions' => 0.0, 'purchases' => 0.0, 'revenue' => 0.0, 'revenue_usd' => 0.0];
                    $u['sessions'] += (float) ($m['sessions'] ?? 0);
                    $u['purchases'] += (float) ($m['purchases'] ?? 0);
                    $u['revenue'] += $rev;
                    $u['revenue_usd'] = $u['revenue_usd'] === null || $usd === null ? null : $u['revenue_usd'] + $usd;
                    $units[$k] = $u;
                    break;
                case 'campaign_id':
                case 'google_ads_campaign':
                    if ((string) $r->dimension_1 !== '' && $r->dimension_1 !== '(not set)') {
                        $ids[$r->dimension_1.'|'.$r->dimension_2] = ['id' => (string) $r->dimension_1, 'name' => (string) $r->dimension_2];
                    }
                    break;
                case 'landing_query':
                    $p = $parser->fromSignature((string) $r->dimension_1);
                    if ($p['family'] !== null) {
                        $k = $p['family'].'|'.($p['utm']['utm_campaign'] ?? '');
                        $clicks[$k] = ['family' => $p['family'], 'campaign' => $p['utm']['utm_campaign'] ?? '', 'sessions' => ($clicks[$k]['sessions'] ?? 0.0) + (float) ($m['sessions'] ?? 0)];
                    }
                    break;
            }
        }
        // A campaign-id row whose id is a Google Ads id also names the Google campaign under its own name.
        $units = array_values($units);

        $campaigns = $this->campaigns($tenantId, $projectId, $window['from_date'], $window['to_date']);
        if (! empty($o['platform'])) {
            $campaigns = array_values(array_filter($campaigns, static fn (array $c): bool => $c['platform'] === $o['platform']));
        }
        if (! empty($o['campaign'])) {
            $want = $this->matcher->norm((string) $o['campaign']);
            $campaigns = array_values(array_filter($campaigns, fn (array $c): bool => $this->matcher->norm($c['name']) === $want));
            $units = array_values(array_filter($units, fn (array $u): bool => $this->matcher->norm($u['name']) === $want));
        }

        $match = $this->matcher->build($campaigns, $units, ['ids' => array_values($ids), 'clicks' => array_values($clicks)]);
        if (! empty($o['platform'])) {
            $match['platforms'] = array_values(array_filter($match['platforms'], static fn (array $p): bool => $p['platform'] === $o['platform']));
        }

        $families = [];
        foreach ($clicks as $c) {
            $families[$c['family']] = ($families[$c['family']] ?? 0.0) + $c['sessions'];
        }
        ksort($families);

        return [
            'match' => $match,
            'rows' => count($units),
            'evidence' => [
                'click_id_sessions' => array_map(static fn (string $f, float $s): array => ['family' => $f, 'sessions' => round($s, 2)], array_keys($families), array_values($families)),
                'id_pairs' => count($ids),
                'campaigns_without_trace' => $match['unmatched']['campaigns'],
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function campaigns(string $tenantId, string $projectId, string $fromDate, string $toDate): array
    {
        $campaigns = ExternalCampaign::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('project_id', $projectId)->get(['id', 'provider', 'external_id', 'name']);
        if ($campaigns->isEmpty()) {
            return [];
        }

        $rows = DailyMetric::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('project_id', $projectId)
            ->whereBetween('metric_date', [$fromDate, $toDate])
            ->tap(fn ($q) => BoundAccountVisibility::apply($q, 'daily_metrics'))
            ->whereIn('metric_key', ['conversions', 'revenue', 'spend', 'clicks'])
            ->groupBy('external_campaign_id', 'metric_key', 'project_currency', 'metric_date')
            ->select('external_campaign_id', 'metric_key', 'project_currency', 'metric_date')
            ->selectRaw('SUM(value) AS total')
            ->toBase()->get();

        $acc = [];
        foreach ($rows as $r) {
            $id = (string) $r->external_campaign_id;
            $key = (string) $r->metric_key;
            $v = (float) $r->total;
            if ($key === 'conversions' || $key === 'clicks') {
                $acc[$id][$key] = ($acc[$id][$key] ?? 0.0) + $v;

                continue;
            }
            $usd = $v == 0.0 ? 0.0 : $this->usd($v, $r->project_currency === null ? null : (string) $r->project_currency, substr((string) $r->metric_date, 0, 10));
            $slot = $key.'_usd';
            $acc[$id][$slot] = array_key_exists($slot, $acc[$id] ?? []) ? ($acc[$id][$slot] === null || $usd === null ? null : $acc[$id][$slot] + $usd) : $usd;
        }

        $out = [];
        foreach ($campaigns as $c) {
            $a = $acc[(string) $c->id] ?? [];
            $out[] = [
                'platform' => AdPlatforms::canonical((string) $c->provider),
                'external_id' => (string) $c->external_id,
                'name' => (string) $c->name,
                'claimed' => [
                    'conversions' => isset($a['conversions']) ? round($a['conversions'], 2) : null,
                    'revenue_usd' => array_key_exists('revenue_usd', $a) && $a['revenue_usd'] !== null ? round($a['revenue_usd'], 2) : null,
                    'spend_usd' => array_key_exists('spend_usd', $a) && $a['spend_usd'] !== null ? round($a['spend_usd'], 2) : null,
                    'clicks' => isset($a['clicks']) ? round($a['clicks'], 2) : null,
                ],
            ];
        }

        return $out;
    }

    private function usd(float $amount, ?string $currency, string $date): ?float
    {
        if ($currency === null || $currency === '') {
            return null;
        }
        $k = $currency.'|'.$date;
        if (! array_key_exists($k, $this->rates)) {
            $this->rates[$k] = $this->converter->resolve($currency, 'USD', Carbon::parse($date))['rate'] ?? null;
        }

        return $this->rates[$k] === null ? null : $amount * $this->rates[$k];
    }

    private function property(string $tenantId, string $projectId): ?ExternalAccount
    {
        $accountId = ProjectIntegrationBinding::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('project_id', $projectId)
            ->where('is_active', true)->where('provider', 'ga4')
            ->orderBy('created_at')->value('external_account_id');
        if (! is_string($accountId) || $accountId === '') {
            return null;
        }

        return ExternalAccount::withoutGlobalScopes()->where('tenant_id', $tenantId)
            ->where('account_type', Ga4PropertyDiscovery::ACCOUNT_TYPE)->find($accountId);
    }
}
