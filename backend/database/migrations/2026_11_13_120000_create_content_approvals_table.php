<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CLIENT-CONTENT-APPROVAL-001 — «بانتظار اعتمادك»: a piece of content the agency puts before its client.
 *
 * One row per ask. It names the client and the project it belongs to (both in the same tenant, checked by the
 * service before the row is written), and optionally the synced creative it is about — the content model that
 * already exists, never a copy of it. The client's answer (approved / changes requested) is written once, with
 * who answered and when; the audit log carries the trail.
 *
 * Additive: a new table, nothing existing is touched, `down()` drops only it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_approvals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('client_workspace_id');
            $table->uuid('project_id');
            $table->uuid('creative_id')->nullable();
            $table->string('title', 160);
            $table->text('caption')->nullable();
            $table->string('status', 24)->default('pending'); // pending | approved | changes_requested | withdrawn
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decided_by_contact', 190)->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('client_workspace_id')->references('id')->on('client_workspaces')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('creative_id')->references('id')->on('external_creatives')->nullOnDelete();
            $table->index(['tenant_id', 'client_workspace_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_approvals');
    }
};
