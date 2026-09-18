<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ACCOUNT-SCOPE-ISOLATION-001 · CREATIVE-ACCOUNT-IDENTITY-001 — a creative's identity includes its account.
 *
 * Campaigns, ad sets and ads were account-scoped; creatives were not. `external_creatives` was unique
 * on `(project_id, provider, external_creative_id)`, so two accounts of one provider in one project
 * that report the same creative id collapsed into ONE row, the last import owning it and the other
 * account's creative figures skipped. The Production inventory of 2026-09-17 counted zero such
 * creatives, so this backfill moves no row between owners — it closes the shape before it happens.
 *
 * Nullable, because demo and estimated creatives are written without an account. The key becomes:
 * account-scoped where an account is known, and the old key where it is not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_creatives', function (Blueprint $table): void {
            $table->foreignUuid('external_account_id')->nullable()->after('provider')
                ->constrained('external_accounts')->nullOnDelete();
        });

        DB::statement(<<<'SQL'
            UPDATE external_creatives c
               SET external_account_id = ec.external_account_id
              FROM external_campaigns ec
             WHERE ec.id = c.external_campaign_id
               AND c.external_account_id IS NULL
        SQL);

        Schema::table('external_creatives', function (Blueprint $table): void {
            $table->dropUnique(['project_id', 'provider', 'external_creative_id']);
        });

        DB::statement('CREATE UNIQUE INDEX external_creatives_account_identity_unique ON external_creatives (project_id, provider, external_account_id, external_creative_id) WHERE external_account_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX external_creatives_accountless_identity_unique ON external_creatives (project_id, provider, external_creative_id) WHERE external_account_id IS NULL');
    }

    public function down(): void
    {
        $collisions = DB::table('external_creatives')
            ->select('project_id', 'provider', 'external_creative_id')
            ->groupBy('project_id', 'provider', 'external_creative_id')
            ->havingRaw('count(*) > 1')
            ->count();

        // Restoring the account-blind key would have to delete one account's creative. Refuse instead.
        if ($collisions > 0) {
            throw new RuntimeException("{$collisions} creative id(s) are held by more than one account; the account-blind key cannot be restored without discarding one of them.");
        }

        DB::statement('DROP INDEX IF EXISTS external_creatives_account_identity_unique');
        DB::statement('DROP INDEX IF EXISTS external_creatives_accountless_identity_unique');

        Schema::table('external_creatives', function (Blueprint $table): void {
            $table->unique(['project_id', 'provider', 'external_creative_id']);
            $table->dropConstrainedForeignId('external_account_id');
        });
    }
};
