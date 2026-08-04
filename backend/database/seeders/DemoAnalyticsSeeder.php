<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Actions\UpsertDailyMetrics;
use App\Domains\Metrics\DTO\NormalizedMetric;
use App\Domains\Metrics\Models\CurrencyRate;
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Domains\Metrics\Services\CurrencyConverter;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Ramsey\Uuid\Uuid;

/**
 * Rich, STORY-DRIVEN demo analytics — 90 days across platforms & campaigns, written to the real
 * daily_metrics/currency_rates/metric_sync_runs tables via the same normalization + upsert path as
 * live data. Fully DETERMINISTIC (no rand): re-running produces identical numbers. Everything is
 * flagged is_demo=true and lives in the demo tenant only; php artisan demo:remove clears it.
 *
 * Narrative (last 90 days, "متجر تجريبي" store project, 250,000 SAR):
 *   - Google  → best ROAS      - Snapchat → best CPA
 *   - TikTok  → traffic, low CVR - Meta → high sales volume
 *   - one budget-burner (spend, ~no conversions); one seasonal ramp; last 3 days shift (late attribution).
 */
final class DemoAnalyticsSeeder extends Seeder
{
    private const DAYS = 90;

    private const PROJECT_CURRENCY = 'SAR';

    private const DEMO_UUID_NS = 'campaignshub-demo-analytics';

    /** archetype: [provider, name, ctr, cpc(orig currency), cvr, aov, spendScale, currency, phase, seasonal, note] */
    /**
     * The demo campaigns, grouped by platform IN THE PRODUCT'S ORDER (PLATFORM-ORDER-001).
     *
     * Demo data is the first thing a reviewer sees, and a demo whose platform sequence differs from
     * the live product teaches the wrong shape. The rows, the figures and the `phase` ordinals are
     * untouched — only which platform's block comes first.
     */
    private const CAMPAIGNS = [
        ['snapchat', 'Snap — Story Ads', 0.014, 0.9, 0.055, 300, 1.1, 'SAR', 0, false, 'best_cpa'],
        ['snapchat', 'Snap — Collection', 0.016, 1.1, 0.060, 320, 1.0, 'SAR', 1, false, 'best_cpa'],
        ['snapchat', 'Snap — Dynamic', 0.013, 1.0, 0.048, 290, 0.9, 'SAR', 2, false, null],
        ['tiktok', 'TikTok — Spark Ads', 0.030, 0.7, 0.018, 260, 1.3, 'SAR', 0, false, 'traffic'],
        ['tiktok', 'TikTok — TopView', 0.045, 0.8, 0.012, 240, 1.6, 'SAR', 1, false, 'traffic'],
        ['tiktok', 'TikTok — Retargeting', 0.026, 0.75, 0.028, 270, 0.8, 'SAR', 2, false, null],
        ['meta', 'Meta — Advantage+ Shopping', 0.019, 1.3, 0.052, 340, 1.7, 'SAR', 0, false, 'volume'],
        ['meta', 'Meta — Retargeting', 0.024, 1.5, 0.070, 360, 1.1, 'SAR', 1, false, 'volume'],
        ['meta', 'Meta — Prospecting Broad', 0.015, 1.1, 0.038, 320, 1.5, 'SAR', 2, false, null],
        ['meta', 'Meta — Lead Gen (burner)', 0.021, 1.6, 0.0009, 0, 1.2, 'SAR', 3, false, 'burner'],
        ['google', 'Google Search — Brand', 0.085, 0.90, 0.075, 420, 1.0, 'USD', 0, false, 'best_roas'],
        ['google', 'Google Shopping — Catalog', 0.020, 0.72, 0.045, 380, 1.4, 'USD', 1, false, 'best_roas'],
        ['google', 'Google PMax — Prospecting', 0.012, 0.58, 0.030, 350, 1.2, 'USD', 2, false, null],
        ['snapchat', 'Snap — White Friday (seasonal)', 0.017, 1.0, 0.058, 380, 1.4, 'SAR', 1, true, 'seasonal'],
        ['meta', 'Meta — White Friday (seasonal)', 0.020, 1.35, 0.055, 400, 1.9, 'SAR', 2, true, 'seasonal'],
    ];

