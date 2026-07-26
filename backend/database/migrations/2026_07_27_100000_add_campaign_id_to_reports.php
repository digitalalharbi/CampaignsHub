<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional link from a report to a single campaign, so the Campaign Command Center's Reports tab
 * (CMC-13) can list the reports that belong to this campaign. Additive + nullable; project reports
 * simply leave it null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->uuid('campaign_id')->nullable()->index()->after('project_id');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn('campaign_id');
        });
    }
};
