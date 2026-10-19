<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AGENTS-APPROVAL-GATEWAY-001 — the two tables the safety model rests on.
 *
 * `action_proposals`: what an agent (or a person) wants done to an advertising object. Nothing here is
 * executed by being written; the status moves only through the gateway.
 * `action_log`: every phase of every proposal, with the request and response of any provider call, so a
 * change to a client's spend can always be traced to who asked, who approved and what the platform said.
 *
 * No `external_*` foreign key: a proposal must outlive the sync that may later drop the object it named.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('action_proposals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('project_id')->index();
            $table->uuid('client_workspace_id')->nullable();
            // Which agent (orchestrator, campaign, analyst, creative, leads, reports) or `person`.
            $table->string('agent_key', 40);
            $table->string('provider', 40);
            $table->uuid('external_account_id');
            $table->string('target_type', 20);          // campaign | ad_set | ad
            $table->string('target_external_id', 120);  // the provider's own id
            $table->string('action', 40);               // the closed vocabulary — see ProposalAction
            $table->jsonb('payload')->nullable();
            $table->string('currency', 3)->nullable();
            $table->text('reason');
            $table->jsonb('evidence')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->jsonb('violations')->nullable();    // why it is blocked, in codes
            $table->string('idempotency_key', 64);
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('executed_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'project_id', 'status']);
            $table->index(['tenant_id', 'idempotency_key']);
        });

        Schema::create('action_log', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('proposal_id')->index();
            $table->string('phase', 20);                // proposed | approved | rejected | blocked | executing | executed | failed | expired
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->jsonb('api_request')->nullable();
            $table->jsonb('api_response')->nullable();
            $table->text('note')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_log');
        Schema::dropIfExists('action_proposals');
    }
};
