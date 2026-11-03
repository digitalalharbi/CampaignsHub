<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REACH-PERIOD-001 — a stored window whose delivery changed after it was fetched is marked stale, not
 * re-fetched by the sync. The reader serves the stored figure and re-requests it lazily when read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('period_reach', function (Blueprint $table): void {
            $table->timestamp('stale_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('period_reach', function (Blueprint $table): void {
            $table->dropColumn('stale_at');
        });
    }
};
