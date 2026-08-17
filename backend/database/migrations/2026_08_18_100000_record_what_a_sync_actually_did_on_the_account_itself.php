<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RUNTIME-100 §30 — the three questions `last_synced_at` alone cannot answer.
 *
 * ## What one timestamp could and could not say
 *
 * An account carried `last_synced_at` and nothing else. That single column has to stand in for:
 *
 *  - «nobody has ever tried» — null,
 *  - «we tried an hour ago and the provider refused» — also whatever it was before, so: stale,
 *  - «we succeeded at 03:00 and are due again at 03:30» — a timestamp that will also look stale
 *    the moment the sweep is late.
 *
 * All three render as an absent or old date, so a broken integration and a brand-new one are the
 * same pixel on every screen, and «آخر مزامنة: قبل يومين» is shown to somebody whose real problem is
 * that their token was revoked on Tuesday.
 *
 * Three additive columns, each answering exactly one of those:
 *
 *  - `last_sync_attempt_at` — we tried. Written on every outcome, including the refusals.
 *  - `last_sync_error_category` — WHY it did not work, as a category rather than a sentence, because
 *    the category is what decides who has to act: an operator adds credentials, a customer
 *    re-authorises, and nobody acts on a provider having a bad minute.
 *  - `next_sync_at` — when we will ask again, so «it is old» can be answered with «and it refreshes
 *    at 03:30» instead of leaving somebody to press a button and hope.
 *
 * Additive and nullable, and nothing is backfilled: a null here means «we have never recorded this»,
 * which is the truth for every row that predates the change. Inventing a value would be the same
 * class of claim as writing `last_synced_at` during discovery (DISCOVERY-NOT-SYNC-001).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_accounts', function (Blueprint $table): void {
            $table->timestampTz('last_sync_attempt_at')->nullable();
            $table->string('last_sync_error_category')->nullable();
            $table->timestampTz('next_sync_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('external_accounts', function (Blueprint $table): void {
            $table->dropColumn(['last_sync_attempt_at', 'last_sync_error_category', 'next_sync_at']);
        });
    }
};
