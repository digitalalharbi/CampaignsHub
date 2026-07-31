<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What CampaignsHub billed a customer, and what they paid for it (SUBINV-001).
 *
 * Deliberately NOT the existing `invoices` table. That one is a TENANT's document to its own client —
 * an agency invoicing a brand — and this one is ours to the tenant. They differ in almost every way
 * that matters: whose tax number appears on it, whose currency governs, who may read it, and which
 * revenue figure it belongs to. One table for both would make "revenue" a number that answers neither
 * question, and would put an agency's client invoices and its own subscription bills behind the same
 * permission.
 *
 * A subscription invoice is ISSUED when a charge is opened and SETTLED when the gateway confirms it.
 * It is never created retrospectively from a payment: a customer is entitled to the document that
 * says what they were asked for, whether or not they paid it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_invoices', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            /*
             * A human-readable number, unique for the life of the install.
             *
             * Sequential per year, because "invoice 7" is what a customer quotes back and what an
             * accountant reconciles against. A uuid in that position is unusable by either.
             */
            $table->string('number', 32)->unique();

            // Who it is for. `tenant_id` is null only for the very first document a customer ever
            // gets — the trial fee, billed before their workspace exists.
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->foreignUuid('registration_request_id')->nullable()
                ->constrained('registration_requests')->nullOnDelete();
            $table->foreignUuid('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->foreignUuid('subscription_payment_id')->nullable()
                ->constrained('subscription_payments')->nullOnDelete();

            // Who it was billed TO, captured at issue. A customer who later renames their company is
            // still owed the document that says what it said when they were charged.
            $table->string('bill_to_name');
            $table->string('bill_to_email');
            $table->string('bill_to_tax_number', 64)->nullable();

            $table->string('currency', 3);

            /*
             * Money, all of it stored rather than derived.
             *
             * Recomputing a total from a rate at read time means a VAT change silently rewrites every
             * historical invoice — which is both wrong and, for a tax document, the kind of wrong that
             * has consequences.
             */
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount_total', 15, 2)->default(0);
            $table->decimal('tax_total', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->decimal('amount_paid', 15, 2)->default(0);

            // The treatment, not just the rate: `basic_15`, `zero_rated`, `exempt`, `out_of_scope`
            // are different statements to a tax authority even where two of them compute to zero.
            $table->string('tax_treatment', 32)->default('basic_15');
            $table->decimal('tax_rate', 5, 4)->default(0);

            // issued | paid | refunded | void
            $table->string('status', 24)->default('issued');

            $table->timestampTz('issued_at');
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('refunded_at')->nullable();
            $table->timestampTz('voided_at')->nullable();
            $table->text('void_reason')->nullable();

            /*
             * A share token, so an invoice can be sent to somebody's accountant.
             *
             * Nullable because sharing is a decision: a document is not publicly reachable until
             * somebody chooses to make it so, and revoking is setting this back to null.
             */
            $table->string('share_token', 64)->nullable()->unique();
            $table->timestampTz('shared_at')->nullable();

            $table->timestampsTz();

            $table->index(['tenant_id', 'status']);
            $table->index('issued_at');
        });

        /*
         * The lines. Separate rows rather than a JSON blob, because an invoice is read line by line
         * by people reconciling it — and because a discount that cannot be attributed to a line is a
         * discount nobody can explain.
         */
        Schema::create('subscription_invoice_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('subscription_invoice_id')
                ->constrained('subscription_invoices')->cascadeOnDelete();

            $table->string('description');
            $table->string('plan_code', 64)->nullable();
            $table->string('period_label', 64)->nullable();

            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2);

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestampsTz();
        });

        // "Give me this year's invoices in order" is the query the finance view is made of.
        DB::statement('CREATE INDEX subscription_invoices_number_index ON subscription_invoices (number)');
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_invoice_lines');
        Schema::dropIfExists('subscription_invoices');
    }
};
