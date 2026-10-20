<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SALLA-ONBOARD-001 — when a merchant removed the app.
 *
 * `status = revoked` says a connection stopped; it does not say WHEN, and the retention promise («keep the
 * data 30 days, then purge») needs the date. Nullable: a connection that was never uninstalled has none, and
 * a reinstall clears it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_connections', function (Blueprint $table): void {
            $table->timestampTz('uninstalled_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('provider_connections', function (Blueprint $table): void {
            $table->dropIndex(['uninstalled_at']);
            $table->dropColumn('uninstalled_at');
        });
    }
};
