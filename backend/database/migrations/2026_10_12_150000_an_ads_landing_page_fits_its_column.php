<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TikTok's structure sync failed on Production four runs in a row with «value too long for type
 * character varying(255)» while inserting into `external_ads`. An ad's landing page carries its
 * UTM parameters and click macros, and 255 characters is not enough for one; TikTok also allows
 * ad and ad-group names up to 512 characters. One long row voided the whole structure sweep, so
 * every TikTok campaign stopped growing ad sets and ads.
 *
 * Widened with plain ALTERs (no doctrine/dbal): a widening is safe on existing data.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE external_ads ALTER COLUMN destination_url TYPE varchar(2048)');
        DB::statement('ALTER TABLE external_ads ALTER COLUMN name TYPE varchar(1024)');
        DB::statement('ALTER TABLE external_ad_sets ALTER COLUMN name TYPE varchar(1024)');
    }

    /**
     * A widening is not reversible without truncating real values, so nothing is narrowed
     * back — the same honest no-op `widen_the_creative_preview_columns::down()` takes.
     */
    public function down(): void {}
};
