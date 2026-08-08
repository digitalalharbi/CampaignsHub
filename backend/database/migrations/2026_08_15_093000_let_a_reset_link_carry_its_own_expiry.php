<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A link that knows when it dies — MAIL-009.
 *
 * `password_reset_tokens` carried `created_at` and the lifetime lived in a PHP constant, which is fine
 * while there is exactly one kind of link. There are two. A person who asked to reset their own
 * password should have an hour: they are at the keyboard, and a long window is a long window for
 * anybody holding a forwarded copy. A person who has just been added to a workspace has not asked for
 * anything and may not read their mail until tomorrow — an hour means the first thing the product ever
 * sends them is already dead when they open it.
 *
 * With one constant, serving both means picking whichever compromise hurts the other. The expiry
 * belongs on the row that was issued, which is also the only version that stays correct when the
 * constant is later changed: existing links keep the lifetime they were promised rather than silently
 * gaining or losing time.
 *
 * Nullable, and read as «fall back to the issuing default», so rows written before this column exists
 * are still valid tokens rather than instantly-expired ones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('password_reset_tokens', function (Blueprint $table): void {
            $table->timestampTz('expires_at')->nullable();
            // Which message carried it: `password_reset` | `member_setup`. What the link is FOR
            // decides the words the reader saw, and a support answer that contradicts the email they
            // are holding is worse than no answer.
            $table->string('purpose', 32)->default('password_reset');
        });
    }

    public function down(): void
    {
        Schema::table('password_reset_tokens', function (Blueprint $table): void {
            $table->dropColumn(['expires_at', 'purpose']);
        });
    }
};
