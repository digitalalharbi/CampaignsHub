<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Metrics\Models\DailyMetric;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Deterministic demo creatives + per-creative daily metrics (is_demo), so the Creatives tab (CMC-8)
 * shows a real ranked list through the real tables/APIs. No rand(). Thumbnails are intentionally NULL
 * — we never fabricate a creative preview; the UI shows "preview unavailable" instead. Idempotent.
 */
final class DemoCreativesSeeder extends Seeder
{
    /** Deterministic per-creative daily shape: [format, spend/day, ctr, conv/day, rev-multiple, viewrate]. */
    private const TEMPLATES = [
        ['video', 220.0, 0.031, 6.0, 5.2, 0.42],   // strong
        ['image', 140.0, 0.018, 3.0, 3.1, 0.0],    // mid
        ['carousel', 90.0, 0.009, 0.4, 0.8, 0.0],  // weak
        ['video', 60.0, 0.055, 1.2, 2.0, 0.61],    // promising, low spend
    ];

    public function run(): void
    {
        $campaigns = UnifiedCampaign::withoutGlobalScopes()
            ->whereHas('externalCampaigns', fn ($q) => $q->whereNull('deleted_at'))
            ->orWhereIn('id', DailyMetric::query()->withoutGlobalScopes()->whereNotNull('unified_campaign_id')->distinct()->pluck('unified_campaign_id'))
            ->get()
            ->unique('id');

        $to = Carbon::today();
        $from = $to->copy()->subDays(29);

        foreach ($campaigns as $campaign) {
            $provider = DailyMetric::withoutGlobalScopes()->where('unified_campaign_id', $campaign->id)->value('provider') ?? 'sandbox';

            foreach (self::TEMPLATES as $i => [$format, $spendDay, $ctr, $convDay, $revX, $viewRate]) {
                $extId = "demo-cr-{$campaign->id}-{$i}";
                $creative = ExternalCreative::withoutGlobalScopes()->updateOrCreate(
                    ['project_id' => $campaign->project_id, 'provider' => $provider, 'external_creative_id' => $extId],
                    [
                        'id' => (string) Str::uuid(),
                        'tenant_id' => $campaign->tenant_id,
                        'campaign_id' => $campaign->id,
                        'name' => "Creative {$i} — {$format}",
                        'client_display_name' => ['Hero Video', 'Product Shot', 'Bundle Carousel', 'Teaser'][$i],
                        'format' => $format,
                        'thumbnail_url' => null, // never fabricate a preview
                        'status' => $i === 2 ? 'paused' : 'active',
                        'source_type' => 'estimated',
                        'is_demo' => true,
                        'last_synced_at' => now(),
                    ],
                );

                DB::table('creative_daily_metrics')->where('creative_id', $creative->id)->delete();
                $rows = [];
                for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
                    // Deterministic daily variation from day-of-month + template index (no rand()).
                    $wobble = 0.85 + (($d->day + $i) % 7) / 20;
                    $impr = round(($spendDay / max($ctr, 0.001)) * 0.02 * $wobble);
                    $spend = round($spendDay * $wobble, 2);
                    $conv = round($convDay * $wobble, 2);
                    $rows[] = [
                        'id' => (string) Str::uuid(),
                        'tenant_id' => $campaign->tenant_id, 'project_id' => $campaign->project_id,
                        'creative_id' => $creative->id, 'campaign_id' => $campaign->id,
                        'metric_date' => $d->toDateString(),
                        'spend' => $spend,
                        'impressions' => $impr,
                        'clicks' => round($impr * $ctr, 2),
                        'conversions' => $conv,
                        'revenue' => round($spend * $revX, 2),
                        'video_views' => $format === 'video' ? round($impr * 0.6) : 0,
                        'video_completions' => $format === 'video' ? round($impr * 0.6 * $viewRate) : 0,
                        'is_demo' => true,
                        'created_at' => now(), 'updated_at' => now(),
                    ];
                }
                DB::table('creative_daily_metrics')->insert($rows);
            }
        }

        $this->command?->info('Seeded demo creatives for '.$campaigns->count().' campaign(s).');
    }
}
