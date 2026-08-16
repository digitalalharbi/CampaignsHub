<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ORCH-100 §3 — the parent entity's NAME, so the wizard can offer it by name.
 *
 * `parent_external_id` has always been stored, and an id is not a thing a person chooses between.
 * The Snapchat adapter already returns the organisation's name alongside it (SNAP-ORG-001) and the
 * OAuth controller was dropping it on the floor; with 309 accounts under several organisations, an
 * agency needs «Acme Media» rather than `0f2c…`.
 *
 * Additive and nullable. Accounts discovered before this simply have no parent name until the next
 * discovery refresh fills it in, which is a display detail and never a correctness one — the id is
 * what everything joins on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_accounts', function (Blueprint $table): void {
            $table->string('parent_name')->nullable()->after('parent_external_id');
        });
    }

    public function down(): void
    {
        Schema::table('external_accounts', function (Blueprint $table): void {
            $table->dropColumn('parent_name');
        });
    }
};