    public function run(): void
    {
        if (! App::environment(['local', 'testing', 'demo'])) {
            return;
        }

        $tenant = Tenant::firstOrCreate(['slug' => 'demo-agency'], ['name' => 'Demo Agency', 'status' => 'active']);
        app(TenantContext::class)->setTenantId((string) $tenant->id);

        // Trashed-safe: restore a soft-deleted demo workspace instead of colliding on the unique slug.
        $ws = ClientWorkspace::withTrashed()->firstOrNew(['tenant_id' => $tenant->id, 'slug' => 'demo-store-analytics']);
        $ws->fill(['name' => 'Demo Store — Analytics', 'mode' => 'managed', 'branding' => ['brand_name' => 'Demo Store']]);
        if ($ws->trashed()) {
            $ws->restore();
        }
        $ws->save();
        $project = Project::firstOrCreate(
            ['client_workspace_id' => $ws->id, 'name' => 'متجر تجريبي — Demo'],
            ['status' => 'active', 'setup_completion' => 100],
        );

        $this->seedCurrencyRates();

        $today = Carbon::today();
        $start = $today->copy()->subDays(self::DAYS - 1);
        $converter = app(CurrencyConverter::class);
        $upsert = app(UpsertDailyMetrics::class);

        // A representative sync run per platform (success + one failed + one late) for the sync-health UI.
        $this->seedSyncRuns((string) $tenant->id, (string) $project->id, $start, $today);

        $metrics = [];
        foreach (self::CAMPAIGNS as $i => [$provider, $name, $ctr, $cpc, $cvr, $aov, $spendScale, $currency, $phase, $seasonal, $note]) {
            $campaign = UnifiedCampaign::firstOrCreate(
                ['project_id' => $project->id, 'name' => $name],
                [
                    'client_workspace_id' => $ws->id,
                    'objective' => 'sales',
                    'status' => $i % 9 === 4 ? 'paused' : 'active',
                    'total_budget' => 250000 / count(self::CAMPAIGNS),
                    'budget_currency' => self::PROJECT_CURRENCY,
                    'meta' => ['is_demo' => true, 'primary_platform' => $provider],
                ],
            );
            $externalCampaignId = $this->demoUuid("camp:{$provider}:{$name}");
            $externalAccountId = $this->demoUuid("acct:{$provider}");

            for ($d = 0; $d < self::DAYS; $d++) {
                $date = $start->copy()->addDays($d);
                $impr = $this->impressions($i, $d, $spendScale, $phase, $seasonal);
                if ($impr <= 0) {
                    continue;
                }
                $clicks = $impr * $ctr;
                // Late attribution: last 3 days show only ~55% of conversions/revenue (not yet attributed).
                $lateFactor = $d >= self::DAYS - 3 ? 0.55 + 0.15 * ($d - (self::DAYS - 3)) : 1.0;
                $conv = $clicks * $cvr * 0.42 * $lateFactor;
                $spendOrig = $clicks * $cpc;
                $revenueOrig = $conv * $aov;

                $rate = $converter->rateFor($currency, self::PROJECT_CURRENCY, $date);
                $add = function (string $key, float $value, ?float $origAmount = null) use (
                    &$metrics, $tenant, $project, $externalAccountId, $externalCampaignId, $campaign,
                    $provider, $date, $currency, $rate, $note
                ) {
                    $isMoney = $origAmount !== null;
                    $metrics[] = new NormalizedMetric(
                        tenantId: (string) $tenant->id,
                        projectId: (string) $project->id,
                        externalAccountId: $externalAccountId,
                        externalCampaignId: $externalCampaignId,
                        provider: $provider,
                        metricKey: $key,
                        metricDate: $date,
                        value: round($isMoney ? $origAmount * $rate : $value, 4),
                        unifiedCampaignId: (string) $campaign->id,
                        originalCurrency: $isMoney ? $currency : null,
                        projectCurrency: $isMoney ? self::PROJECT_CURRENCY : null,
                        originalAmount: $isMoney ? round($origAmount, 4) : null,
                        convertedAmount: $isMoney ? round($origAmount * $rate, 4) : null,
                        exchangeRate: $isMoney ? $rate : null,
                        originalTimezone: 'UTC',
                        projectTimezone: 'Asia/Riyadh',
                        attributionWindow: '7d_click_1d_view',
                        sourceType: 'api',
                        dataFreshnessAt: $date->copy()->endOfDay(),
                        raw: ['note' => $note],
                        isDemo: true,
                    );
                };

                // Conversion funnel with realistic stage drop-off (deterministic).
                $landing = $clicks * 0.86;
                $addToCart = $landing * 0.34;
                $checkout = $addToCart * 0.52;

                $add('impressions', round($impr));
                $add('clicks', round($clicks));
                $add('landing_page_views', round($landing));
                $add('add_to_cart', round($addToCart));
                $add('checkout', round($checkout));
                $add('conversions', round($conv, 2));
                /*
                 * `purchases` as well as `conversions`, because a store reports both and they are not
                 * the same column.
                 *
                 * The funnel labels `conversions` «Purchase», so the seeded story looked complete — but
                 * anything reading the `purchases` metric key found nothing and rendered a zero. On the
                 * client's live report that put «Purchases: 0» directly beside «Add to cart: 17,812»,
                 * which reads as a catastrophic checkout failure rather than as a metric nobody wrote.
                 *
                 * Equal to conversions here because for this demo store the conversion IS the purchase.
                 * A product with several conversion events would seed them apart.
                 */
                $add('purchases', round($conv, 2));
                $add('spend', 0, $spendOrig);
                $add('revenue', 0, $revenueOrig);
            }
        }

        $upsert->handle($metrics);
        app(TenantContext::class)->forget();
    }

