<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AUTOMATION-FIRST-OPERATIONS-001 — the scheduler records that it ran.
 *
 * ## What this is not
 *
 * It is not another ledger of WORK. `metric_sync_runs`, `integration_sync_runs`, `digest_sends`,
 * `report_deliveries` and `mail_deliveries` already record what the product produced, per domain, and
 * duplicating any of that here would be a second source for a figure that already has one.
 *
 * This records something none of them can: that the scheduled command **executed at all**.
 *
 * ## The gap it closes
 *
 * Thirteen commands are scheduled and `ScheduledWorkInventoryTest` proves they are still registered.
 * Not one of them writes a record of its own run. So a domain ledger's silence has two readings that
 * are indistinguishable from the outside:
 *
 *   - «`notifications:send-digests` ran, and nobody was subscribed» — a normal Tuesday;
 *   - «`notifications:send-digests` has not run since the deploy» — an outage nobody is watching.
 *
 * `digest_sends` is empty in both. The same holds for every other command: an alert sweep that throws
 * every night leaves no trace whatsoever, because the failure happens before any alert is written.
 *
 * ## Why one table and one listener
 *
 * Written by a single subscriber on Laravel's own scheduled-task events rather than by thirteen
 * commands each remembering to log. A command added next year is observed without anybody editing it,
 * and — the part that matters — a command that CRASHES is still recorded, which it would not be if the
 * recording lived at the end of its own handler.
 *
 * Platform-scoped on purpose: the scheduler is not a tenant's, and there is no tenant_id to put here.
 * That is precisely why the surface reading it sits behind `platform`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // The artisan signature as the scheduler knows it, e.g. «notifications:send-digests».
            $table->string('command', 191)->index();

            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            /*
             * completed · failed · skipped.
             *
             * `skipped` is a real outcome, not a missing one: `withoutOverlapping` refusing a second
             * copy while the first is still working is the guard doing its job, and reading it as a
             * failure would have somebody investigating healthy behaviour.
             */
            $table->string('outcome', 16)->index();

            $table->integer('exit_code')->nullable();

            /*
             * The exception class, when there was one. Kept apart from the message because the class is
             * what groups repeated failures and the message is what explains one.
             */
            $table->string('failure_class', 191)->nullable();
            $table->text('failure_message')->nullable();

            $table->timestamps();

            // "the last run of each command" is the only question this table is asked.
            $table->index(['command', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_runs');
    }
};
