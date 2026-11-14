<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PLATFORM-INBOX-ATTENTION-001 — what is addressed to the platform is read, and answered on the record.
 *
 * `read_at` / `read_by` on the three public queues (contact, support, data requests): «unread» is a fact —
 * nobody on the platform has opened this row — rather than a guess from a status. Nullable and additive: every
 * existing row reads as unread, which is the truth (no screen ever showed one), and nothing is rewritten.
 *
 * `platform_inbox_replies`: an administrator's answer to a visitor, kept beside the mail-ledger key of the message
 * that carried it, so «did we answer, and did it leave» is one row and not a search through mail logs. The
 * recipient is copied from the record answered, never typed. Belongs to no tenant.
 *
 * Reversible: `down()` drops the table and the four columns, nothing else.
 */
return new class extends Migration
{
    private const QUEUES = ['contact_messages', 'support_tickets', 'data_requests'];

    public function up(): void
    {
        foreach (self::QUEUES as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->timestampTz('read_at')->nullable();
                $t->foreignId('read_by')->nullable()->constrained('users')->nullOnDelete();
                $t->index('read_at');
            });
        }

        Schema::create('platform_inbox_replies', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            // `contact_message` · `support_ticket` · `service_request`.
            $t->string('subject_type', 32);
            $t->string('subject_id', 64);
            $t->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('recipient', 190);
            $t->text('body');
            $t->string('delivery_status', 40);
            $t->string('mail_dedup_key', 190)->nullable();
            $t->timestampsTz();
            $t->index(['subject_type', 'subject_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_inbox_replies');

        foreach (self::QUEUES as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->dropIndex(['read_at']);
                $t->dropConstrainedForeignId('read_by');
                $t->dropColumn('read_at');
            });
        }
    }
};
