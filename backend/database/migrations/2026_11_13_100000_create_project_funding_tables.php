<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FIN-LOOP-001 — one approved budget per project, as a running record (Owner 2026-11-13).
 *
 * Three tables, three different kinds of fact:
 *  - `project_funding`   — the one budget a project has: how it is paid and from which day spend counts.
 *  - `funding_payments`  — every amount finance approved. APPEND-ONLY: a renewal is a new row, never an
 *                          edit, so the spend already counted stays where it is.
 *  - `funding_spend_days`— real spend per account per day, keyed (project, account, day). A resync
 *                          REPLACES the row for that key; it can never add it twice (F07).
 *
 * Deliberately not `spend_limits` (an internal monitoring line) and not `invoices`/`payments` (SaaS and
 * client billing): an approved budget is money the client has put in, and the three must not be summed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_funding', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('client_workspace_id')->index();
            $table->uuid('project_id')->unique();
            $table->string('payment_method', 16)->default('prepaid'); // prepaid | direct | unset
            $table->date('starts_on')->nullable();                    // null = the first payment's date
            $table->decimal('alert_floor', 18, 2)->default(2000);     // SAR
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('client_workspace_id')->references('id')->on('client_workspaces')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
        });

        Schema::create('funding_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('client_workspace_id')->index();
            $table->uuid('project_id');
            $table->string('kind', 16);                       // initial | top_up
            $table->decimal('amount', 18, 2);                 // as entered
            $table->char('currency', 3);
            $table->decimal('amount_sar', 18, 2);
            $table->decimal('rate', 24, 12);
            $table->date('rate_date');
            $table->string('rate_source', 64);
            $table->date('paid_on');
            $table->string('reference', 120)->nullable();
            $table->string('note', 500)->nullable();
            $table->decimal('remaining_before', 18, 2)->nullable(); // SAR, at the moment it was added
            $table->decimal('remaining_after', 18, 2)->nullable();
            $table->string('idempotency_key', 80);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->unique(['project_id', 'idempotency_key']);
            $table->index(['project_id', 'paid_on']);
        });

        Schema::create('funding_spend_days', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('project_id');
            $table->uuid('external_account_id');
            $table->string('provider', 32);
            $table->date('spend_date');
            $table->decimal('original_amount', 24, 6);
            $table->char('original_currency', 3)->nullable();
            $table->decimal('amount_sar', 18, 2)->nullable();   // null = no dated rate; never a silent zero
            $table->decimal('rate', 24, 12)->nullable();
            $table->date('rate_date')->nullable();
            $table->string('rate_source', 64)->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->unique(['project_id', 'external_account_id', 'spend_date'], 'funding_spend_days_key');
            $table->index(['project_id', 'spend_date']);
        });

        /*
         * The two permissions, created here rather than only in the seeder: existing agency owners hold
         * the permissions their role was given when it was made, and a new key nobody holds is a screen
         * nobody can open. The seeder also lists them, so new workspaces get them the usual way.
         */
        $now = now();
        foreach (['funding.view', 'funding.manage'] as $key) {
            DB::table('permissions')->insertOrIgnore(['key' => $key, 'group' => 'funding', 'created_at' => $now, 'updated_at' => $now]);
        }
        $ids = DB::table('permissions')->whereIn('key', ['funding.view', 'funding.manage'])->pluck('id');
        $owners = DB::table('roles')->where('slug', 'tenant-owner')->pluck('id');
        foreach ($owners as $role) {
            foreach ($ids as $perm) {
                DB::table('permission_role')->insertOrIgnore(['role_id' => $role, 'permission_id' => $perm]);
            }
        }
    }

    public function down(): void
    {
        DB::table('permissions')->whereIn('key', ['funding.view', 'funding.manage'])->delete();
        Schema::dropIfExists('funding_spend_days');
        Schema::dropIfExists('funding_payments');
        Schema::dropIfExists('project_funding');
    }
};
