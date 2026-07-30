<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Splits ENTITY SCOPE out of the membership row (ADR 0002).
 *
 * Reviewing the first cut against the scenarios it has to support found two things it could not
 * actually express:
 *
 *  1. **A user in two workspaces of the same tenant.** The unique key was
 *     (user_id, tenant_id, portal) and left `workspace_id` out, so the second workspace was rejected
 *     by the database — the exact case the membership layer exists to allow.
 *
 *  2. **An agency operator who may see several specific clients.** `client_workspace_id` was a single
 *     column, so three clients meant three membership rows: three entries in the workspace switcher
 *     for what is one job, and no way to say "these three and no others" as one fact.
 *
 * Scope is therefore its own table, a MANY relation. A membership answers "who are you here, and in
 * which portal"; a scope row answers "which entities may that reach". Absence of scope rows means
 * unrestricted within the tenant — an agency owner — while one or more rows is a hard ceiling.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_scopes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('membership_id')->index();
            // What kind of entity this row confines the membership to.
            $table->string('scope_type', 32); // client_workspace | project
            $table->uuid('scope_id');
            $table->timestampsTz();

            $table->foreign('membership_id')->references('id')->on('memberships')->cascadeOnDelete();
            $table->unique(['membership_id', 'scope_type', 'scope_id'], 'membership_scopes_unique');
            $table->index(['scope_type', 'scope_id']);
        });

        // Carry the existing single-client memberships across before the column goes.
        $existing = DB::table('memberships')->whereNotNull('client_workspace_id')
            ->select('id', 'client_workspace_id')->get();

        if ($existing->isNotEmpty()) {
            $now = now();
            DB::table('membership_scopes')->insert($existing->map(fn ($m) => [
                'id' => (string) Str::uuid(),
                'membership_id' => $m->id,
                'scope_type' => 'client_workspace',
                'scope_id' => $m->client_workspace_id,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
        }

        // The old keys assumed one workspace and one client per membership. Both assumptions are gone.
        DB::statement('DROP INDEX IF EXISTS memberships_user_tenant_portal_unique');
        DB::statement('DROP INDEX IF EXISTS memberships_user_client_portal_unique');

        Schema::table('memberships', function (Blueprint $table) {
            $table->dropColumn('client_workspace_id');
        });

        /*
         * One membership per (user, tenant, workspace, portal). `workspace_id` is nullable — a
         * tenant-wide membership belongs to no single workspace — and Postgres treats NULLs as
         * distinct in a unique index, so this is two partial indexes rather than one that would
         * silently allow duplicate tenant-wide rows.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX memberships_user_tenant_portal_unique
            ON memberships (user_id, tenant_id, portal)
            WHERE workspace_id IS NULL
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX memberships_user_workspace_portal_unique
            ON memberships (user_id, workspace_id, portal)
            WHERE workspace_id IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS memberships_user_tenant_portal_unique');
        DB::statement('DROP INDEX IF EXISTS memberships_user_workspace_portal_unique');

        Schema::table('memberships', function (Blueprint $table) {
            $table->uuid('client_workspace_id')->nullable()->index();
        });

        // Restore the single-client value from the first scope row, best effort.
        foreach (DB::table('membership_scopes')->where('scope_type', 'client_workspace')->get() as $scope) {
            DB::table('memberships')->where('id', $scope->membership_id)
                ->whereNull('client_workspace_id')
                ->update(['client_workspace_id' => $scope->scope_id]);
        }

        Schema::dropIfExists('membership_scopes');

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX memberships_user_tenant_portal_unique
            ON memberships (user_id, tenant_id, portal)
            WHERE client_workspace_id IS NULL
        SQL);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX memberships_user_client_portal_unique
            ON memberships (user_id, client_workspace_id, portal)
            WHERE client_workspace_id IS NOT NULL
        SQL);
    }
};
