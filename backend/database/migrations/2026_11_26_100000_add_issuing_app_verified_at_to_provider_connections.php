<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * META-MULTI-APP-001 — when Meta itself confirmed which app issued a connection's token.
 *
 * `issuing_app_id` alone cannot tell a verified fact from a recorded assumption: rows stamped since 2026-10-30 carry the app the product
 * BELIEVED issued the token. This column is set only by a successful `debug_token` check (the audit command with `--apply`, or an atomic
 * reconnect), so «unverified» is a visible, countable state rather than a guess.
 *
 * Additive and nullable. NO backfill: every existing row stays NULL = unverified until a per-connection proof says otherwise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_connections', function (Blueprint $table): void {
            $table->timestamp('issuing_app_verified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('provider_connections', function (Blueprint $table): void {
            $table->dropColumn('issuing_app_verified_at');
        });
    }
};
