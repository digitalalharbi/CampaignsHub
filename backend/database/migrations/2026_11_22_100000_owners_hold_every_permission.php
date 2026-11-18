<?php

declare(strict_types=1);

use Database\Seeders\PermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * NAV-OWNER-001 — the workspace owner holds every permission in the catalogue, including the ones added after the
 * workspace was made.
 *
 * Production deploys migrate but never seed, so a permission added to PermissionSeeder reached new workspaces only.
 * Since the side menu shows a section only to a role that holds its `*.view` (NAV-PERMS, #1076), owners of older
 * workspaces saw «التحليلات» «الحملات» «الميزانيات» disappear. This puts the full catalogue in place and gives it to every
 * `tenant-owner` role. Idempotent: run it twice and nothing changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new PermissionSeeder)->run();

        $permissions = DB::table('permissions')->pluck('id');
        $owners = DB::table('roles')->where('slug', 'tenant-owner')->pluck('id');

        foreach ($owners as $role) {
            foreach ($permissions->chunk(200) as $chunk) {
                DB::table('permission_role')->insertOrIgnore(
                    $chunk->map(fn ($permission): array => ['role_id' => $role, 'permission_id' => $permission])->values()->all(),
                );
            }
        }
    }

    public function down(): void
    {
        // Granting is not undone: taking permissions away from owners would hide their own workspace from them.
    }
};
