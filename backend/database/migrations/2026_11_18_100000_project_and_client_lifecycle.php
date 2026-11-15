<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * PROJECT-LIFECYCLE-001 §1 — the lifecycle data model (docs/rules/project-lifecycle.md). Additive and reversible.
 *
 * Projects already had `status` (written `draft`/`setup`/… at creation and moved only by archive/pause) and
 * `deleted_at`; clients already had `client_status` (the full client set but `deleted`) and `archived_at/by`. This
 * EXTENDS them — nothing is duplicated:
 *
 * - projects: archived_at/by, deleted_by, purge_after, previous_status, and `status_before_lifecycle` (the exact value
 *   each row held before this migration — what down() puts back).
 * - client_workspaces: deleted_by, purge_after, previous_status.
 * - project_status_transitions / client_status_transitions: actor + time + name snapshot, never deleted (no FK, so a
 *   purge keeps them).
 * - project_archive_effects: what an archive stopped, so un-archive restores it exactly.
 * - the `live_projects` view (the SQL face of LiveProjects).
 * - permissions projects.archive/restore/purge (clients.archive / clients.delete / projects.delete already exist),
 *   granted to the workspace owner role only.
 *
 * ## Backfill — no project's effective behaviour changes
 *
 * Status is normalised to the canonical set by explicit conditions, and each changed row gets a transition row
 * (reason `lifecycle_backfill`):
 * - soft-deleted → `deleted` (purge_after left NULL: nothing that existed before is ever auto-purged);
 * - archived stays archived (archived_at := updated_at when unknown); paused stays paused;
 * - active/completed/closed → active;
 * - draft/setup/onboarding WITH any account binding → active (they were syncing and aggregated, and the screen already
 *   derived «نشط»/«قيد الإعداد» for them); without any binding → draft (no data, so leaving aggregation changes no
 *   figure);
 * - any other value → active.
 * No project row is deleted or moved; no archive effects are applied here (the lead runs
 * `lifecycle:apply-archive-effects` explicitly).
 */
