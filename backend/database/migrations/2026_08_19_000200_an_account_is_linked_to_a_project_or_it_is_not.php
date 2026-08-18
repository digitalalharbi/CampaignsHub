<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INTEG-RUNTIME §5 — removing the curation state that was never part of the journey.
 *
 * `external_accounts.management_state` held a four-state workflow — discovered, enabled, excluded —
 * that the customer was asked to understand and that changed nothing. Enabling an account did not
 * make it sync, did not attach it to anything and did not spend a quota slot; only a binding to a
 * project ever did any of those. It was internal bookkeeping promoted to customer-facing vocabulary,
 * and the brief that governs this work removes the step outright: OAuth → discovery → organisation →
 * account → project → confirm → first sync, with nothing in the middle whose only effect is to
 * change a word on a chip.
 *
 * Nothing is lost by dropping it. The column's only readers were the endpoints removed alongside it,
 * and the one fact that matters — is this account linked to a project — was never stored here in the
 * first place: it is `ProjectIntegrationBinding` where `is_active`, which is untouched.
 *
 * `down()` restores the column, nullable, exactly as it was. It cannot restore the values, and it
 * should not pretend to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_accounts', function (Blueprint $table) {
            $table->dropIndex('external_accounts_tenant_state_index');
            $table->dropColumn(['management_state', 'management_state_changed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('external_accounts', function (Blueprint $table) {
            $table->string('management_state', 16)->nullable()->after('status');
            $table->timestampTz('management_state_changed_at')->nullable()->after('management_state');
            $table->index(['tenant_id', 'management_state'], 'external_accounts_tenant_state_index');
        });
    }
};
