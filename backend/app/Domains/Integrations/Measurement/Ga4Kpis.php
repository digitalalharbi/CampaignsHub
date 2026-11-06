<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Measurement;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * GA4-BOUNCE-AOV-KPIS-001 — the site KPIs with current value, previous period, change, source,
 * definition and freshness, read from the stored DAILY figures of the project's selected property.
 *
 * Rules the numbers obey:
 *   - counts are summed over the window; rates (bounce, engagement) are weighted by each day's
 *     sessions, never summed or averaged as bare fractions;
 *   - AOV, items per order and revenue per user / session are DERIVED from sums, and only when both
 *     operands exist — a missing operand makes the KPI absent (null), never 0;
 *   - these are GA4's figures under GA4's attribution: they carry `blended_with_other_sources=false`
 *     and are never added to a store or ad-platform figure.
 */
final class Ga4Kpis
{
    /** key => [definition, GA4 metrics it is built from, kind, money] */
    private const DEFS = [
        'sessions' => ['Sessions started on the site.', ['sessions'], 'count', false],
        'users_daily_sum' => ['Total users per day, added across the days (a person on two days counts twice).', ['totalUsers'], 'count', false],
        'new_users' => ['Users who visited for the first time.', ['newUsers'], 'count', false],
        'active_users' => ['Active users per day, added across the days (a person on two days counts twice).', ['activeUsers'], 'count', false],
        'engaged_sessions' => ['Sessions longer than 10 seconds, or with a key event, or with 2+ screen views.', ['engagedSessions'], 'count', false],
        'engagement_rate' => ['Engaged sessions divided by sessions, weighted by each day\'s sessions.', ['engagementRate', 'sessions'], 'rate', false],
        'bounce_rate' => ['Sessions that were not engaged divided by sessions (GA4 bounceRate), weighted by each day\'s sessions.', ['bounceRate', 'sessions'], 'rate', false],
        'avg_engagement_seconds' => ['Total engagement time divided by the daily active users added across the days: seconds per active user-day.', ['userEngagementDuration', 'activeUsers'], 'seconds', false],
        'page_views' => ['Screen / page views.', ['screenPageViews'], 'count', false],
        'product_views' => ['Units viewed in view_item events. Needs the view_item event on the site, otherwise absent.', ['itemsViewed'], 'count', false],
        'key_events' => ['Key events (formerly conversions).', ['keyEvents'], 'count', false],
        'transactions' => ['Purchase transactions with revenue.', ['transactions'], 'count', false],
        'revenue' => ['Purchase revenue GA4 measured on the site, in the property currency. Not store or ad-platform revenue.', ['purchaseRevenue'], 'money', true],
        'aov' => ['Average order value: purchase revenue divided by transactions.', ['purchaseRevenue', 'transactions'], 'money', true],
        'items_per_order' => ['Average products per order: items purchased divided by transactions.', ['itemsPurchased', 'transactions'], 'ratio', false],
        'revenue_per_user' => ['Purchase revenue divided by total users added across the days (users are not de-duplicated across days).', ['purchaseRevenue', 'totalUsers'], 'money', true],
        'revenue_per_session' => ['Purchase revenue divided by sessions.', ['purchaseRevenue', 'sessions'], 'money', true],
        // GA4-STORE-ANALYTICS-001
        'avg_session_seconds' => ['Average session duration in seconds (GA4 averageSessionDuration), weighted by each day\'s sessions.', ['averageSessionDuration', 'sessions'], 'seconds', false],
        'conversion_rate' => ['Purchase transactions divided by sessions.', ['transactions', 'sessions'], 'rate', false],
        'add_to_carts' => ['Add-to-cart events.', ['addToCarts'], 'count', false],
        'checkouts' => ['Begin-checkout events.', ['checkouts'], 'count', false],
    ];

