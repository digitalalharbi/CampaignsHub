<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LEGAL-002 — the three things a public visitor can actually send us, and the record each leaves.
 *
 * ## Why these are not `external_requests`
 *
 * That table is the paid-media intake: a commercial request that enters a workflow, gets an SLA, is
 * assigned to a project and becomes billable work. A «how much does this cost» enquiry, a support
 * question and a data-deletion demand are none of those things, and filing them together would put a
 * legal obligation with a two-week statutory clock into the same queue an operator triages for sales.
 *
 * ## Why a data request is its own table rather than a support ticket with a type
 *
 * Because it can be REFUSED, and the refusal has to be recorded with its reason. A deletion request
 * against an account with unpaid invoices is not a ticket somebody forgot to close — it is a request
 * the operator is obliged to answer and obliged not to execute yet, and the difference between those
 * two states is the whole point of keeping it separately.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Contact enquiries. Deliberately thin: a name, a way to reply, and what they said.
         *
         * No tenant_id — the sender is usually not a customer yet, and inventing a tenancy for them
         * would either attach a stranger's message to somebody's workspace or require a fake one.
         */
        Schema::create('contact_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 160);
            $table->string('email', 160);
            $table->string('phone', 40)->nullable();
            $table->string('company', 160)->nullable();
            $table->string('subject', 200);
            $table->text('message');

            // new → read → answered → closed. `spam` exists so a rejected message can be marked
            // rather than deleted; deleting it loses the evidence that it was judged.
            $table->string('status', 20)->default('new')->index();
            $table->text('operator_note')->nullable();
            $table->unsignedBigInteger('handled_by')->nullable();
            $table->timestamp('handled_at')->nullable();

            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('locale', 5)->default('ar');
            $table->timestamps();

            $table->foreign('handled_by')->references('id')->on('users')->nullOnDelete();
            $table->index('created_at');
        });

        /*
         * Support tickets. The difference from a contact message is the REFERENCE.
         *
         * A support ticket is something the sender expects to be able to follow up on, so it gets a
         * short human-quotable code they can put in a reply or read down a phone. That is also why
         * the code is stored in the clear while a report-share token is hashed: this one identifies a
         * conversation, it does not grant access to anything.
         */
        Schema::create('support_tickets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('reference', 20)->unique();

            $table->string('name', 160);
            $table->string('email', 160);
            $table->string('phone', 40)->nullable();
            $table->string('subject', 200);
            $table->text('message');
            $table->string('category', 40)->default('general');

            $table->string('status', 20)->default('open')->index();
            $table->string('priority', 20)->default('normal');
            $table->text('operator_note')->nullable();
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->timestamp('resolved_at')->nullable();

            // Set when the sender was signed in — a ticket from a known account is worth more context.
            $table->unsignedBigInteger('user_id')->nullable();
            $table->uuid('tenant_id')->nullable();

            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('locale', 5)->default('ar');
            $table->timestamps();

            $table->foreign('assigned_to')->references('id')->on('users')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('tenant_id')->references('id')->on('tenants')->nullOnDelete();
            $table->index('created_at');
        });

        /*
         * Data-subject requests: export, correction, deletion of specific data, deletion of an account.
         *
         * `blockers` is the field that makes this table worth having. A deletion request against an
         * account with open invoices is not refused and forgotten — the reasons are recorded, shown to
         * the requester, and remain there for whoever reviews it later. A boolean «rejected» would
         * lose exactly the part that matters.
         */
        Schema::create('data_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('reference', 20)->unique();

            // export | correction | delete_data | delete_account
            $table->string('type', 30)->index();

            $table->string('name', 160);
            $table->string('email', 160);
            $table->string('phone', 40)->nullable();
            $table->text('details')->nullable();

            // pending → verifying → in_review → blocked → completed | rejected | withdrawn
            $table->string('status', 20)->default('pending')->index();

            /** Why it cannot proceed yet — invoices, an active subscription, a legal hold. */
            $table->jsonb('blockers')->nullable();

            $table->text('operator_note')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->unsignedBigInteger('user_id')->nullable();
            $table->uuid('tenant_id')->nullable();

            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('locale', 5)->default('ar');
            $table->timestamps();

            $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('tenant_id')->references('id')->on('tenants')->nullOnDelete();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_requests');
        Schema::dropIfExists('support_tickets');
        Schema::dropIfExists('contact_messages');
    }
};
