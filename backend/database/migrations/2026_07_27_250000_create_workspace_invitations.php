<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invitations to join an EXISTING workspace (tenant). Only the token hash is stored; the invite carries a
 * role and optional project allowlist. Delivery is honest (awaiting_provider_credentials); the accept link
 * works for testing but the token is never exposed in production.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_invitations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('email');
            $table->string('role_slug');
            $table->jsonb('project_ids')->nullable(); // null = all projects; otherwise a restricted allowlist
            $table->char('token_hash', 64)->index();
            $table->string('delivery_status')->default('awaiting_provider_credentials');
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->foreignId('accepted_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            // One PENDING invite per email per tenant (a partial unique index on not-yet-accepted rows).
            $table->unique(['tenant_id', 'email', 'accepted_at'], 'workspace_invitations_pending');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_invitations');
    }
};