    /**
     * @return array{kpis: list<array<string, mixed>>, previous_period: array{from: string, to: string}, kpi_freshness: array<string, mixed>}
     */
    public function build(string $tenantId, string $projectId, string $accountId, Carbon $from, Carbon $to, ?CarbonInterface $lastSyncedAt, ?string $currency): array
    {
        $days = (int) $from->diffInDays($to) + 1;
        $prevTo = $from->copy()->subDay();
        $prevFrom = $prevTo->copy()->subDays($days - 1);

        $rows = DB::table('measurement_daily_metrics')
            ->where('tenant_id', $tenantId)->where('project_id', $projectId)->where('external_account_id', $accountId)
            ->whereBetween('metric_date', [$prevFrom->toDateString(), $to->toDateString()])
            ->get(['metric_date', 'metric_key', 'value']);

        $win = ['current' => [], 'previous' => []];
        $newest = null;
        foreach ($rows as $r) {
            $d = substr((string) $r->metric_date, 0, 10);
            $w = $d >= $from->toDateString() ? 'current' : 'previous';
            $win[$w][$d][(string) $r->metric_key] = (float) $r->value;
            if ($w === 'current' && ($newest === null || $d > $newest)) {
                $newest = $d;
            }
        }

        $freshness = [
            'last_synced_at' => $lastSyncedAt?->toIso8601String(),
            'newest_day' => $newest,
            'days_with_data' => count($win['current']),
            'days_in_window' => $days,
        ];

        $kpis = [];
        foreach (self::DEFS as $key => [$definition, $metrics, $kind, $money]) {
            $cur = $this->value($key, $win['current']);
            $prev = $this->value($key, $win['previous']);
            $kpis[] = [
                'key' => $key,
                'kind' => $kind,
                'current' => $cur,
                'previous' => $prev,
                'change' => $cur !== null && $prev !== null ? round($cur - $prev, 6) : null,
                'change_pct' => $cur !== null && $prev !== null && $prev != 0.0 ? round(($cur - $prev) / abs($prev), 4) : null,
                'currency' => $money ? $currency : null,
                'definition' => $definition,
                'source' => ['kind' => 'ga4', 'metrics' => $metrics],
                'blended_with_other_sources' => false,
                'freshness' => $freshness,
                'trend' => $this->trend($key, $win['current']),
            ];
        }

        return [
            'kpis' => $kpis,
            'previous_period' => ['from' => $prevFrom->toDateString(), 'to' => $prevTo->toDateString()],
            'kpi_freshness' => $freshness,
        ];
    }

    /**
     * The KPI day by day, each day derived on its own. A day that cannot state it is left out rather
     * than drawn as zero.
     *
     * @param  array<string, array<string, float>>  $byDay
     * @return list<array{date: string, value: float}>
     */
    private function trend(string $key, array $byDay): array
    {
        ksort($byDay);
        $out = [];
        foreach ($byDay as $date => $values) {
            $value = $this->value($key, [$date => $values]);
            if ($value !== null) {
                $out[] = ['date' => $date, 'value' => $value];
            }
        }

        return $out;
    }

    /** @param array<string, array<string, float>> $byDay */
    private function sum(array $byDay, string $k): ?float
    {
        $v = array_filter(array_column($byDay, $k), static fn ($x) => $x !== null);

        return $v === [] ? null : array_sum($v);
    }

    /** @param array<string, array<string, float>> $byDay */
    private function weighted(array $byDay, string $k): ?float
    {
        $num = 0.0;
        $den = 0.0;
        foreach ($byDay as $day) {
            if (isset($day[$k], $day['sessions']) && $day['sessions'] > 0) {
                $num += $day[$k] * $day['sessions'];
                $den += $day['sessions'];
            }
        }

        return $den > 0 ? round($num / $den, 6) : null;
    }

    /** @param array<string, array<string, float>> $byDay */
    private function ratio(array $byDay, string $num, string $den): ?float
    {
        $n = $this->sum($byDay, $num);
        $d = $this->sum($byDay, $den);

        return $n !== null && $d !== null && $d > 0 ? round($n / $d, 6) : null;
    }

    /** @param array<string, array<string, float>> $byDay */
    private function value(string $key, array $byDay): ?float
    {
        if ($byDay === []) {
            return null;
        }

        return match ($key) {
            'sessions', 'new_users', 'active_users', 'engaged_sessions', 'page_views', 'product_views', 'key_events', 'transactions', 'revenue', 'add_to_carts', 'checkouts' => $this->sum($byDay, $key),
            'avg_session_seconds' => $this->weighted($byDay, 'avg_session_seconds'),
            'conversion_rate' => $this->ratio($byDay, 'transactions', 'sessions'),
            'users_daily_sum' => $this->sum($byDay, 'users'),
            'engagement_rate' => $this->weighted($byDay, 'engagement_rate'),
            'bounce_rate' => $this->weighted($byDay, 'bounce_rate'),
            'avg_engagement_seconds' => $this->ratio($byDay, 'engagement_seconds', 'active_users'),
            'aov' => $this->ratio($byDay, 'revenue', 'transactions'),
            'items_per_order' => $this->ratio($byDay, 'items_purchased', 'transactions'),
            'revenue_per_user' => $this->ratio($byDay, 'revenue', 'users'),
            'revenue_per_session' => $this->ratio($byDay, 'revenue', 'sessions'),
            default => null,
        };
    }
}
