<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AUTH-PHONE-001 — a number is only a credential once somebody proved they hold it.
 *
 * ## The hole this closes
 *
 * `PhoneSignInController` signs in whoever answers a code sent to `users.phone`. `PATCH /me/profile`
 * writes `users.phone` from a payload, with a format rule and nothing else. So a number nobody has
 * ever proved control of was a way into an account — the product's own documentation said the number
 * was «verified during registration», and for anything typed afterwards that simply was not true.
 *
 * The gap is not only adversarial. Somebody correcting a typo in their profile could put a stranger's
 * number on their account, and that stranger — holding their own phone, doing nothing wrong — could
 * then sign in as them.
 *
 * ## Backfilling: existing numbers are treated as verified
 *
 * Every phone already on a user got there through `ProvisionWorkspace`, from a registration that
 * cleared the mobile gate (PHONE-VERIFY-001). Marking them unverified would lock those customers out
 * of a credential they legitimately proved, to fix a hole that opens only for numbers set afterwards.
 * The timestamp is the user's own `created_at`, which is when that proof actually happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('phone_verified_at')->nullable()->after('phone');
        });

        DB::table('users')
            ->whereNotNull('phone')
            ->update(['phone_verified_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('phone_verified_at');
        });
    }
};
