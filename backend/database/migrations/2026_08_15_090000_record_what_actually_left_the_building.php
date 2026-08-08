<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ledger for messages that belong to an ACCOUNT rather than to a project — MAIL-009.
 *
 * ## Why not `notification_deliveries`
 *
 * That table requires a `notification_id`: every row is a channel attempt for an in-app notification
 * that already exists. A password reset has no in-app notification — the person cannot sign in, which
 * is the entire reason they are being emailed — and it happens with no tenant resolved at all. An
 * invitation is addressed to somebody who has no user row yet. Forcing either into that table would
 * mean inventing an AppNotification nobody will ever see, so that a foreign key can be satisfied.
 *
 * ## What this replaces, and why it is not cosmetic
 *
 * Three flows wrote `delivery_status => 'awaiting_provider_credentials'` as a LITERAL — the workspace
 * invitation, the registration email challenge, and the mobile code. The value was true, because
 * nothing was wired. It was also unconditional: none of the three composed a message, so the day real
 * SMTP credentials arrive, every one of them would go on reporting «awaiting credentials» and no
 * invitation would ever be delivered. The honesty was hard-coded, and hard-coded honesty stops being
 * honest the moment the world changes.
 *
 * A status here is the RESULT of an attempt: `sent` only after the transport returned without
 * throwing, `awaiting_credentials` when the channel says it has none, `sandbox` when the driver works
 * and reaches nobody, `failed` with the transport's own message.
 *
 * ## `dedup_key`
 *
 * Unique and nullable. A caller that can name the thing being sent — invitation `X`, reset token `Y` —
 * gets exactly-once delivery from the index rather than from a check-then-send, and the window
 * between those two is where a retried job sends somebody a second copy of their own password reset.
 * Callers with nothing meaningful to name pass null, and NULLs do not collide in a unique index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Both nullable ON PURPOSE. A password reset is requested by somebody with no session and
            // no resolved tenant; an invitation is addressed to somebody who has no user row yet.
            $table->uuid('tenant_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();

            // `password_reset` | `invitation` | `email_verification` | `security_alert` | `sign_in_code`
            $table->string('kind', 40);
            /*
             * The recipient address, stored deliberately.
             *
             * Operational visibility is the requirement — «who did we email, when, and did it
             * arrive?» — and it cannot be answered from a user id when half of these messages are
             * addressed to people who are not users yet.
             */
            $table->string('recipient', 190);
            $table->string('locale', 5)->default('ar');

            /*
             * The view that rendered it, rather than an invented version number.
             *
             * A «template version» that nothing increments is a column of the string `v1`, which
             * looks like provenance and carries none. The view name is what a person debugging a
             * malformed message actually needs, and it changes when the template does.
             */
            $table->string('template', 60);

            // sent | awaiting_credentials | sandbox | failed | suppressed
            $table->string('status', 32);
            /*
             * What the transport looked like at the moment of the attempt — see `MailTransportState`.
             *
             * Recorded per attempt rather than read at display time, because «this failed while we
             * were still on the log driver» and «this failed on live SMTP» are different incidents
             * and the second one is the only one worth waking somebody for.
             */
            $table->string('transport', 24);
            $table->unsignedSmallInteger('attempts')->default(1);
            $table->text('error')->nullable();
            $table->string('dedup_key', 120)->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampsTz();

            $table->unique('dedup_key');
            $table->index(['tenant_id', 'kind', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_deliveries');
    }
};
