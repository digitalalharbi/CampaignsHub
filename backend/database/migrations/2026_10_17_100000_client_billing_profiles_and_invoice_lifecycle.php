<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INV-PROFILE-001, INV-LIFECYCLE-001, INV-IMMUTABLE-001 — the agency-to-client fee invoice.
 *
 * ## Same entity, not a second billing system
 *
 * `invoices` already holds what an agency bills a client (it was born from quotes and is paid through a
 * gateway). A weekly fee invoice is the same document from a different source, so it lives in the same
 * table with the few columns the new lifecycle needs, and everything that already reads invoices — the
 * client portal, the receivables worklist, the payment flow — keeps working unchanged.
 *
 * ## What the profile stores
 *
 * HOW a client is billed, once, so each week's draft is produced rather than typed: the fee model, its
 * numbers, the cadence, the currency, the VAT treatment, the due terms and the payment details. The
 * issuer and bill-to blocks carry the legal identities a tax invoice must show.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_billing_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('client_workspace_id');
            $table->string('fee_model')->default('manual');         // fixed | percentage | manual
            $table->decimal('fixed_amount', 15, 2)->nullable();
            $table->decimal('percentage', 6, 3)->nullable();          // of the period's managed spend
            $table->decimal('minimum_fee', 15, 2)->nullable();
            $table->string('cadence')->default('weekly');            // weekly | monthly | custom
            $table->string('currency', 3)->default('SAR');
            $table->string('tax_treatment')->default('basic_15');
            $table->unsignedSmallInteger('due_days')->default(7);
            $table->jsonb('issuer')->nullable();                      // legal name, VAT number, address
            $table->jsonb('bill_to')->nullable();                     // legal name, VAT number, address
            $table->jsonb('payment_details')->nullable();             // bank, beneficiary, IBAN
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'client_workspace_id']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->date('period_start')->nullable()->after('due_date');
            $table->date('period_end')->nullable()->after('period_start');
            $table->string('source')->default('quote')->after('period_end');       // quote | billing_profile | manual
            $table->timestampTz('reviewed_at')->nullable()->after('issued_at');
            $table->unsignedBigInteger('reviewed_by')->nullable()->after('reviewed_at');
            $table->timestampTz('sent_at')->nullable()->after('reviewed_by');
            $table->unsignedBigInteger('supersedes_id')->nullable()->after('sent_at');
            $table->text('correction_reason')->nullable()->after('supersedes_id');
            $table->jsonb('profile_snapshot')->nullable()->after('correction_reason');
            $table->index(['tenant_id', 'client_workspace_id', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'client_workspace_id', 'period_end']);
            $table->dropColumn(['period_start', 'period_end', 'source', 'reviewed_at', 'reviewed_by', 'sent_at', 'supersedes_id', 'correction_reason', 'profile_snapshot']);
        });
        Schema::dropIfExists('client_billing_profiles');
    }
};
