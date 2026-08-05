<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keep what the platform actually said, whatever anybody does afterwards (REPORT-OBJECTIVE-002).
 *
 * `objective` holds OUR classification and `objective_source` says who decided it. Neither preserves
 * the platform's own string — `OUTCOME_SALES`, `RF_VIDEO_VIEWS`, `PRODUCT_AND_BRAND_CONSIDERATION` —
 * and once a person corrects a campaign, that string is gone.
 *
 * It is worth a column because it is the difference between two situations an operator has to tell
 * apart: «the platform reported this and it is wrong» and «the platform never reported anything».
 * The first is a mapping gap somebody should fix centrally so every campaign benefits; the second is
 * one campaign that needs a person. With only `source` recorded, both read as `unset`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unified_campaigns', function (Blueprint $table): void {
            $table->string('objective_platform_value', 120)->nullable()->after('objective_source');
        });
    }

    public function down(): void
    {
        Schema::table('unified_campaigns', function (Blueprint $table): void {
            $table->dropColumn('objective_platform_value');
        });
    }
};
