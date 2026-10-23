<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FORMS-REFERRALS-001 — a lead may have come because another lead told them.
 *
 * `referred_by_lead_id` is written ONCE, at arrival, from a signed code the referrer's link carried — never from a value the
 * submitter typed. It is provenance, so it joins the columns `UpdateLead` strips and the team cannot rewrite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->uuid('referred_by_lead_id')->nullable()->after('canonical_lead_id');
            $table->index(['tenant_id', 'referred_by_lead_id'], 'leads_referred_by_idx');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->dropIndex('leads_referred_by_idx');
            $table->dropColumn('referred_by_lead_id');
        });
    }
};
