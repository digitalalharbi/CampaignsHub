<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OPERATIONAL-INTELLIGENCE-P1-001 — an alert becomes decision-ready.
 *
 * Additive only: every new column on `alert_events` is nullable, so existing rows are neither rewritten
 * nor invalidated (a NULL `decision_status` reads as «new», a NULL `anatomy` is derived on read). The
 * audit table is append-only by construction (see `AlertEventDecision`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alert_events', function (Blueprint $table) {
            $table->string('priority', 2)->nullable();            // P1|P2|P3 — AlertAnatomy's deterministic rule
            $table->jsonb('evidence')->nullable();                // metric, window, before/now, threshold, completeness
            $table->jsonb('anatomy')->nullable();                 // problem / impact / action, ar+en
            $table->string('decision_status', 20)->nullable();    // new|in_progress|done|dismissed (NULL = new)
            $table->text('decision_reason')->nullable();          // required when dismissed
            $table->string('action_tag', 40)->nullable();         // what was done, when «done»
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->jsonb('impact_baseline')->nullable();         // metric + value frozen at «done»

            $table->foreign('owner_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['tenant_id', 'priority']);
        });

        Schema::create('alert_event_decisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('seq')->generatedAs()->unique();   // the order lines were written in, even within one second
            $table->uuid('tenant_id')->index();
            $table->uuid('alert_event_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('kind', 20);                           // status|assign|note|resolve|snooze
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->unsignedBigInteger('from_owner_id')->nullable();
            $table->unsignedBigInteger('to_owner_id')->nullable();
            $table->string('action_tag', 40)->nullable();
            $table->text('note')->nullable();
            $table->timestampTz('created_at');                    // no updated_at: a row is never edited

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('alert_event_id')->references('id')->on('alert_events')->cascadeOnDelete();
            $table->index(['alert_event_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_event_decisions');
        Schema::table('alert_events', function (Blueprint $table) {
            $table->dropForeign(['owner_id']);
            $table->dropIndex(['tenant_id', 'priority']);
            $table->dropColumn(['priority', 'evidence', 'anatomy', 'decision_status', 'decision_reason', 'action_tag', 'owner_id', 'decided_at', 'impact_baseline']);
        });
    }
};
