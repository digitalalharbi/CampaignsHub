<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Internal (team-facing) campaign classification for the command center: operational stage,
 * performance label, and priority. Real, persisted, filterable, auditable columns — not React-only
 * badges. Additive and nullable; existing rows are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unified_campaigns', function (Blueprint $table) {
            $table->string('stage')->nullable()->index()->after('status');            // CampaignStage
            $table->string('performance_label')->nullable()->index()->after('stage');  // CampaignPerformanceLabel
            $table->string('priority')->default('medium')->index()->after('performance_label'); // CampaignPriority
        });
    }

    public function down(): void
    {
        Schema::table('unified_campaigns', function (Blueprint $table) {
            $table->dropColumn(['stage', 'performance_label', 'priority']);
        });
    }
};
