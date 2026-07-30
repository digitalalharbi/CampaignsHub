<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The creator's own side of the influencers portal (INFL-002, ADR 0002).
 *
 * INFL-001 built the agency's view of this domain: a roster, agreements, deliverables — all read and
 * written by an operator. The portal's promise is that "brand / agency / influencer / creator each
 * see a different surface", and this is what the creator's surface needs.
 *
 * TWO additions, and the reasoning for each matters more than the columns:
 *
 * 1. `influencers.user_id` — the roster entry IS the creator's identity. Deliberately not a second
 *    scope table alongside `membership_scopes`: two mechanisms answering "which creator is this?"
 *    drift, and the day they disagree one of them is showing somebody another creator's fee. The
 *    membership says the user is in this portal as a creator; this column says which creator.
 *
 * 2. The agreement fields. A collaboration previously had one status, which cannot distinguish
 *    "we are still deciding what to offer" from "offered, awaiting their answer" from "they said no".
 *    That distinction is the whole of the agreement step, and without it the creator's surface would
 *    have to show every draft — including fees still being argued about internally.
 *
 *    `terms_sent_at` is therefore the gate: NULL means the creator cannot see this collaboration at
 *    all. Not a status value, because a status can be set to anything by a form; this is a fact about
 *    whether an offer was actually made.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('influencers', function (Blueprint $table): void {
            // nullOnDelete, not cascade: deleting the login must not delete the roster entry. The
            // agency's record of who they worked with, and what it cost, outlives an account.
            $table->foreignId('user_id')->nullable()->after('owner_id')
                ->constrained('users')->nullOnDelete();
        });

        // One login is at most one creator per tenant. Without the predicate this reads as if the
        // many roster entries with no login yet would collide with each other.
        DB::statement(
            'CREATE UNIQUE INDEX influencers_tenant_user_unique
             ON influencers (tenant_id, user_id)
             WHERE user_id IS NOT NULL AND deleted_at IS NULL'
        );

        Schema::table('influencer_collaborations', function (Blueprint $table): void {
            // The gate. NULL = never offered, so the creator does not see it.
            $table->timestamp('terms_sent_at')->nullable()->after('internal_notes');
            $table->string('creator_decision')->nullable()->after('terms_sent_at'); // accepted|declined
            $table->timestamp('creator_responded_at')->nullable()->after('creator_decision');
            $table->text('creator_decline_reason')->nullable()->after('creator_responded_at');
        });
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS influencers_tenant_user_unique');

        Schema::table('influencer_collaborations', function (Blueprint $table): void {
            $table->dropColumn(['terms_sent_at', 'creator_decision', 'creator_responded_at', 'creator_decline_reason']);
        });

        Schema::table('influencers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
