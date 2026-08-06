<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * §15.1 — the canonical creative, and metrics that can say «the platform does not report this».
 *
 * A creative was a name, a format, a thumbnail and a status: enough to list, not enough to analyse.
 * The product's promise is «كل حملاتك الإعلانية المدفوعة في مكان واحد», and an ad whose copy,
 * headline, CTA, dimensions, duration and destination live only inside Meta's UI cannot be compared
 * with the same asset running on TikTok.
 *
 * ## Three changes, and why each is shaped this way
 *
 * **1. The canonical fields.** Everything a platform is willing to say about an ad, printed onto one
 * model. `raw` keeps the provider's own payload verbatim beside it — the printed columns are what the
 * product reasons about, and the raw block is what makes a mis-mapping recoverable without a re-sync.
 *
 * **2. Video metrics that are NULLABLE.** `video_views` and `video_completions` existed as
 * `NOT NULL DEFAULT 0`, so «this platform does not report video quartiles» and «nobody watched it»
 * were the same row. That is the exact failure §15.4 forbids: a completion rate of 0% next to 40,000
 * impressions reads as a catastrophically bad video rather than as a metric nobody sent. Every video
 * column here is nullable, and the existing two are widened to match.
 *
 * The DEFAULT is dropped rather than kept, deliberately: a default of 0 on a nullable column means an
 * ingester that omits the key writes a zero anyway, which is the same lie with an extra step.
 *
 * **3. Creative groups.** The same image runs on Snapchat, TikTok and Meta as three provider rows
 * with three ids. A group is what lets one asset be read as one asset. Membership is a column on the
 * creative rather than a pivot table because a creative belongs to at most one group, and the METHOD
 * is stored beside it: automatic grouping by file hash is evidence, grouping by filename is a guess,
 * and §15.8 forbids ever finalising the second on its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creative_groups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('project_id')->nullable()->index();
            $table->string('name', 200)->nullable();

            /*
             * How this group was formed — evidence, not decoration.
             *
             * `file_hash` and `thumbnail_fingerprint` are provable; `manual` is a person's judgement;
             * `confirmed` is a person agreeing with an automatic match. The UI shows the method, and
             * `filename` is deliberately NOT a value here: §15.8 forbids finalising a merge on a
             * filename alone, so it cannot be written as a settled reason.
             */
            $table->string('method', 32)->default('manual');
            $table->string('fingerprint', 128)->nullable()->index();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
        });

        Schema::table('external_creatives', function (Blueprint $table): void {
            // Where it sits in the platform's own structure.
            $table->uuid('external_ad_set_id')->nullable()->after('external_campaign_id')->index();

            // The asset itself.
            $table->string('asset_url', 2048)->nullable()->after('preview_url');
            $table->string('video_url', 2048)->nullable()->after('asset_url');
            $table->unsignedInteger('width')->nullable()->after('video_url');
            $table->unsignedInteger('height')->nullable()->after('width');
            $table->string('aspect_ratio', 16)->nullable()->after('height');
            $table->unsignedInteger('duration_seconds')->nullable()->after('aspect_ratio');
            $table->unsignedBigInteger('file_size')->nullable()->after('duration_seconds');
            $table->string('file_hash', 128)->nullable()->after('file_size')->index();

            // What it says. `body` is long-form; the rest are short by every platform's own limits.
            $table->text('body')->nullable()->after('file_hash');
            $table->string('headline', 500)->nullable()->after('body');
            $table->string('description', 1000)->nullable()->after('headline');
            $table->string('cta', 64)->nullable()->after('description');

            // When it lived. `first_seen_at` is the platform's, not ours — an ad that has been running
            // for ninety days is a different thing from one we happened to discover yesterday.
            $table->timestamp('first_seen_at')->nullable()->after('cta');
            $table->timestamp('last_active_at')->nullable()->after('first_seen_at');
            $table->timestamp('source_updated_at')->nullable()->after('last_active_at');

            /*
             * A temporary URL is not an asset (§15.1).
             *
             * Platform preview and asset links expire, often within hours, and storing one as though it
             * were permanent produces a library of broken images a week later. The expiry is recorded so
             * a reader can be told «this preview needs a refresh» instead of being shown a dead frame.
             */
            $table->timestamp('asset_expires_at')->nullable()->after('source_updated_at');

            $table->jsonb('raw')->nullable()->after('asset_expires_at');

            $table->uuid('creative_group_id')->nullable()->after('raw')->index();
            $table->foreign('creative_group_id')->references('id')->on('creative_groups')->nullOnDelete();
        });

        Schema::table('creative_daily_metrics', function (Blueprint $table): void {
            // Funnel stages a creative can genuinely be credited with.
            $table->decimal('add_to_cart', 18, 4)->nullable()->after('conversions');
            $table->decimal('checkout', 18, 4)->nullable()->after('add_to_cart');
            $table->decimal('purchases', 18, 4)->nullable()->after('checkout');
            $table->decimal('landing_page_views', 18, 4)->nullable()->after('purchases');
            $table->decimal('engagements', 18, 4)->nullable()->after('landing_page_views');
            $table->decimal('reach', 18, 4)->nullable()->after('engagements');
            $table->decimal('frequency', 12, 4)->nullable()->after('reach');

            // Video, all nullable — see the class note. A platform that reports none of these leaves
            // every column null, and the reader is told «Not provided» rather than shown a zero.
            $table->decimal('video_views_2s', 18, 4)->nullable()->after('video_completions');
            $table->decimal('video_views_3s', 18, 4)->nullable()->after('video_views_2s');
            $table->decimal('video_views_6s', 18, 4)->nullable()->after('video_views_3s');
            $table->decimal('video_p25', 18, 4)->nullable()->after('video_views_6s');
            $table->decimal('video_p50', 18, 4)->nullable()->after('video_p25');
            $table->decimal('video_p75', 18, 4)->nullable()->after('video_p50');
            $table->decimal('video_p100', 18, 4)->nullable()->after('video_p75');
            $table->decimal('video_avg_watch_seconds', 12, 4)->nullable()->after('video_p100');
        });

        // The two that already existed: widen so «not reported» is expressible at all.
        DB::statement('ALTER TABLE creative_daily_metrics ALTER COLUMN video_views DROP NOT NULL');
        DB::statement('ALTER TABLE creative_daily_metrics ALTER COLUMN video_views DROP DEFAULT');
        DB::statement('ALTER TABLE creative_daily_metrics ALTER COLUMN video_completions DROP NOT NULL');
        DB::statement('ALTER TABLE creative_daily_metrics ALTER COLUMN video_completions DROP DEFAULT');
    }

    public function down(): void
    {
        DB::statement("UPDATE creative_daily_metrics SET video_views = 0 WHERE video_views IS NULL");
        DB::statement("UPDATE creative_daily_metrics SET video_completions = 0 WHERE video_completions IS NULL");
        DB::statement("ALTER TABLE creative_daily_metrics ALTER COLUMN video_views SET DEFAULT 0");
        DB::statement('ALTER TABLE creative_daily_metrics ALTER COLUMN video_views SET NOT NULL');
        DB::statement("ALTER TABLE creative_daily_metrics ALTER COLUMN video_completions SET DEFAULT 0");
        DB::statement('ALTER TABLE creative_daily_metrics ALTER COLUMN video_completions SET NOT NULL');

        Schema::table('creative_daily_metrics', function (Blueprint $table): void {
            $table->dropColumn([
                'add_to_cart', 'checkout', 'purchases', 'landing_page_views', 'engagements', 'reach', 'frequency',
                'video_views_2s', 'video_views_3s', 'video_views_6s',
                'video_p25', 'video_p50', 'video_p75', 'video_p100', 'video_avg_watch_seconds',
            ]);
        });

        Schema::table('external_creatives', function (Blueprint $table): void {
            $table->dropForeign(['creative_group_id']);
            $table->dropColumn([
                'external_ad_set_id', 'asset_url', 'video_url', 'width', 'height', 'aspect_ratio',
                'duration_seconds', 'file_size', 'file_hash', 'body', 'headline', 'description', 'cta',
                'first_seen_at', 'last_active_at', 'source_updated_at', 'asset_expires_at', 'raw',
                'creative_group_id',
            ]);
        });

        Schema::dropIfExists('creative_groups');
    }
};
