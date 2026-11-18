<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ACCESS-CENTER-001 — per-member access overrides, and the invitation fields that carry them.
 *
 * Additive only. No existing row is read or rewritten: a member with no override row resolves exactly the
 * keys their roles hold today, and an invitation with null columns is accepted exactly as before.
 *
 * - `member_access_overrides`: one row per (tenant, member, permission key), effect `allow` or `deny`. Applied in
 *   `HasRoles::permissionKeys()` — the one place a request's permissions are resolved.
 * - `workspace_invitations.access_overrides`: `{allow: [...], deny: [...]}` decided when the invitation is sent,
 *   copied onto the member when it is accepted.
 * - `workspace_invitations.client_ids`: the clients the invitee will be confined to, granted as membership
 *   scopes at acceptance (the same ceiling the team page sets afterwards).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_access_overrides', function (Blueprint $table): void {
            $table->id();
            $table->uuid('tenant_id');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('permission_key', 80);
            $table->string('effect', 5); // allow | deny
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'user_id', 'permission_key'], 'member_access_overrides_unique');
            $table->index(['user_id', 'tenant_id']);
        });

        Schema::table('workspace_invitations', function (Blueprint $table): void {
            $table->jsonb('access_overrides')->nullable();
            $table->jsonb('client_ids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('workspace_invitations', function (Blueprint $table): void {
            $table->dropColumn(['access_overrides', 'client_ids']);
        });
        Schema::dropIfExists('member_access_overrides');
    }
};
