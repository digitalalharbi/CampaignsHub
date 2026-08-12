<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LEGAL-DELETE-001 — a destructive request has to prove the requester holds the address.
 *
 * `data_requests` accepted a deletion naming any email, from anyone. The `verifying` status existed
 * in the model's enum and nothing ever set it, so «somebody typed this address into a form» and
 * «somebody who can read that inbox asked for this» were the same event to us. For an export that is
 * a disclosure; for a deletion it is somebody else's data destroyed on a stranger's say-so.
 *
 * The proof is a code sent to the stated address, stored HASHED — a table of live codes beside the
 * requests they unlock is a table that deletes accounts for whoever reads it.
 *
 * Non-destructive requests are untouched: a correction still opens `pending`, because the operator
 * reads it and acts on judgement rather than executing it.
 *
 * Additive. Existing rows get NULLs, which read correctly — they were opened before verification
 * existed and were only ever actioned by an operator who looked at them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_requests', function (Blueprint $table): void {
            // SHA-256 of the code. Never the code itself — see the class docblock.
            $table->string('verification_hash', 64)->nullable()->after('blockers');
            $table->timestamp('verification_sent_at')->nullable()->after('verification_hash');
            $table->timestamp('verification_expires_at')->nullable()->after('verification_sent_at');
            $table->timestamp('verified_at')->nullable()->after('verification_expires_at');

            // Five wrong answers retire the code. Recorded rather than counted in a cache, because a
            // cache eviction must not hand somebody a fresh five attempts.
            $table->unsignedSmallInteger('verification_attempts')->default(0)->after('verified_at');

            // Which platform asked, when a provider's callback opened this rather than a person.
            $table->string('source', 40)->default('web')->after('locale');
            $table->string('source_provider', 40)->nullable()->after('source');

            $table->index(['status', 'verification_expires_at']);
        });

        /*
         * Rows that predate this are marked verified at their creation time.
         *
         * Not because anybody proved anything — because leaving them NULL would make an operator's
         * existing queue unactionable overnight, and these were already handled by a person reading
         * them. `source` says `web` for all of them, which is what they were.
         */
        DB::table('data_requests')->whereNull('verified_at')->update([
            'verified_at' => DB::raw('created_at'),
        ]);
    }

    public function down(): void
    {
        Schema::table('data_requests', function (Blueprint $table): void {
            $table->dropIndex(['status', 'verification_expires_at']);
            $table->dropColumn([
                'verification_hash', 'verification_sent_at', 'verification_expires_at',
                'verified_at', 'verification_attempts', 'source', 'source_provider',
            ]);
        });
    }
};
