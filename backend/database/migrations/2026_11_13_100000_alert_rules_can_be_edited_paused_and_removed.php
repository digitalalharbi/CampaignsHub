<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ALERT-RULES-MANAGE-001 — an alert rule can be edited, paused and removed, and says who hears it.
 *
 * `recipients` — null keeps the behaviour every existing rule has today (everybody in the rule's scope who chose
 * alert email). Set, it narrows the email to the project's manager and/or named members of this workspace.
 *
 * `deleted_at` — removing a rule keeps its history. `alert_events.rule_id` cascades on delete, so a hard delete
 * would erase every alert the rule ever raised, decisions and notes included. A removed rule is soft-deleted and
 * switched off, so the evaluator (which reads `active = true`) never sweeps it again.
 *
 * Additive and reversible: two nullable columns, nothing rewritten, `down()` drops only what `up()` added.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alert_rules', function (Blueprint $table): void {
            $table->jsonb('recipients')->nullable();
            $table->softDeletesTz();
        });
    }

    public function down(): void
    {
        Schema::table('alert_rules', function (Blueprint $table): void {
            $table->dropSoftDeletesTz();
            $table->dropColumn('recipients');
        });
    }
};
