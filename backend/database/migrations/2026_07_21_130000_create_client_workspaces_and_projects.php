<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rentable client workspaces + their projects. A client workspace is an isolated space an agency
 * (tenant) provisions per client, with its own branding, mode, users, projects, and limits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_workspaces', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('name');
            $table->string('slug');
            $table->string('mode')->default('managed'); // managed|collaborative|self_service
            $table->string('status')->default('active');
            $table->jsonb('branding')->nullable();   // logo_url, brand_name, colors
            $table->jsonb('limits')->nullable();      // per-workspace usage limits
            $table->string('custom_domain')->nullable()->unique();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'slug']);
        });

        // Membership: which users belong to a client workspace (and in what client role).
        Schema::create('client_workspace_user', function (Blueprint $table) {
            $table->uuid('client_workspace_id');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('client_role')->default('client_viewer'); // client_admin|client_approver|client_viewer
            $table->timestampsTz();

            $table->foreign('client_workspace_id')->references('id')->on('client_workspaces')->cascadeOnDelete();
            $table->primary(['client_workspace_id', 'user_id']);
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('client_workspace_id');
            $table->foreignId('account_manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('status')->default('setup'); // setup|active|paused|closed
            $table->unsignedTinyInteger('setup_completion')->default(0); // 0..100
            $table->jsonb('meta')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('client_workspace_id')->references('id')->on('client_workspaces')->cascadeOnDelete();
            $table->index(['tenant_id', 'client_workspace_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
        Schema::dropIfExists('client_workspace_user');
        Schema::dropIfExists('client_workspaces');
    }
};
