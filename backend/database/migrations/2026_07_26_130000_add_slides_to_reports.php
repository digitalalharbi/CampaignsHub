<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Interactive, slide-based reports: `mode` distinguishes a live (recomputed) report from a snapshot
 * (frozen at approval); `version` lets the slide-config schema evolve without breaking old reports.
 * The slide layout itself lives in reports.config (JSONB, already present).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->string('mode')->default('snapshot')->after('type'); // live | snapshot
            $table->unsignedInteger('version')->default(1)->after('mode');
            $table->string('campaign_objective')->nullable()->after('version'); // sales|awareness|traffic|leads|app_installs|video|custom
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn(['mode', 'version', 'campaign_objective']);
        });
    }
};
