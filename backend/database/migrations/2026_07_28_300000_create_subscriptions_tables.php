<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subscriptions / plans / usage limits. This layer is additive and honest by construction: a tenant WITHOUT a
 * subscription is never blocked (it defaults to the most permissive plan at the service layer), and a plan
 * limit is enforced against real usage counters — never a hard-coded assumption.
 *
 *   - subscription_plans : the global plan catalogue (starter|growth|scale). NOT tenant-scoped — every tenant
 *                          picks from the same catalogue. `limits` holds {projects, team_members, connections,
 *                          reports_per_month, ...}; a null/absent limit means unlimited.
 *   - subscriptions      : exactly one row per tenant (tenant_id unique). Points at a plan and carries the
 *                          billing status + current period end + purchased seats. Tenant-scoped.
 *   - usage_counters     : a metered counter per (tenant, metric, period). `period` is 'total' for cumulative
 *                          metrics (e.g. projects) or 'YYYY-MM' for monthly metrics (e.g. reports_per_month).
 *                          Unique per (tenant, metric, period) so an increment is a safe upsert. Tenant-scoped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code')->unique(); // starter|growth|scale
            $table->string('name');
            $table->decimal('price_monthly', 15, 2)->default(0);
            $table->string('currency', 3)->default('SAR');
            $table->jsonb('features')->nullable();  // marketing/feature flags surfaced to the UI
            $table->jsonb('limits')->nullable();    // {projects, team_members, connections, reports_per_month, ...}
            $table->boolean('is_active')->default(true)->index();
            $table->timestampsTz();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->unique(); // one subscription per tenant
            $table->uuid('plan_id');
            $table->string('status')->default('trialing'); // trialing|active|past_due|canceled
            $table->timestampTz('current_period_end')->nullable();
            $table->unsignedInteger('seats')->default(1);
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('plan_id')->references('id')->on('subscription_plans')->cascadeOnDelete();
            $table->index('status');
        });

        Schema::create('usage_counters', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('tenant_id')->index();
            $table->string('metric');            // projects|team_members|connections|reports_per_month|...
            $table->string('period')->default('total'); // 'total' or 'YYYY-MM'
            $table->unsignedBigInteger('count')->default(0);
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'metric', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_counters');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('subscription_plans');
    }
};
