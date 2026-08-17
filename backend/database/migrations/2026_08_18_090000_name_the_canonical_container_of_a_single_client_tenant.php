<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PROJECT-CREATE-WORKSPACE-001 — one flag, so «which container is the advertiser's own?» has an
 * answer that cannot drift.
 *
 * ## Why a column rather than a rule
 *
 * `projects.client_workspace_id` is NOT NULL and a `client_workspaces` row is an AGENCY's client, so
 * an advertiser — who has no clients — still needs exactly one container to hold their own work. The
 * shape of the data almost answers it: a tenant with one workspace has an unambiguous container.
 * Almost, because the day that advertiser adds a second workspace the shape stops answering, and
 * project creation would break for a reason nobody could see from the request.
 *
 * The flag pins the decision at the moment it is unambiguous and keeps it true afterwards. It is
 * NOT «the first workspace»: `resolve()` refuses to choose when more than one exists and no flag has
 * been set, which is exactly the guess this whole change exists to remove.
 *
 * ## Additive and non-destructive
 *
 * A nullable-defaulted boolean and a PARTIAL unique index. Nothing is dropped, nothing is rewritten,
 * and no existing row is marked: adoption happens the first time a single-client tenant resolves its
 * container, under a lock, in application code that can see the tenant's account type. Backfilling
 * here would have to guess for every tenant at once with none of that context.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_workspaces', function (Blueprint $table): void {
            $table->boolean('is_canonical')->default(false);
        });

        /*
         * The backstop, in the database rather than in a comment.
         *
         * Two concurrent «create my first project» requests both find no canonical container and both
         * try to adopt one. The application serialises them on the tenant row; this makes the second
         * insert fail loudly if that lock is ever bypassed, instead of leaving a tenant with two
         * containers that both claim to be the only one.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS client_workspaces_canonical_unique
            ON client_workspaces (tenant_id)
            WHERE is_canonical = true AND deleted_at IS NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS client_workspaces_canonical_unique');

        Schema::table('client_workspaces', function (Blueprint $table): void {
            $table->dropColumn('is_canonical');
        });
    }
};
