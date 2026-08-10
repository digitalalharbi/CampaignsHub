<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LOGIN-HELP-001 — what the sender needs, and where they were standing when they asked.
 *
 * The «تواصل معنا» panel on `/login` asks a question the free-text contact form never did: which of
 * five things do you need. Storing that answer inside `subject` as a sentence would make it
 * unsortable — the operator queue could show it but could not group by it, which is the only reason
 * to ask a closed question instead of an open one.
 *
 * `source` is a triage hint, not a fact about the sender: an enquiry raised from the sign-in page is
 * usually somebody who cannot get in, and one from the pricing page usually is not. Both are
 * nullable, because every message already in the table predates the question.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_messages', function (Blueprint $table): void {
            $table->string('topic', 40)->nullable()->after('company');
            $table->string('source', 40)->nullable()->after('topic');
        });
    }

    public function down(): void
    {
        Schema::table('contact_messages', function (Blueprint $table): void {
            $table->dropColumn(['topic', 'source']);
        });
    }
};
