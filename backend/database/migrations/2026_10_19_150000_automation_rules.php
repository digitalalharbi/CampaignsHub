<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AUTO-RULES-001 — Owner-defined automated rules, their runs and every action they took or declined.
 *
 * A rule belongs to ONE project (so to one client and one tenant) and never reaches outside it. Its
 * scope narrows inside the project: platforms, ad accounts, campaigns, ad groups. Every change a rule
 * wants is a proposal through the existing approval gateway; the action rows record before and after
 * values, the proposal it became and why anything was skipped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('project_id')->index();
            $table->string('name', 160);
            $table->boolean('enabled')->default(false);
            $table->string('mode', 20)->default('monitor');          // monitor | recommend | auto
            $table->string('level', 20)->default('campaign');        // campaign | ad_set | ad
            $table->jsonb('scope')->nullable();                      // {platforms[], account_ids[], campaign_ids[], ad_set_ids[], ad_ids[]}
            $table->string('logic', 3)->default('all');              // all (AND) | any (OR)
            $table->jsonb('conditions');                             // [{metric, operator, value, window_days}]
            $table->jsonb('action');                                 // {type, percent?}
            $table->jsonb('schedule');                               // {frequency, at?, timezone}
            $table->unsignedInteger('cooldown_hours')->default(24);
            $table->decimal('min_spend', 14, 2)->nullable();         // data sufficiency: judge only past this spend
            $table->timestampTz('last_run_at')->nullable();
            $table->timestampTz('next_run_at')->nullable()->index();
            $table->string('last_result', 40)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        Schema::create('automation_rule_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('rule_id')->index();
            $table->boolean('dry_run')->default(false);
            $table->string('trigger', 20);                           // schedule | manual | dry_run
            $table->unsignedInteger('evaluated')->default(0);
            $table->unsignedInteger('matched')->default(0);
            $table->unsignedInteger('acted')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->string('status', 20)->default('running');        // running | done | failed
            $table->text('error')->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
        });

        Schema::create('automation_rule_actions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('rule_id')->index();
            $table->uuid('run_id')->index();
            $table->uuid('project_id');
            $table->string('level', 20);
            $table->string('provider', 30);
            $table->uuid('external_account_id')->nullable();
            $table->string('target_external_id', 120);
            $table->string('target_name', 255)->nullable();
            $table->string('action', 30);
            $table->string('status', 40);                            // matched | notified | proposed | executed | blocked | failed | skipped_* | unsupported_* | awaiting_credentials
            $table->jsonb('metrics')->nullable();                    // the figures the conditions read
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->uuid('proposal_id')->nullable();
            $table->text('note')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['rule_id', 'target_external_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_rule_actions');
        Schema::dropIfExists('automation_rule_runs');
        Schema::dropIfExists('automation_rules');
    }
};
