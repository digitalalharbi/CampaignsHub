<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Measurement;

use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * GA4-ANALYTICS-PRODUCT-001 — what the SITE measured, as an analytics experience, kept apart from
 * every ad platform's figure (ATTR-LAYER-GA4-001, GA4-NOT-BLENDED-001).
 *
 * Read from the breakdowns the property sync stores. Rules the numbers obey:
 *   - COUNTS (sessions, engaged sessions, key events, add-to-carts, checkouts, purchases, revenue)
 *     add up across the rows of one breakdown; totals come from the DEVICE breakdown, which splits
 *     every session exactly once;
 *   - USERS do not add up — one person arrives from two sources, or on two days — so no total of
 *     users is printed as «users»: the daily figure is summed and labelled as a sum of daily users;
 *   - a breakdown the property never returned is ABSENT, not an empty table claiming no traffic;
 *   - paid traffic is recognised from the session medium, and a platform from the session source —
 *     the GA4 view of a platform's visits, never added to the platform's own conversions.
 */
final class Ga4SiteAnalytics
{
    /** Session mediums that mean the visit was paid for. */
    private const PAID_MEDIUM = '/^(cpc|ppc|paid|paidsearch|paid_search|paidsocial|paid_social|paid-social|cpm|cpv|display|social_paid|paid_media)$/i';

    /** Session source → advertising platform, for the paid rows only. */
    private const PLATFORM_SOURCES = [
        'meta' => '/^(facebook|fb|instagram|ig|meta|m\.facebook\.com|l\.facebook\.com|l\.instagram\.com|an)$/i',
        'google' => '/^(google|adwords|googleads|youtube)$/i',
        'snapchat' => '/^(snapchat|snap)$/i',
        'tiktok' => '/^(tiktok|tiktok\.com)$/i',
    ];

    private const TOP = 25;

    /** @return array<string, mixed> */
    public function build(string $tenantId, string $projectId, Carbon $from, Carbon $to): array
    {
        $property = $this->property($tenantId, $projectId);
        if ($property === null) {
            $connected = ExternalAccount::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('account_type', Ga4PropertyDiscovery::ACCOUNT_TYPE)->exists();

            return ['state' => $connected ? 'not_selected' : 'not_connected', 'period' => $this->period($from, $to)];
        }

        $rows = DB::table('measurement_dimension_rows')
            ->where('tenant_id', $tenantId)
            ->where('project_id', $projectId)
            ->where('external_account_id', $property->getKey())
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
            ->get(['metric_date', 'breakdown', 'dimension_1', 'dimension_2', 'metrics', 'currency'])
            ->map(fn (object $r) => [
                'date' => substr((string) $r->metric_date, 0, 10),
                'breakdown' => (string) $r->breakdown,
                'd1' => (string) $r->dimension_1,
                'd2' => (string) $r->dimension_2,
                'm' => (array) json_decode((string) $r->metrics, true),
                'currency' => $r->currency,
            ])->all();

        $base = [
            'property' => ['id' => (string) $property->external_id, 'name' => $property->name, 'timezone' => $property->timezone],
            'period' => $this->period($from, $to),
            'last_synced_at' => $property->last_synced_at?->toIso8601String(),
        ];

        if ($rows === []) {
            return ['state' => 'empty'] + $base;
        }

        $by = [];
        foreach ($rows as $row) {
            $by[$row['breakdown']][] = $row;
        }
        $currency = collect($rows)->pluck('currency')->filter()->first();
        $totalsSource = $by['device'] ?? $by['source_medium'] ?? $by['landing_page'] ?? [];
        $totals = $this->sum($totalsSource);
        $totals['engagement_rate'] = ($totals['sessions'] ?? 0) > 0 ? round(($totals['engaged_sessions'] ?? 0) / $totals['sessions'], 4) : null;
        $totals['users_daily_sum'] = $this->dailyUsers($tenantId, $projectId, (string) $property->getKey(), $from, $to);

        return ['state' => 'ready'] + $base + [
            'currency' => $currency,
            'totals' => $totals,
            'trend' => $this->trend($totalsSource),
            'funnel' => $this->funnel($totals),
            'paid' => $this->paid($by['source_medium'] ?? [], $totals),
            'breakdowns' => [
                'source_medium' => $this->table($by['source_medium'] ?? null),
                'campaign' => $this->table($by['campaign'] ?? null),
                'landing_page' => $this->table($by['landing_page'] ?? null),
                'device' => $this->table($by['device'] ?? null),
                'country' => $this->table($by['country'] ?? null),
                'user_type' => $this->table($by['user_type'] ?? null),
            ],
            'events' => $this->events($by['event'] ?? null),
        ];
    }

