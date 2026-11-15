<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CONS-REPORT-READY-ONCE — the idempotency key of «Report ready» (report + version), held on the report itself.
 *
 * Additive and nullable: an existing report reads null, so its next build says «ready» once and records it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table): void {
            $table->unsignedInteger('ready_notified_version')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table): void {
            $table->dropColumn('ready_notified_version');
        });
    }
};
