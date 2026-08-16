<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ORCH-100 — discovery is not a sync, and an active assignment is not a free-for-all.
 *
 * ## Additive only, because production holds real evidence
 *
 * A live Snapchat connection exists with real encrypted tokens and **309 discovered ad accounts**.
 * Nothing here drops a column, deletes a row or rewrites an external id. One column is added, one
 * partial index is added, and two backfills correct a claim that was never true.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_accounts', function (Blueprint $table): void {
            // DISCOVERY-NOT-SYNC-001. `last_synced_at` was being stamped at discovery, so the product
            // told an operator «آخر مزامنة: الآن» about an account whose data it had never fetched.
            // The two are different facts and now have different columns.
            $table->timestampTz('discovered_at')->nullable()->after('status');

            // ACCESS-LOST-001 (requirement M): an account that vanishes from a provider's access is
            // not deleted — it is marked, kept, and stops syncing. Deleting it would take its
            // history and any report pointing at it with it.
            $table->timestampTz('access_lost_at')->nullable()->after('discovered_at');
        });

        /*
         * Backfill 1 — when each account was discovered.
         *
         * `created_at` IS the discovery moment: rows in this table are created by discovery and by
         * nothing else. So this is a rename of a fact we already hold, not a guess.
         */
        DB::table('external_accounts')->whereNull('discovered_at')->update([
            'discovered_at' => DB::raw('created_at'),
        ]);

        /*
         * Backfill 2 — retract the sync claim from accounts that have never synced.
         *
         * Deliberately narrow. An account is cleared ONLY when nothing anywhere shows it ever
         * produced data: no campaign filed against it, and no successful sync run on its connection.
         * An account that really has synced keeps its timestamp untouched.
         *
         * For the live connection this clears all 309, which is correct — none of them has ever
         * been assigned to a project, so none of them can have synced.
         */
        DB::table('external_accounts')
            ->whereNotNull('last_synced_at')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('external_campaigns')
                ->whereColumn('external_campaigns.external_account_id', 'external_accounts.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('integration_sync_runs')
                ->whereColumn('integration_sync_runs.provider_connection_id', 'external_accounts.provider_connection_id')
                ->where('integration_sync_runs.status', 'success'))
            ->update(['last_synced_at' => null]);

        /*
         * Z — one ACTIVE binding per (account, project).
         *
         * A partial unique index rather than a plain one, because detaching leaves the row behind:
         * two inactive bindings for the same pair are history and must stay legal, while two active
         * ones are the same assignment recorded twice.
         *
         * This is also the backstop under the quota check. A check-then-insert can be raced by two
         * concurrent confirmations; the database refusing the second is what makes the count honest
         * rather than merely usually-honest.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS project_integration_bindings_active_unique
            ON project_integration_bindings (external_account_id, project_id)
            WHERE is_active = true
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS project_integration_bindings_active_unique');

        Schema::table('external_accounts', function (Blueprint $table): void {
            $table->dropColumn(['discovered_at', 'access_lost_at']);
        });
    }
};
