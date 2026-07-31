<?php

declare(strict_types=1);

use App\Domains\Accounts\Enums\AccountState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where an account is on the path to being usable (SIGNUP-001).
 *
 * `status` already existed and held four loose values — `active`, `trialing`, `suspended`,
 * `inactive` — which is enough to answer "may this workspace be used?" and nothing else. It cannot
 * express "waiting for a human to approve", "approved but unpaid", or "the customer pressed pay and
 * the provider has not confirmed", so the gated registration path had nowhere to live.
 *
 * This adds `account_state` alongside it rather than widening `status` in place, because the two are
 * different questions and conflating them is what made the old column useless: `status` is the
 * operational switch that `AccountSuspension` and the middleware read, and `account_state` is the
 * position in the lifecycle. They are kept consistent by `TransitionAccountState`, which is the only
 * thing that writes either.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            // Nullable for the length of this migration only — backfilled below, then made NOT NULL,
            // so no row ever exists without a state for later code to guess at.
            $table->string('account_state', 40)->nullable()->after('status');

            // When the account became usable. Null until it does, which makes "was this ever
            // activated?" answerable without reading an audit log.
            $table->timestamp('activated_at')->nullable()->after('account_state');
            // Why an account was refused or switched off. Shown to the owner, so it is written in
            // words a customer can act on rather than an internal code.
            $table->text('state_reason')->nullable()->after('activated_at');
            $table->timestamp('state_changed_at')->nullable()->after('state_reason');
        });

        /*
         * Backfill from the operational column.
         *
         * `trialing` maps to Active, not to some waiting state: a trial is a working account, and
         * the people in one are using the product right now. Treating them as pre-activation would
         * revoke access from every existing trial the moment this deploys.
         */
        DB::table('tenants')->whereNull('account_state')->update([
            'account_state' => DB::raw(
                "CASE status
                    WHEN 'active' THEN '".AccountState::Active->value."'
                    WHEN 'trialing' THEN '".AccountState::Active->value."'
                    WHEN 'suspended' THEN '".AccountState::Suspended->value."'
                    WHEN 'inactive' THEN '".AccountState::Suspended->value."'
                    ELSE '".AccountState::Active->value."'
                END"
            ),
        ]);

        // Every existing workspace is one that already works, so it has been activated — dated from
        // its own creation rather than from this deploy, which would claim they all started today.
        DB::statement('UPDATE tenants SET activated_at = created_at
            WHERE activated_at IS NULL AND account_state = ?', [AccountState::Active->value]);

        DB::statement('UPDATE tenants SET state_changed_at = updated_at WHERE state_changed_at IS NULL');

        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('account_state', 40)->nullable(false)->default(AccountState::Draft->value)->change();
        });

        // Answering "what is waiting for approval?" and "what is unpaid?" is the admin queue's whole
        // job, and it will ask on every page load.
        DB::statement('CREATE INDEX tenants_account_state_index ON tenants (account_state)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS tenants_account_state_index');

        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn(['account_state', 'activated_at', 'state_reason', 'state_changed_at']);
        });
    }
};
