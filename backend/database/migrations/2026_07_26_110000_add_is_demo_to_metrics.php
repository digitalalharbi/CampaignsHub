<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flags rows created by the demo analytics seeder so they can be listed and removed independently
 * of any future real data (php artisan demo:remove), and never leak into production reporting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_metrics', function (Blueprint $table) {
            $table->boolean('is_demo')->default(false)->index();
        });
        Schema::table('metric_sync_runs', function (Blueprint $table) {
            $table->boolean('is_demo')->default(false)->index();
        });
    }

    public function down(): void
    {
        Schema::table('daily_metrics', function (Blueprint $table) {
            $table->dropColumn('is_demo');
        });
        Schema::table('metric_sync_runs', function (Blueprint $table) {
            $table->dropColumn('is_demo');
        });
    }
};
