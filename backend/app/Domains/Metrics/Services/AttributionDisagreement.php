<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Services;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Commerce\Models\CommerceOrder;
use App\Domains\Integrations\Services\BoundAccountVisibility;
use App\Domains\Metrics\Models\DailyMetric;
use App\Support\AdPlatforms;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * CROSS-PLATFORM-ATTRIBUTION-DEPTH-001 — attribution disagreement per platform, over time.
 *
 * The panel already sets each platform's claim beside what the store confirmed for the whole
 * window. A single pair hides WHEN they parted: a platform that matched the store for three weeks
 * and claimed double in the fourth reads the same as one that always over-claimed by a third. This
 * splits the same two figures into periods — days for a window up to fourteen days, seven-day
 * periods counted from the window's first day beyond that — so the reader sees where the gap opened.
 *
 * Nothing new is measured. The claim is the platform's own `conversions`, the confirmed count is the
 * store's live orders placed on that platform by the same rule the whole-window figure uses (the
 * resolved campaign's platform, else the click id's), and each order falls in the period of its
 * merchant-local day. So the periods of one platform add up to its whole-window pair, which the
 * tests assert. No total across platforms is produced: two platforms can claim the same sale.
 */
final class AttributionDisagreement
{
    private const ORDERS_KEY = 'conversions';

    private const DAILY_UP_TO_DAYS = 14;

    /**
     * @param  Collection<int, CommerceOrder>  $orders  the window's orders as ProjectOrders loaded them
     * @param  list<string>  $providers  empty = every platform
     * @return array<string, mixed>
     */
    public function build(bool $hasStore, string $projectId, string $fromDate, string $toDate, Collection $orders, array $providers = []): array
    {
        $notes = [
            'note_ar' => 'ما زعمته كل منصة مقابل ما أكّده المتجر، مقسّمًا على الفترة. لا يُجمع بين المنصات: منصتان قد تزعمان الطلب نفسه.',
            'note_en' => 'What each platform claimed against what the store confirmed, split across the window. Never summed across platforms: two platforms can claim the same order.',
        ];

        if (! $hasStore) {
            return ['available' => false, 'unavailable_reason' => 'no_store_connected', ...$notes, 'grain' => null, 'periods' => [], 'platforms' => []];
        }

        $from = Carbon::parse($fromDate)->startOfDay();
        $to = Carbon::parse($toDate)->startOfDay();
        $days = (int) $from->diffInDays($to) + 1;
        $step = $days <= self::DAILY_UP_TO_DAYS ? 1 : 7;

        $periods = [];
        for ($start = $from->copy(); $start->lte($to); $start->addDays($step)) {
            $end = $start->copy()->addDays($step - 1);
            $periods[] = ['from' => $start->toDateString(), 'to' => ($end->gt($to) ? $to : $end)->toDateString()];
        }
        $periodOf = static function (string $date) use ($periods): ?int {
            foreach ($periods as $i => $p) {
                if ($date >= $p['from'] && $date <= $p['to']) {
                    return $i;
                }
            }

            return null;
        };

        $claimed = [];
        DailyMetric::withoutGlobalScopes()
            ->where('project_id', $projectId)
            ->whereBetween('metric_date', [$fromDate, $toDate])
            ->tap(fn ($q) => BoundAccountVisibility::apply($q, 'daily_metrics'))
            ->where('metric_key', self::ORDERS_KEY)
            ->when($providers !== [], fn ($q) => $q->whereIn('provider', $providers))
            ->groupBy('provider', 'metric_date')
            ->select('provider', 'metric_date')
            ->selectRaw('SUM(value) AS total')
            ->toBase()
            ->get()
            ->each(function ($row) use (&$claimed, $periodOf): void {
                $i = $periodOf(Carbon::parse((string) $row->metric_date)->toDateString());
                if ($i === null) {
                    return;
                }
                $provider = AdPlatforms::canonical((string) $row->provider);
                $claimed[$provider][$i] = ($claimed[$provider][$i] ?? 0.0) + (float) $row->total;
            });

        $live = $orders->filter(fn (CommerceOrder $o) => $o->cancelled_at === null);
        $campaignIds = $live->pluck('external_campaign_id')->filter()->unique()->values()->all();
        $campaignProviders = $campaignIds === []
            ? []
            : ExternalCampaign::withoutGlobalScopes()->whereIn('id', $campaignIds)->pluck('provider', 'id')->all();

        $confirmed = [];
        foreach ($live as $order) {
            $raw = $order->external_campaign_id !== null
                ? ($campaignProviders[$order->external_campaign_id] ?? null)
                : $order->click_id_provider;
            if ($raw === null) {
                continue;
            }
            $day = $order->placed_on?->toDateString() ?? $order->placed_at?->toDateString();
            $i = $day === null ? null : $periodOf($day);
            if ($i === null) {
                continue;
            }
            $provider = AdPlatforms::canonical((string) $raw);
            $confirmed[$provider][$i] = ($confirmed[$provider][$i] ?? 0) + 1;
        }

        $rows = [];
        foreach (array_unique([...array_keys($claimed), ...array_keys($confirmed)]) as $provider) {
            $points = [];
            foreach ($periods as $i => $p) {
                $c = round((float) ($claimed[$provider][$i] ?? 0.0), 2);
                $k = (int) ($confirmed[$provider][$i] ?? 0);
                $points[] = [
                    'from' => $p['from'],
                    'to' => $p['to'],
                    'claimed' => $c,
                    'confirmed' => $k,
                    'gap' => round($c - $k, 2),
                    // Null where the store confirmed nothing: «claimed 4 of 0» has no ratio, only a gap.
                    'ratio' => $k > 0 ? round($c / $k, 3) : null,
                ];
            }
            // The period where the claim ran furthest ahead of the store, so the reader knows where to look.
            $widest = null;
            foreach ($points as $point) {
                if ($widest === null || $point['gap'] > $widest['gap']) {
                    $widest = $point;
                }
            }
            $rows[] = [
                'provider' => $provider,
                'claimed' => round(array_sum(array_column($points, 'claimed')), 2),
                'confirmed' => array_sum(array_column($points, 'confirmed')),
                'widest_gap' => $widest,
                'points' => $points,
            ];
        }

        return [
            'available' => true,
            'unavailable_reason' => null,
            ...$notes,
            'grain' => $step === 1 ? 'day' : 'week',
            'periods' => $periods,
            'platforms' => AdPlatforms::sortRows($rows, 'provider'),
        ];
    }
}
