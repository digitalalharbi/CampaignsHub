<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TWO-STEP-001 — the second step, as methods a person holds rather than one flag nobody read.
 *
 * `two_factor_enabled` stays as the summary every existing reader uses (team list, console, user resource): it is true while at
 * least one method below is active. An account that already confirmed an authenticator keeps it — the backfill marks that method
 * active, so turning the gate on does not silently drop what the owner set up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('two_factor_totp_confirmed_at')->nullable()->after('two_factor_enabled');
            // The last 30-second step an authenticator code was accepted for: a code is never accepted twice.
            $table->unsignedBigInteger('two_factor_totp_last_step')->nullable()->after('two_factor_totp_confirmed_at');
            $table->timestamp('two_factor_email_enabled_at')->nullable()->after('two_factor_totp_last_step');
            $table->timestamp('two_factor_sms_enabled_at')->nullable()->after('two_factor_email_enabled_at');
            $table->string('two_factor_default_method', 16)->nullable()->after('two_factor_sms_enabled_at');
            // sha256 of each one-time recovery code; a used code is removed from the list.
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_default_method');
        });

        DB::table('users')->where('two_factor_enabled', true)->whereNotNull('two_factor_secret')->update([
            'two_factor_totp_confirmed_at' => now(),
            'two_factor_default_method' => 'totp',
        ]);
        // A flag with no secret behind it protected nothing and could not be answered: it is cleared, not enforced.
        DB::table('users')->where('two_factor_enabled', true)->whereNull('two_factor_secret')->update(['two_factor_enabled' => false]);

        /*
         * Every session, remember-me cookie and API token such an account holds was opened WITHOUT the second step, because until
         * now no sign-in asked for it. The repository's «sign out everywhere» (UserSessions::revokeAll) for exactly those accounts:
         * the next sign-in meets the challenge. Nothing else is touched.
         */
        $enforced = DB::table('users')->where('two_factor_enabled', true)->pluck('id');
        if ($enforced->isNotEmpty()) {
            DB::table('users')->whereIn('id', $enforced)->update(['sessions_valid_after' => now(), 'remember_token' => null]);
            // Expired rather than deleted: the token stops working at once and the row stays, so nothing is destroyed.
            if (Schema::hasColumn('personal_access_tokens', 'expires_at')) {
                DB::table('personal_access_tokens')->where('tokenable_type', 'App\\Models\\User')->whereIn('tokenable_id', $enforced)
                    ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                    ->update(['expires_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'two_factor_totp_confirmed_at', 'two_factor_totp_last_step', 'two_factor_email_enabled_at',
                'two_factor_sms_enabled_at', 'two_factor_default_method', 'two_factor_recovery_codes',
            ]);
        });
    }
};
