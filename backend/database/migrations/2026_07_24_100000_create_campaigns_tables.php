<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campaigns core (spec §9): a Unified Campaign is the business campaign inside the system; it groups
 * many External Campaigns (real objects pulled from ad platforms via connectors).
 *
 * - unified_campaigns: business-owned, project-scoped, soft-deletable.
 * - external_campaigns: imported per ad account (idempotent upsert), optionally linked to one unified
 *   campaign within the project; raw platform payload retained in `raw` (never trusted for reporting).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unified_campaigns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('project_id')->index();
            $table->uuid('client_workspace_id')->nullable()->index();
            $table->string('name');
            $table->string('objective')->default('other');   // CampaignObjective
            $table->string('status')->default('draft');       // CampaignStatus
            $table->decimal('total_budget', 18, 4)->nullable();
            $table->string('budget_currency', 3)->default('SAR');
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->string('primary_conversion_purpose')->nullable(); // advertising|conversion_api|ecommerce|analytics
            $table->string('attribution_model')->nullable();          // last_click|7d_click_1d_view|...
            $table->string('attribution_window')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('target_kpi')->nullable();   // {cpa, roas, cpl, ...} targets
            $table->text('audience')->nullable();
            $table->jsonb('regions')->nullable();
            $table->jsonb('meta')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            // No two unified campaigns share a name within a project (soft-deleted rows excluded by app).
            $table->unique(['project_id', 'name']);
        });

        Schema::create('external_campaigns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('project_id')->index();
            $table->uuid('client_workspace_id')->nullable()->index();
            $table->uuid('unified_campaign_id')->nullable()->index(); // link (at most one within project)
            $table->uuid('external_account_id');
            $table->string('provider');            // meta|google|tiktok|snapchat|... (|sandbox)
            $table->string('external_id');         // provider's campaign id
            $table->string('name');
            $table->string('status')->default('unknown');   // normalized CampaignStatus
            $table->string('objective')->nullable();
            $table->decimal('daily_budget', 18, 4)->nullable();
            $table->decimal('lifetime_budget', 18, 4)->nullable();
            $table->string('currency', 3)->nullable();
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->jsonb('raw')->nullable();       // raw platform payload (audit/debug only)
            $table->timestampTz('linked_at')->nullable();
            $table->foreignId('linked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('last_synced_at')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('external_account_id')->references('id')->on('external_accounts')->cascadeOnDelete();
            $table->foreign('unified_campaign_id')->references('id')->on('unified_campaigns')->nullOnDelete();
            // Idempotent import: a platform campaign is stored once per ad account.
            $table->unique(['external_account_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_campaigns');
        Schema::dropIfExists('unified_campaigns');
    }
};
