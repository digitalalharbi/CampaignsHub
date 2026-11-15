<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PROJECT-LIFECYCLE-001 §4–§5 (builder N2) — trash, permanent delete, move. Additive and reversible; touches NO
 * existing row's data (no backfill, no status change, nothing deleted or moved).
 *
 * - projects.purged_at / purged_by: the permanent delete keeps the `projects` row as a tombstone (status `deleted`,
 *   soft-deleted, purged_at set). Its data rows are deleted by `ProjectPurger`; the row itself stays because the
 *   notice logs (`funding_notices`, `app_notifications` → `notification_deliveries`) reference it, and deleting it
 *   would cascade into them. Audit + notice logs are never deleted (rule 7).
 * - projects.purge_warned_at: the «3 days before automatic permanent deletion» notice is sent once.
 * - report_share_access_logs.share_id: CASCADE → SET NULL (nullable), so purging a project's reports never deletes the
 *   access log of a client link (a log, kept with its detail).
 * - permission `projects.move` («نقل المشروع لعميل آخر»), granted to the workspace owner role only.
 */
return new class extends Migration
{
    private const NEW_PERMISSIONS = ['projects.move'];

    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->timestampTz('purged_at')->nullable();
            $table->foreignId('purged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('purge_warned_at')->nullable();
        });

        Schema::table('report_share_access_logs', function (Blueprint $table) {
            $table->dropForeign(['share_id']);
        });
        DB::statement('ALTER TABLE report_share_access_logs ALTER COLUMN share_id DROP NOT NULL');
        Schema::table('report_share_access_logs', function (Blueprint $table) {
            $table->foreign('share_id')->references('id')->on('report_shares')->nullOnDelete();
        });

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

        Schema::table('report_share_access_logs', function (Blueprint $table) {
            $table->dropForeign(['share_id']);
        });
        // NOT NULL comes back only when no log row lost its share to a purge (those rows are logs and are kept).
        if (! DB::table('report_share_access_logs')->whereNull('share_id')->exists()) {
            DB::statement('ALTER TABLE report_share_access_logs ALTER COLUMN share_id SET NOT NULL');
        }
        Schema::table('report_share_access_logs', function (Blueprint $table) {
            $table->foreign('share_id')->references('id')->on('report_shares')->cascadeOnDelete();
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purged_by');
            $table->dropColumn(['purged_at', 'purge_warned_at']);
        });
    }
};
