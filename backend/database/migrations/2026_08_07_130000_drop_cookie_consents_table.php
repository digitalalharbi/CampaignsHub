<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LEGAL-003 — the consent table goes, because there is nothing to consent to.
 *
 * The cookie banner was withdrawn: this application sets strictly necessary cookies only — session,
 * CSRF, language and theme — and no analytics or marketing cookie exists to be gated. A stored
 * consent record for a choice nobody was offered is not neutral. It is evidence of a decision that
 * never happened, and the kind of thing an auditor reads as a claim.
 *
 * `policy_acceptances` is untouched and stays: agreeing to the terms and the privacy policy at
 * registration and at payment is a real act by a real person, and entirely separate from cookies.
 *
 * `down()` recreates the table exactly, so reintroducing consent later — which must happen in the
 * same change as whatever non-essential cookie needs it — starts from the same shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('cookie_consents');
    }

    public function down(): void
    {
        Schema::create('cookie_consents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('visitor_id', 64)->index();
            $table->unsignedBigInteger('user_id')->nullable();
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
};
