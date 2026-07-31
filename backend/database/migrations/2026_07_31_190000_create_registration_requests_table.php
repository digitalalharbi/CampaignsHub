<?php

declare(strict_types=1);

use App\Domains\Accounts\Enums\AccountState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A registration that has NOT yet become a workspace (SIGNUP-002).
 *
 * The contract's rule is that filling in a form opens nothing, and this table is what makes that
 * structurally true rather than a policy someone has to remember. Registration used to create a
 * tenant, a workspace and a membership in one transaction, so "an account waiting for approval" had
 * no representation at all — the only way to express it would have been an inactive tenant that
 * nonetheless owned rows all over the database.
 *
 * A request holds everything the applicant told us and grants nothing. `tenant_id` stays null until
 * the account actually reaches Active, and that column is the record of the crossing: null means no
 * workspace exists for this person, whatever else the row says.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registration_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // What the applicant asked for. `requested_portal` is a REQUEST, never a grant — the
            // membership that eventually gets created is what decides access.
            $table->string('email');
            $table->string('name');
            $table->string('tenant_name');
            $table->string('account_type', 40)->nullable();
            $table->string('requested_portal', 32)->nullable();
            $table->string('plan_code', 64)->nullable();
            $table->string('service', 40)->nullable();

            // The credential is captured here so the account can be created at activation without
            // asking again. Hashed on the way in — a pending registration is still a password.
            $table->string('password');

            $table->string('state', 40)->default(AccountState::Draft->value);
            $table->text('state_reason')->nullable();
            $table->timestamp('state_changed_at')->nullable();

            // Verification. Two separate facts, because a plan may require either, both or neither.
            $table->string('phone', 40)->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('mobile_verified_at')->nullable();

            // Review. `reviewed_by` is a platform user, so it is not tenant-scoped.
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            // What an administrator granted while reviewing: a trial period, a discount, a plan
            // change. Kept as a record of the decision rather than applied silently.
            $table->json('review_concessions')->nullable();

            /*
             * The workspace this request became, once it became one.
             *
             * Null for every request that has not reached Active, which is the single question the
             * whole table exists to answer honestly. `nullOnDelete` rather than cascade: deleting a
             * workspace must not erase the record that someone applied for it.
             */
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->timestamp('provisioned_at')->nullable();

            $table->timestamps();
        });

        /*
         * One LIVE request per email address.
         *
         * A partial index, because the constraint only applies to requests still in flight: someone
         * whose application was rejected must be able to apply again, and someone whose workspace
         * exists has a historical row that must not block a second, unrelated registration later.
         */
        DB::statement("CREATE UNIQUE INDEX registration_requests_live_email_unique
            ON registration_requests (lower(email))
            WHERE tenant_id IS NULL AND state NOT IN ('rejected', 'cancelled', 'expired')");

        // The admin review queue asks "what is waiting for me?" on every page load.
        DB::statement('CREATE INDEX registration_requests_state_index ON registration_requests (state)');
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_requests');
    }
};
