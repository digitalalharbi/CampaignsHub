<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alerting engine storage:
 *   - alert_rules   : per-tenant (optionally per-project) rule config — type, threshold, cooldown, channels,
 *                     whether to open a task, severity. Fail-closed: a rule only fires while active.
 *   - alert_events  : the firing ledger — one lifecycle row per (rule, entity). Carries the measured context,
 *                     the linked notification/task, and the resolve/snooze lifecycle. last_triggered_at drives
 *                     the cooldown window; dedup_key collapses repeats for the same rule+entity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('project_id')->nullable()->index(); // null = tenant-wide
            $table->string('type'); // budget_risk|cpa_increase|cpl_increase|roas_drop|no_results|sync_failure|token_expiry|report_failed|sla_warning
            $table->string('name');
            $table->jsonb('threshold')->nullable();          // e.g. {"pct":20} | {"days":3} | {"ratio":0.9}
            $table->unsignedInteger('cooldown_minutes')->default(720); // default 12h between repeat alerts
            $table->jsonb('channels')->nullable();            // ["in_app","email","whatsapp"]
            $table->boolean('create_task')->default(false);
            $table->string('severity')->default('warning');   // info|warning|critical
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'type', 'active']);
        });

        Schema::create('alert_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('project_id')->nullable()->index();
            $table->uuid('rule_id');
            $table->string('type');
            $table->string('entity_type')->nullable();
            $table->string('entity_id')->nullable();
            $table->string('dedup_key', 64)->index();
            $table->string('status')->default('open');        // open|snoozed|resolved
            $table->string('severity')->default('warning');
            $table->jsonb('context')->nullable();             // measured values at trigger time
            $table->uuid('notification_id')->nullable();
            $table->uuid('task_id')->nullable();
            $table->timestampTz('last_triggered_at')->nullable();
            $table->timestampTz('snoozed_until')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('rule_id')->references('id')->on('alert_rules')->cascadeOnDelete();
            $table->index(['tenant_id', 'rule_id', 'status']);
            $table->index(['tenant_id', 'dedup_key', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_events');
        Schema::dropIfExists('alert_rules');
    }
};
