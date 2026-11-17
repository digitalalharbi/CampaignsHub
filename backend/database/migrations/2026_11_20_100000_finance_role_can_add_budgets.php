<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * FINANCE-ADDS-BUDGET-001 — the «المالية» role adds and renews budgets.
 *
 * The finance preset carried `budget.view` / `budget.manage`, but the budgets API (FundingController, FundingRateController)
 * asks for `funding.view` / `funding.manage`, which only the workspace owner received (2026_11_13_100000). A finance member
 * therefore saw the budgets and could not add one. Every existing role of the `finance` slug gets the two keys; nothing else
 * changes — a project manager keeps `budget.view` only (read, never change). Additive and reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        $perms = DB::table('permissions')->whereIn('key', ['funding.view', 'funding.manage'])->pluck('id');
        $roles = DB::table('roles')->where('slug', 'finance')->pluck('id');

        foreach ($roles as $role) {
            foreach ($perms as $perm) {
                DB::table('permission_role')->insertOrIgnore(['role_id' => $role, 'permission_id' => $perm]);
            }
        }
    }

    public function down(): void
    {
        $perms = DB::table('permissions')->whereIn('key', ['funding.view', 'funding.manage'])->pluck('id');
        $roles = DB::table('roles')->where('slug', 'finance')->pluck('id');

        DB::table('permission_role')->whereIn('role_id', $roles)->whereIn('permission_id', $perms)->delete();
    }
};
