<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SUPERADMIN-ACCESS-001 — every session a user signed in before this moment is no longer theirs.
 *
 * Sessions live in Redis with no index by user, so «sign out everywhere» cannot enumerate them. Instead each session records when
 * it signed in, and `RejectRevokedSessions` refuses one that signed in before the user's cut-off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('sessions_valid_after')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('sessions_valid_after');
        });
    }
};
