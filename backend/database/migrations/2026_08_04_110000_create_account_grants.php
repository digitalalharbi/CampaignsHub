<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GRANT-001 — an administrative exception, written down.
 *
 * The brief asks the platform owner to be able to give one account something its plan does not
 * include: an extra capability, a module, or a subscription outright, and to take it back again
 * without touching anybody else. The obvious implementations are both wrong.
 *
 * Editing the tenant's plan is wrong because it re-prices and re-limits them, and because "why does
 * this workspace have that?" then has no answer beyond a column that was set at some point.
 * Editing the PLAN is wrong because it grants the same thing to every customer on it.
 *
 * So a grant is its own row: one account, one thing, who granted it, why, and — separately — who
 * revoked it and when. It is additive only. There is no grant that TAKES a capability away, because
 * an exception that subtracts is a suspension, and this platform already has one of those which
 * preserves the customer's data. `AccountEntitlements` unions the active grants over what the plan
 * already allows and can therefore only ever widen; a bug in this table cannot lock a paying
 * customer out of something they bought.
 *
 * Fail-closed by construction: nothing reads a grant that has been revoked or has expired, and the
 * only route that writes one is behind the `platform` middleware. A tenant user cannot reach it, so
 * no user can grant themselves anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_grants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');

            /*
             * WHAT was granted.
             *
             *  - `section`      a single nav capability (`reports`, `clients`, …) beyond the portal's
             *                   entitlements for this workspace.
             *  - `module`       a marketing module (`influencer_marketing`) the plan did not include.
             *  - `plan`         a complimentary subscription: `value` is the plan code.
             *  - `full_access`  every section every portal this workspace holds offers. `value` is
             *                   unused and stored as an empty string so the unique index still works.
             */
            $table->string('kind', 24);
            $table->string('value', 64)->default('');

            // Why. Not nullable and not blank-able at the application layer: a grant nobody can
            // explain is one nobody can safely revoke.
            $table->text('reason');

            // Who. `granted_by` is a platform user; the FK is deliberately absent so deleting a
            // staff account cannot silently delete the record of what they gave away.
            $table->unsignedBigInteger('granted_by')->nullable();
            $table->timestamp('granted_at')->useCurrent();

            /*
             * An optional end date, and an explicit revocation. Two fields because they are two
             * different events: a grant that lapsed on its own terms and one somebody took back.
             */
            $table->timestamp('expires_at')->nullable();
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revoked_reason')->nullable();

            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            /*
             * One LIVE grant of a given thing per account.
             *
             * Partial, on `revoked_at IS NULL`, so the same capability can be granted again after
             * being revoked — which is the normal case — while a double-click cannot create two
             * concurrent grants that then need two revocations to actually remove.
             */
            $table->index(['tenant_id', 'revoked_at']);
        });

        // Postgres-only partial unique index; the sqlite fallback used by some test runs skips it.
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::getConnection()->statement(
                'CREATE UNIQUE INDEX account_grants_live_unique
                 ON account_grants (tenant_id, kind, value)
                 WHERE revoked_at IS NULL'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('account_grants');
    }
};
