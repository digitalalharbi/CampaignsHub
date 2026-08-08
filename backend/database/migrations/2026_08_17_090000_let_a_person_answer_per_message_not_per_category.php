<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-TYPE notification choices — MAIL-011.
 *
 * ## Why a new column rather than a wider `categories`
 *
 * `categories` holds six keys and every existing row means something by them. Widening it in place
 * would need this migration to guess what a person meant by «الأداء» about a message type that did
 * not exist when they chose it — and a wrong guess here does not throw, it silently mails somebody
 * something they thought they had switched off, or silently stops mailing them something they need.
 *
 * A separate column lets the resolver ask the precise question first and fall back to the older,
 * coarser answer only where the type genuinely lived under that category. Nobody's stored choice is
 * rewritten, and no default is invented on their behalf.
 *
 * NULL means «has not answered per type», which is different from «answered nothing» (`{}`) — the
 * first inherits their category settings, the second is a person who cleared every switch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_preferences', function (Blueprint $table): void {
            // { "<type>": { "email": bool, "in_app": bool, "rhythm": "immediate|daily|weekly" } }
            // Sparse by design: only the types this person has actually touched are stored.
            $table->jsonb('types')->nullable()->after('categories');
        });
    }

    public function down(): void
    {
        Schema::table('notification_preferences', function (Blueprint $table): void {
            $table->dropColumn('types');
        });
    }
};
