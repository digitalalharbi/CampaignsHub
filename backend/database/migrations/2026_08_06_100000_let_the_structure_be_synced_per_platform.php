<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * STRUCT-001 — two schema facts the structure sync ran into, both of them about a real platform.
 *
 * ## 1. LinkedIn has no ad-set level, and pretending otherwise would invent one
 *
 * Five of the six platforms are three levels deep: campaign → ad set (ad group, ad squad, line item)
 * → ad. LinkedIn is not. Its hierarchy is campaign group → campaign → creative, and what this product
 * already calls an external campaign is a LinkedIn *campaign* — so beneath it there is a creative and
 * nothing else. The generic model would fabricate one ad set per campaign to fill the gap, and a row
 * the platform never returned is exactly the kind of invented structure the honesty rules forbid.
 *
 * So `external_ad_set_id` becomes nullable, and an ad may hang directly off its campaign.
 *
 * That breaks the old uniqueness guarantee: Postgres treats NULLs as distinct, so
 * `unique(external_ad_set_id, external_id)` stops preventing anything the moment the ad-set is null,
 * and a re-sync would insert every LinkedIn creative again on every run. The replacement —
 * `unique(external_campaign_id, external_id)` — is strictly STRONGER than the old one for the rows it
 * replaces, because an ad set belongs to exactly one campaign, so uniqueness within a campaign
 * implies uniqueness within the ad set.
 *
 * ## 2. Structure and metrics are synced on different clocks
 *
 * `last_synced_at` on an ad account has always meant "we last pulled numbers". Structure moves at a
 * different pace — a new ad set appears a few times a week, a day's spend is restated for a week — so
 * they run on separate schedules, and one column reporting both would report whichever ran last.
 * An operator asking «هل ظهرت المجموعة الجديدة؟» is asking about this column, not that one.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Postgres will not drop a unique constraint through `dropUnique` when it was created as an
        // index; both spellings are attempted so this runs on a database built either way.
        DB::statement('ALTER TABLE external_ads DROP CONSTRAINT IF EXISTS external_ads_external_ad_set_id_external_id_unique');
        DB::statement('DROP INDEX IF EXISTS external_ads_external_ad_set_id_external_id_unique');

        Schema::table('external_ads', function (Blueprint $table): void {
            $table->uuid('external_ad_set_id')->nullable()->change();
            $table->unique(['external_campaign_id', 'external_id']);
        });

        Schema::table('external_accounts', function (Blueprint $table): void {
            $table->timestampTz('last_structure_synced_at')->nullable()->after('last_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('external_accounts', function (Blueprint $table): void {
            $table->dropColumn('last_structure_synced_at');
        });

        Schema::table('external_ads', function (Blueprint $table): void {
            $table->dropUnique(['external_campaign_id', 'external_id']);
        });

        // Rows added since the change may have a null ad set, which the old NOT NULL cannot hold.
        DB::table('external_ads')->whereNull('external_ad_set_id')->delete();

        Schema::table('external_ads', function (Blueprint $table): void {
            $table->uuid('external_ad_set_id')->nullable(false)->change();
            $table->unique(['external_ad_set_id', 'external_id']);
        });
    }
};
