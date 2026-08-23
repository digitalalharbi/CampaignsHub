<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SNAP-MEDIA-OBSERVABILITY-001 — a media fetch that fails must stop being invisible.
 *
 * `SnapchatConnector::withMedia()` catches every `Throwable` and continues, which is right: a
 * creative with no picture is still a creative, and failing a whole structure sweep because one
 * media lookup was throttled would cost the campaigns, ad squads and ads that came back with it.
 *
 * But the containment was total. Media could fail for EVERY creative in the account, the run would
 * report `success`, and the owner would see blank cards with nothing anywhere saying why. «The
 * platform sent no media» and «we never managed to ask» produced the identical empty column.
 *
 * `metric_sync_runs` already has a `meta` column for exactly this kind of answer;
 * `integration_sync_runs` — where the structure sweep records itself — did not. Nullable JSON, so
 * every run written before this stays honestly silent rather than being backfilled into a claim.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_sync_runs', function (Blueprint $table): void {
            $table->json('meta')->nullable()->after('error');
        });
    }

    public function down(): void
    {
        Schema::table('integration_sync_runs', function (Blueprint $table): void {
            $table->dropColumn('meta');
        });
    }
};