    /**
     * GA4's view of each advertising platform's PAID visits — for the attribution engine's third layer.
     *
     * @return array<string, array{sessions: float, purchases: float, revenue: float, currency: ?string}>|null null when no property
     */
    public function paidByPlatform(string $tenantId, string $projectId, Carbon $from, Carbon $to): ?array
    {
        $property = $this->property($tenantId, $projectId);
        if ($property === null) {
            return null;
        }
        $rows = DB::table('measurement_dimension_rows')
            ->where('tenant_id', $tenantId)->where('project_id', $projectId)->where('external_account_id', $property->getKey())
            ->where('breakdown', 'source_medium')
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
            ->get(['dimension_1', 'dimension_2', 'metrics', 'currency']);

        $out = [];
        foreach ($rows as $r) {
            $platform = $this->platformOf((string) $r->dimension_1, (string) $r->dimension_2);
            if ($platform === null) {
                continue;
            }
            $m = (array) json_decode((string) $r->metrics, true);
            $out[$platform] ??= ['sessions' => 0.0, 'purchases' => 0.0, 'revenue' => 0.0, 'currency' => null];
            $out[$platform]['sessions'] += (float) ($m['sessions'] ?? 0);
            $out[$platform]['purchases'] += (float) ($m['purchases'] ?? 0);
            $out[$platform]['revenue'] += (float) ($m['revenue'] ?? 0);
            $out[$platform]['currency'] ??= $r->currency;
        }

        return $out;
    }

    public function platformOf(string $source, string $medium): ?string
    {
        if (preg_match(self::PAID_MEDIUM, trim($medium)) !== 1) {
            return null;
        }
        foreach (self::PLATFORM_SOURCES as $platform => $pattern) {
            if (preg_match($pattern, trim($source)) === 1) {
                return $platform;
            }
        }

        return 'other_paid';
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

        return ExternalAccount::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('account_type', Ga4PropertyDiscovery::ACCOUNT_TYPE)
            ->find($accountId);
    }

    /** @return array{from: string, to: string} */
    private function period(Carbon $from, Carbon $to): array
    {
        return ['from' => $from->toDateString(), 'to' => $to->toDateString()];
    }

    /**
     * @param  list<array{m: array<string, mixed>}>  $rows
     * @return array<string, float>
     */
    private function sum(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            foreach (Ga4Breakdowns::ADDITIVE as $key) {
                if (array_key_exists($key, $row['m'])) {
                    $out[$key] = ($out[$key] ?? 0.0) + (float) $row['m'][$key];
                }
            }
        }

