<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The digest schedule, and the ledger that makes it idempotent — MAIL-003.
 *
 * ## The unique index IS the deduplication
 *
 * `(user_id, kind, period_key)` is unique, so a second attempt to send Tuesday's digest to the same
 * person cannot be inserted — not «is unlikely to be», cannot. That matters because the alternative
 * is a check-then-send, and the window between the two is exactly where a retried queue job, an
 * overlapping scheduler run or a second worker sends the same email twice. A person who receives
 * yesterday's numbers twice stops trusting the ones they receive once.
 *
 * The row is written BEFORE the send is attempted, for the same reason: a crash between sending and
 * recording must leave evidence that the send happened, not a clean slate that invites a repeat.
 *
 * ## Why a row exists even when nothing is sent
 *
 * `skipped` and `awaiting_credentials` are recorded states. «Why did I not get my digest?» has to
 * have an answer, and the honest answers — no activity, nothing in scope, no mail provider wired —
 * are different from each other and from a failure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_preferences', function (Blueprint $table): void {
            /*
             * The recipient's own clock and language.
             *
             * A «daily» email that arrives at 03:00 local time is not daily, it is nocturnal. The
             * hour is stored as the LOCAL hour the person chose; the scheduler converts, so moving
             * timezone changes nothing about their preference.
             */
            $table->string('timezone')->default('Asia/Riyadh');
            $table->string('locale', 5)->default('ar');
            $table->unsignedTinyInteger('digest_hour')->default(8);
            /*
             * Which digests this person wants at all, independent of the per-category channel map.
             *
             * Kept separate because «send me the daily summary» and «tell me when a sync fails» are
             * different questions: one is a rhythm, the other is an event.
             */
            $table->jsonb('digests')->nullable();
        });

        Schema::create('digest_sends', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->unsignedBigInteger('user_id');
            // `daily` | `weekly` — the rhythm, not the content.
            $table->string('kind', 16);
            /*
             * The period this send COVERS, not the moment it was made: `2026-08-07` for a daily,
             * `2026-W32` for a weekly. Two attempts for the same period collide on the index below,
             * which is what makes a retry safe.
             */
            $table->string('period_key', 24);
            // sent | awaiting_credentials | skipped | failed
            $table->string('status', 32);
            $table->string('reason', 64)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampsTz();

            $table->unique(['user_id', 'kind', 'period_key']);
            $table->index(['tenant_id', 'kind', 'period_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('digest_sends');

        Schema::table('notification_preferences', function (Blueprint $table): void {
            $table->dropColumn(['timezone', 'locale', 'digest_hour', 'digests']);
        });
    }
};
