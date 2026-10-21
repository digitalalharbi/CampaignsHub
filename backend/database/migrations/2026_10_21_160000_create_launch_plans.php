<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LAUNCH-001 — a launch is a PLAN with per-account steps, not one request that either worked or did not.
 *
 * Publishing a campaign to three ad accounts is up to twelve provider calls (campaign, ad set, ad, run — per
 * account), any of which can be refused half-way. The plan keeps what each step produced — the provider's own
 * id, status and error — so a partial launch is visible as exactly that, and a retry resumes from the first
 * step that has no provider id instead of creating a second campaign on a platform that already holds one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('launch_plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('project_id')->index();
            $table->uuid('client_workspace_id')->nullable();
            $table->uuid('unified_campaign_id')->nullable()->index();
            $table->string('name', 250);
            $table->string('objective', 64);
            $table->string('status', 24)->default('draft');
            $table->string('idempotency_key', 64);
            $table->jsonb('spec');
            $table->jsonb('steps')->default('[]');
            $table->boolean('launch')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('published_by')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('launch_plans');
    }
};