        // Sums of decimals drift (32364.000000000007); money and counts are stated to the cent.
        return array_map(static fn (float $v): float => round($v, 2), $out);
    }

    private function dailyUsers(string $tenantId, string $projectId, string $accountId, Carbon $from, Carbon $to): ?float
    {
        $sum = DB::table('measurement_daily_metrics')
            ->where('tenant_id', $tenantId)->where('project_id', $projectId)->where('external_account_id', $accountId)
            ->where('metric_key', 'users')
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
            ->sum('value');

        return $sum > 0 ? (float) $sum : null;
    }

    /**
     * @param  list<array{date: string, m: array<string, mixed>}>  $rows
     * @return list<array<string, mixed>>
     */
    private function trend(array $rows): array
    {
        $days = [];
        foreach ($rows as $row) {
            $day = $days[$row['date']] ?? ['date' => $row['date'], 'sessions' => 0.0, 'engaged_sessions' => 0.0, 'purchases' => 0.0, 'revenue' => 0.0];
            foreach (['sessions', 'engaged_sessions', 'purchases', 'revenue'] as $k) {
                $day[$k] += (float) ($row['m'][$k] ?? 0);
            }
            $days[$row['date']] = $day;
        }
        ksort($days);

        return array_values($days);
    }

    /**
     * Sessions → add-to-carts → checkouts → purchases, each step's rate against the one before.
     * Add-to-carts and checkouts are EVENT counts, so a step can exceed the one before it — said
     * by `exceeds_previous` rather than drawn as a funnel that widens.
     *
     * @param  array<string, float>  $totals
     * @return list<array<string, mixed>>
     */
    private function funnel(array $totals): array
    {
        $steps = [];
        $previous = null;
        foreach (['sessions', 'add_to_carts', 'checkouts', 'purchases'] as $key) {
            $count = $totals[$key] ?? null;
            $steps[] = [
                'stage' => $key,
                'count' => $count,
                'step_rate' => $previous !== null && $previous > 0 && $count !== null ? round($count / $previous, 4) : null,
                'exceeds_previous' => $previous !== null && $count !== null && $count > $previous,
            ];
            $previous = $count;
        }

        return $steps;
    }

    /**
     * @param  list<array{d1: string, d2: string, m: array<string, mixed>, currency: ?string}>  $rows
     * @param  array<string, float>  $totals
     * @return array<string, mixed>
     */
    private function paid(array $rows, array $totals): array
    {
        if ($rows === []) {
            return ['available' => false];
        }
        $platforms = [];
        $paid = ['sessions' => 0.0, 'purchases' => 0.0, 'revenue' => 0.0];
        foreach ($rows as $row) {
            $platform = $this->platformOf($row['d1'], $row['d2']);
            if ($platform === null) {
                continue;
            }
            $platforms[$platform] ??= ['platform' => $platform, 'sessions' => 0.0, 'purchases' => 0.0, 'revenue' => 0.0];
            foreach (['sessions', 'purchases', 'revenue'] as $k) {
                $platforms[$platform][$k] += (float) ($row['m'][$k] ?? 0);
                $paid[$k] += (float) ($row['m'][$k] ?? 0);
            }
        }
        $share = static fn (float $part, ?float $whole): ?float => $whole !== null && $whole > 0 ? round($part / $whole, 4) : null;

        return [
            'available' => true,
            'sessions' => round($paid['sessions'], 2),
            'purchases' => round($paid['purchases'], 2),
            'revenue' => round($paid['revenue'], 2),
            'session_share' => $share($paid['sessions'], $totals['sessions'] ?? null),
            'purchase_share' => $share($paid['purchases'], $totals['purchases'] ?? null),
            'revenue_share' => $share($paid['revenue'], $totals['revenue'] ?? null),
            'platforms' => array_values(collect($platforms)->sortByDesc('sessions')->all()),
        ];
    }

    /**
     * One breakdown aggregated over the window: summed counts per dimension value, the rates
     * derived from those sums, the top rows and how many more there were.
     *
     * @param  list<array{d1: string, d2: string, m: array<string, mixed>}>|null  $rows
     * @return array<string, mixed>|null null when the property never returned this breakdown
     */
    private function table(?array $rows): ?array
    {
        if ($rows === null) {
            return null;
        }
        $agg = [];
        foreach ($rows as $row) {
            $key = $row['d1']."\u{1F}".$row['d2'];
            $a = $agg[$key] ?? ['dimension_1' => $row['d1'], 'dimension_2' => $row['d2'] !== '' ? $row['d2'] : null];
            foreach (Ga4Breakdowns::ADDITIVE as $k) {
                if (array_key_exists($k, $row['m'])) {
                    $a[$k] = ($a[$k] ?? 0.0) + (float) $row['m'][$k];
                }
            }
            $agg[$key] = $a;
        }
        $list = collect($agg)->map(function (array $a): array {
            foreach (Ga4Breakdowns::ADDITIVE as $k) {
                if (isset($a[$k])) {
                    $a[$k] = round((float) $a[$k], 2);
                }
            }
            $sessions = (float) ($a['sessions'] ?? 0);
            $a['engagement_rate'] = $sessions > 0 ? round(((float) ($a['engaged_sessions'] ?? 0)) / $sessions, 4) : null;
            $a['conversion_rate'] = $sessions > 0 && array_key_exists('purchases', $a) ? round(((float) $a['purchases']) / $sessions, 4) : null;

            return $a;
        })->sortByDesc('sessions')->values();

        return ['rows' => $list->take(self::TOP)->all(), 'more' => max(0, $list->count() - self::TOP)];
    }

    /**
     * @param  list<array{d1: string, m: array<string, mixed>}>|null  $rows
     * @return array<string, mixed>|null
     */
    private function events(?array $rows): ?array
    {
        if ($rows === null) {
            return null;
        }
        $agg = [];
        foreach ($rows as $row) {
            $a = $agg[$row['d1']] ?? ['event' => $row['d1'], 'event_count' => 0.0, 'key_events' => 0.0];
            $a['event_count'] += (float) ($row['m']['event_count'] ?? 0);
            $a['key_events'] += (float) ($row['m']['key_events'] ?? 0);
            $agg[$row['d1']] = $a;
        }
        $list = collect($agg)->map(fn (array $a) => $a + ['is_key_event' => $a['key_events'] > 0])->sortByDesc('event_count')->values();

        return ['rows' => $list->take(30)->all(), 'more' => max(0, $list->count() - 30)];
    }
}