return new class extends Migration
{
    private const NEW_PERMISSIONS = ['projects.archive', 'projects.restore', 'projects.purge'];

    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->timestampTz('archived_at')->nullable();
            $table->foreignId('archived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('purge_after')->nullable();
            $table->string('previous_status', 32)->nullable();
            $table->string('status_before_lifecycle', 32)->nullable();
            $table->index(['tenant_id', 'status']);
        });

        Schema::table('client_workspaces', function (Blueprint $table) {
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('purge_after')->nullable();
            $table->string('previous_status', 32)->nullable();
        });

        Schema::create('project_status_transitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('project_id');            // no FK: the log outlives a purge
            $table->uuid('client_workspace_id')->nullable();
            $table->string('name_snapshot');
            $table->string('client_name_snapshot')->nullable();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name_snapshot')->nullable();
            $table->string('reason')->nullable();
            $table->jsonb('context')->nullable();
            $table->timestampTz('created_at', 6);
            $table->index(['project_id', 'created_at']);
        });

        Schema::create('client_status_transitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('client_workspace_id');   // no FK: the log outlives a purge
            $table->string('name_snapshot');
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name_snapshot')->nullable();
            $table->string('reason')->nullable();
            $table->jsonb('context')->nullable();
            $table->timestampTz('created_at', 6);
            $table->index(['client_workspace_id', 'created_at']);
        });

        Schema::create('project_archive_effects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('project_id');
            $table->uuid('transition_id')->nullable();
            $table->string('kind', 48);
            $table->string('target_table', 64);
            $table->string('target_id', 64);
            $table->jsonb('before');
            $table->jsonb('after');
            $table->unsignedBigInteger('applied_by')->nullable();
            $table->timestampTz('applied_at', 6);
            $table->timestampTz('restored_at')->nullable();
            $table->unsignedBigInteger('restored_by')->nullable();
            $table->string('restore_outcome', 32)->nullable();
            $table->index(['project_id', 'restored_at']);
        });

        $this->backfill();

        DB::statement("ALTER TABLE projects ALTER COLUMN status SET DEFAULT 'active'");
        DB::statement("CREATE OR REPLACE VIEW live_projects AS SELECT id, tenant_id, client_workspace_id, status FROM projects WHERE deleted_at IS NULL AND status IN ('active', 'paused')");

        $now = now();
        foreach (self::NEW_PERMISSIONS as $key) {
            DB::table('permissions')->insertOrIgnore(['key' => $key, 'group' => 'projects', 'description' => 'Projects — '.substr($key, 9), 'created_at' => $now, 'updated_at' => $now]);
        }
        $ids = DB::table('permissions')->whereIn('key', self::NEW_PERMISSIONS)->pluck('id');
        foreach (DB::table('roles')->where('slug', 'tenant-owner')->pluck('id') as $role) {
            foreach ($ids as $perm) {
                DB::table('permission_role')->insertOrIgnore(['role_id' => $role, 'permission_id' => $perm]);
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('key', self::NEW_PERMISSIONS)->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('key', self::NEW_PERMISSIONS)->delete();

        DB::statement('DROP VIEW IF EXISTS live_projects');
        DB::statement("ALTER TABLE projects ALTER COLUMN status SET DEFAULT 'setup'");
        // Every row back to the exact value it held before (rows created since keep theirs).
        DB::statement('UPDATE projects SET status = status_before_lifecycle WHERE status_before_lifecycle IS NOT NULL');
        DB::table('client_workspaces')->where('client_status', 'deleted')->update(['client_status' => DB::raw("COALESCE(previous_status, 'archived')")]);

        Schema::dropIfExists('project_archive_effects');
        Schema::dropIfExists('client_status_transitions');
        Schema::dropIfExists('project_status_transitions');

        Schema::table('client_workspaces', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deleted_by');
            $table->dropColumn(['purge_after', 'previous_status']);
        });
        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'status']);
            $table->dropConstrainedForeignId('archived_by');
            $table->dropConstrainedForeignId('deleted_by');
            $table->dropColumn(['archived_at', 'purge_after', 'previous_status', 'status_before_lifecycle']);
        });
    }

    private function backfill(): void
    {
        $before = DB::table('projects')->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->all();

        DB::statement('UPDATE projects SET status_before_lifecycle = status');
        DB::statement("UPDATE projects SET status = 'deleted' WHERE deleted_at IS NOT NULL AND status <> 'deleted'");
        DB::statement("UPDATE projects SET status = 'active' WHERE deleted_at IS NULL AND status IN ('completed', 'closed')");
        DB::statement("UPDATE projects SET status = 'active' WHERE deleted_at IS NULL AND status IN ('draft', 'setup', 'onboarding')
            AND EXISTS (SELECT 1 FROM project_integration_bindings b WHERE b.project_id = projects.id)");
        DB::statement("UPDATE projects SET status = 'draft' WHERE deleted_at IS NULL AND status IN ('setup', 'onboarding')");
        DB::statement("UPDATE projects SET status = 'active' WHERE deleted_at IS NULL AND status NOT IN ('draft', 'active', 'paused', 'archived')");
        DB::statement("UPDATE projects SET archived_at = updated_at WHERE status = 'archived' AND archived_at IS NULL");
        DB::statement("UPDATE projects SET previous_status = 'active' WHERE status IN ('archived', 'deleted') AND previous_status IS NULL");

        DB::statement("INSERT INTO project_status_transitions (id, tenant_id, project_id, client_workspace_id, name_snapshot, client_name_snapshot, from_status, to_status, reason, created_at)
            SELECT gen_random_uuid(), p.tenant_id, p.id, p.client_workspace_id, p.name, c.name, p.status_before_lifecycle, p.status, 'lifecycle_backfill', now()
            FROM projects p LEFT JOIN client_workspaces c ON c.id = p.client_workspace_id
            WHERE p.status IS DISTINCT FROM p.status_before_lifecycle");

        $after = DB::table('projects')->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->all();
        Log::info('lifecycle.backfill', ['before' => $before, 'after' => $after]);
    }
};
