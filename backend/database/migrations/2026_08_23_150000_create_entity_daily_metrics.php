<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * METRICS-BACKBONE-001 — daily metrics for the two rungs that had none.
 *
 * ## The gap this closes
 *
 * The product could report at exactly two grains: `daily_metrics` is keyed by CAMPAIGN and
 * `creative_daily_metrics` by CREATIVE. Between them sit 187 ad squads and 5,706 ads on the live
 * account with **no metrics storage of any kind**, which is why Analytics has no Ad Set tab and no
 * Ads tab: not a missing screen, a missing table.
 *
 * ## One table for both rungs, not two
 *
 * Ad squads and ads carry the SAME measures from the same provider call shape, so two tables would
 * be one schema written twice and two ingest paths to keep in step. `entity_type` distinguishes
 * them, and the natural key `(entity_type, entity_id, metric_date, attribution_window)` is the same
 * key `daily_metrics` uses one rung up — this is the existing architecture extended, not a second
 * metrics system beside it.
 *
 * ## Money carries its provenance, as it must everywhere now
 *
 * `spend` and `revenue` are NULLABLE with `*_original` and `original_currency` beside them, exactly
 * as CREATIVE-MONEY-TRUTH-001 left `creative_daily_metrics`. A money column that defaults to 0 is
 * how «no rate exists» became «spent nothing», and this table is built after that lesson rather
 * than before it. Snapchat reports in the ad account's currency (USD on the live account) and the
 * project reports in SAR, so every row written today is withheld — correctly.
 *
 * ## Provenance is not optional
 *
 * `is_demo` so DEMO-LIVE-AGGREGATION-ISOLATION-001 can keep seeded rows out of operational totals,
 * and `sync_run_id` so a figure can always be traced to the call that produced it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_daily_metrics', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('tenant_id')->index();
            $table->uuid('project_id')->index();
            $table->uuid('external_account_id')->nullable()->index();
            $table->string('provider', 32)->index();

            // 'ad_set' | 'ad'. The rung this row measures.
            $table->string('entity_type', 16);
            // Our own id for the entity — external_ad_sets.id or external_ads.id.
            $table->uuid('entity_id');
            // The platform's id, kept so a figure can be reconciled against the provider by hand.
            $table->string('external_entity_id');
            // The parents, for rollup and for drill-down without a recursive walk.
            $table->uuid('external_campaign_id')->nullable()->index();
            $table->uuid('external_ad_set_id')->nullable()->index();

            $table->date('metric_date');
            /*
             * The attribution basis this row was measured on. Two windows are two different
             * measurements of the same day and must never be summed together — the natural key
             * carries it for that reason.
             */
            $table->string('attribution_window', 32)->default('default');

            // ── delivery ──────────────────────────────────────────────────────────────────────
            $table->decimal('impressions', 24, 6)->nullable();
            $table->decimal('reach', 24, 6)->nullable();
            $table->decimal('frequency', 12, 6)->nullable();

            // ── money: withheld rather than wrong ─────────────────────────────────────────────
            $table->decimal('spend', 24, 6)->nullable();
            $table->decimal('spend_original', 24, 6)->nullable();
            $table->decimal('revenue', 24, 6)->nullable();
            $table->decimal('revenue_original', 24, 6)->nullable();
            $table->string('original_currency', 3)->nullable();
            $table->string('project_currency', 3)->nullable();

            // ── traffic ───────────────────────────────────────────────────────────────────────
            $table->decimal('clicks', 24, 6)->nullable();
            $table->decimal('landing_page_views', 24, 6)->nullable();

            // ── engagement ────────────────────────────────────────────────────────────────────
            $table->decimal('engagements', 24, 6)->nullable();

            // ── video ─────────────────────────────────────────────────────────────────────────
            $table->decimal('video_views', 24, 6)->nullable();
            $table->decimal('video_views_2s', 24, 6)->nullable();
            $table->decimal('video_views_5s', 24, 6)->nullable();
            $table->decimal('video_views_15s', 24, 6)->nullable();
            $table->decimal('video_p25', 24, 6)->nullable();
            $table->decimal('video_p50', 24, 6)->nullable();
            $table->decimal('video_p75', 24, 6)->nullable();
            $table->decimal('video_p100', 24, 6)->nullable();
            $table->decimal('video_watch_seconds', 24, 6)->nullable();

            // ── results ───────────────────────────────────────────────────────────────────────
            $table->decimal('conversions', 24, 6)->nullable();
            $table->decimal('purchases', 24, 6)->nullable();
            $table->decimal('add_to_cart', 24, 6)->nullable();
            $table->decimal('checkout', 24, 6)->nullable();
            $table->decimal('leads', 24, 6)->nullable();
            $table->decimal('sign_ups', 24, 6)->nullable();
            $table->decimal('installs', 24, 6)->nullable();
            $table->decimal('app_opens', 24, 6)->nullable();
            $table->decimal('page_views', 24, 6)->nullable();

            // ── provenance ────────────────────────────────────────────────────────────────────
            $table->boolean('is_demo')->default(false)->index();
            $table->uuid('sync_run_id')->nullable()->index();

            $table->timestamps();

            /*
             * Idempotent by the table's own key: attribution keeps moving for days after the fact,
             * so the same window is re-fetched constantly and must correct in place rather than
             * doubling.
             */
            $table->unique(
                ['entity_type', 'entity_id', 'metric_date', 'attribution_window'],
                'entity_daily_metrics_natural_key'
            );

            // The shape every read uses: one project, one grain, a date range.
            $table->index(['project_id', 'entity_type', 'metric_date'], 'entity_daily_metrics_read');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_daily_metrics');
    }
};
