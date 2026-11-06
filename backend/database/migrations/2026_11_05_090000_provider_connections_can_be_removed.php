<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INTEG-SOURCE-REMOVAL-001 — when an operator removed a source from the Connection Hub.
 *
 * A soft hide, not a delete: `external_accounts`, `daily_metrics`, reports and the audit trail all hang off
 * the connection row with cascading foreign keys, so deleting it would delete the history. A removed
 * connection keeps every row; this column only says «do not list it, do not sync it».
 *
 * (`uninstalled_at` is the Salla commerce retention clock — a different fact with a purge attached.)
 *
 * Additive and nullable: every existing row stays NULL, which reads «listed», exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_connections', function (Blueprint $table): void {
            $table->timestampTz('removed_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('provider_connections', function (Blueprint $table): void {
            $table->dropIndex(['removed_at']);
            $table->dropColumn('removed_at');
        });
    }
};
