<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * META-INSIGHTS-GRANT-001 — the one fact a granted-scope list cannot carry.
 *
 * `provider_connections.scopes` records what the CONSENT granted. Meta's «(#200) Ad account owner has
 * NOT grant ads_management or ads_read permission» is a different refusal: the ad account's owner
 * decided it in Business Manager, after consent, and a token whose scope list proudly contains
 * `ads_read` is refused all the same.
 *
 * So the scope list cannot answer «can this connection read insights?» on its own, and the only
 * authority is the provider's own refusal. This column remembers it, so the page can say so before
 * the next sync fails the same way rather than after.
 *
 * Nullable and self-clearing: a fresh authorisation clears it, and so does an insights sync that
 * actually succeeds. There is no stale flag to reconcile, because nothing writes it by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_connections', function (Blueprint $table): void {
            $table->timestampTz('insights_denied_at')->nullable()->after('last_error');
        });
    }

    public function down(): void
    {
        Schema::table('provider_connections', function (Blueprint $table): void {
            $table->dropColumn('insights_denied_at');
        });
    }
};
