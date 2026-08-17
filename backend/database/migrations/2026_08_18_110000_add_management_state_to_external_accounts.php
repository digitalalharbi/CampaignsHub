<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * COMMAND-CENTER §7 — the customer's decision about an account, recorded once.
 *
 * Additive, and nullable on purpose. NULL means «discovered»: the provider returned it and nobody
 * has said anything about it yet. Every account that exists before this migration is exactly that,
 * so a default would be a claim about rows nobody has looked at — and a NOT NULL default of
 * 'discovered' would make «nobody has decided» and «somebody decided it is undecided» the same
 * value, which is the conflation this whole column exists to end.
 *
 * `assigned` is deliberately NOT storable here. Assignment is the answer of
 * `ProjectIntegrationBinding` and copying it into a second column is how this product ended up with
 * three different answers to «who owns this account» in the first place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_accounts', function (Blueprint $table): void {
            $table->string('management_state', 16)->nullable()->after('status');
            $table->timestampTz('management_state_changed_at')->nullable()->after('management_state');
            $table->index(['tenant_id', 'management_state'], 'external_accounts_tenant_state_index');
        });
    }

    public function down(): void
    {
        Schema::table('external_accounts', function (Blueprint $table): void {
            $table->dropIndex('external_accounts_tenant_state_index');
            $table->dropColumn(['management_state', 'management_state_changed_at']);
        });
    }
};
