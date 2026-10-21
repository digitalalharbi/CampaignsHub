<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REPORT-LINK-PAUSE-001 — a shared link can be paused and resumed, unlike a revoked one.
 *
 * Revoking is a permanent cut-off (the link cannot be renewed or edited). The Owner needs the other thing too:
 * switch a link off now and keep its settings, its URL and its history, then switch it back on. A separate
 * column keeps the two decisions apart, so resuming can never undo a revocation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_shares', function (Blueprint $table): void {
            $table->timestampTz('paused_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('report_shares', function (Blueprint $table): void {
            $table->dropColumn('paused_at');
        });
    }
};