    /** Deterministic daily impressions: archetype base × seasonal ramp × weekday rhythm (no rand). */
    private function impressions(int $campaignIdx, int $day, float $scale, int $phase, bool $seasonal): float
    {
        $base = 3600 * $scale;
        // Smooth seasonal wave + a gentle upward trend, phased per campaign.
        $wave = 1 + 0.28 * sin(($day + $phase * 9) / 14.0) + 0.12 * cos(($day + $phase * 5) / 6.0);
        $trend = 1 + 0.15 * ($day / self::DAYS);
        // Weekend rhythm (day 0 = 90 days ago; use modulo as a stable weekday proxy).
        $weekday = ($day + $phase) % 7;
        $weekend = in_array($weekday, [5, 6], true) ? 0.82 : 1.0;
        // Seasonal campaigns ramp hard in the final third (White Friday build-up).
        $seasonalRamp = $seasonal ? (1 + max(0, ($day - 55) / 12.0)) : 1.0;

        return max(0, $base * $wave * $trend * $weekend * $seasonalRamp);
    }

    private function seedCurrencyRates(): void
    {
        // USD→SAR is effectively pegged; seed a stable rate at the window start so every date resolves.
        CurrencyRate::updateOrCreate(
            ['base_currency' => 'USD', 'quote_currency' => 'SAR', 'rate_date' => Carbon::today()->subDays(self::DAYS)->toDateString()],
            ['rate' => 3.7500, 'source' => 'demo'],
        );
    }

    private function seedSyncRuns(string $tenantId, string $projectId, Carbon $start, Carbon $today): void
    {
        $runs = [
            ['meta', 'success', $today, 2400, null],
            ['google', 'success', $today, 1800, null],
            ['tiktok', 'success', $today, 1600, null],
            ['snapchat', 'partial', $today, 900, 'Rate limited on 1 account; retried.'],
            ['meta', 'failed', $today->copy()->subDays(1), 0, 'Token expired — reconnect required.'],
        ];
        foreach ($runs as [$provider, $status, $day, $upserted, $error]) {
            MetricSyncRun::updateOrCreate(
                ['id' => $this->demoUuid("run:{$provider}:{$status}:{$day->toDateString()}")],
                [
                    'tenant_id' => $tenantId,
                    'project_id' => $projectId,
                    'provider' => $provider,
                    'status' => $status,
                    'window_start' => $start->toDateString(),
                    'window_end' => $today->toDateString(),
                    'metrics_upserted' => $upserted,
                    'attempts' => $status === 'failed' ? 3 : 1,
                    'started_at' => $day->copy()->subMinutes(4),
                    'finished_at' => $day,
                    'error' => $error,
                    'is_demo' => true,
                ],
            );
        }
    }

    /** Stable UUIDv5 so re-seeding reuses the same synthetic ids (idempotent natural key). */
    private function demoUuid(string $seed): string
    {
        $ns = Uuid::uuid5(Uuid::NAMESPACE_DNS, self::DEMO_UUID_NS)->toString();

        return Uuid::uuid5($ns, $seed)->toString();
    }
}
