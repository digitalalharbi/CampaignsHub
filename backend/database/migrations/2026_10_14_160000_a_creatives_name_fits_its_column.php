<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TIKTOK-CONTENT-001 — a creative's name fits its column.
 *
 * `2026_10_12_150000` widened the AD's name and landing page after TikTok's structure sweep failed on
 * «value too long for type character varying(255)». The creative is written first, in the same
 * transaction, and TikTok names it after the ad (`ad_name`, up to 512 characters) — so one long ad
 * name still rolled back the whole account's ad sets, ads and creatives. Widened to the same sizes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE external_creatives ALTER COLUMN name TYPE varchar(1024)');
    }

    public function down(): void {}
};
