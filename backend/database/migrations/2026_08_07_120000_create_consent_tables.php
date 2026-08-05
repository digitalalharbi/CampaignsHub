<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LEGAL-003 — what somebody agreed to, and when, and which words they were shown.
 *
 * ## Why the VERSION is the point
 *
 * «The user accepted the terms» is worth very little a year later. «The user accepted terms v1.0,
 * effective 2026-08-07, on this date from this address» is a record that survives a dispute — and it
 * only works because the version points at text held in git, which cannot be edited after the fact.
 * A row saying `accepted: true` against a policy somebody rewrote last week is worse than no record,
 * because it looks like evidence.
 *
 * ## Why cookie consent is a separate table
 *
 * A policy acceptance belongs to a user; cookie consent belongs to a BROWSER, and usually to one
 * that has no account yet. Forcing them into one table would mean either inventing a user for every
 * anonymous visitor or leaving a user column null on most rows and hoping nobody joins on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_acceptances', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            /*
             * Nullable, because acceptance happens BEFORE the account exists.
             *
             * Registration is the main case: the visitor ticks the box, and the user row is created
             * moments later. The acceptance is linked once we have an id; until then the email is
             * what ties it to the person.
             */
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('email', 160)->nullable()->index();

            $table->string('document', 40);
            $table->string('version', 20);
            $table->date('effective');

            /** registration | payment | reacceptance — what the person was doing when they agreed. */
            $table->string('context', 30)->default('registration');

            $table->timestamp('accepted_at');
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('locale', 5)->default('ar');

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();

            /*
             * No unique constraint on (user, document, version).
             *
             * Re-accepting the same version happens legitimately — a second purchase, a re-confirmation
             * after a support conversation — and each is a separate fact with its own timestamp. A
             * unique index would silently discard the later one, which is exactly the evidence somebody
             * would later want.
             */
            $table->index(['user_id', 'document']);
            $table->index('accepted_at');
        });

        Schema::create('cookie_consents', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            /**
             * The browser this decision belongs to. Generated client-side and stored in a first-party
             * cookie — itself strictly necessary, because without it we cannot remember a refusal and
             * would have to ask again on every page, which is its own kind of dark pattern.
             */
            $table->string('visitor_id', 64)->index();
            $table->unsignedBigInteger('user_id')->nullable();

            /*
             * Categories, each explicitly true or false rather than «present means yes».
             *
             * `necessary` is stored even though it is always true: a record that omitted it would be
             * indistinguishable from one written before the category existed.
             */
            $table->boolean('necessary')->default(true);
            $table->boolean('analytics')->default(false);
            $table->boolean('marketing')->default(false);

            $table->string('policy_version', 20);
            $table->timestamp('decided_at');
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->index('decided_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cookie_consents');
        Schema::dropIfExists('policy_acceptances');
    }
};
