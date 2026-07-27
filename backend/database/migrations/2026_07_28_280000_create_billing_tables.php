<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing / payments storage. The domain is honest by construction: a payment is only ever marked paid by a
 * verified webhook, and idempotency is enforced at the storage layer.
 *
 *   - quotes                 : a priced offer to a client (draft → sent → approved → invoice). Tenant-scoped.
 *   - invoices               : an issued bill; amount_paid tracks partial settlement. Tenant-scoped.
 *   - payments               : one attempt to collect an invoice via a provider. idempotency_key is unique so
 *                              a retried startPayment never double-creates. Never paid without a verified hook.
 *   - payment_attempts       : an append-only audit of every provider round-trip for a payment.
 *   - payment_webhook_events : the received-webhook ledger; event_id is unique so a re-delivered event is a
 *                              no-op (idempotent). verified gates any money-moving state transition.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('tenant_id')->index();
            $table->uuid('client_workspace_id')->nullable()->index();
            $table->uuid('request_id')->nullable()->index();
            $table->uuid('project_id')->nullable()->index();
            $table->string('number');
            $table->string('currency', 3)->default('SAR');
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('tax', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->string('status')->default('draft'); // draft|sent|approved|rejected|expired
            $table->date('valid_until')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('tenant_id')->index();
            $table->uuid('client_workspace_id')->index();
            $table->unsignedBigInteger('quote_id')->nullable();
            $table->string('number');
            $table->string('currency', 3)->default('SAR');
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('tax', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->decimal('amount_paid', 15, 2)->default(0);
            $table->string('status')->default('draft'); // draft|issued|paid|partially_paid|void|refunded
            $table->date('due_date')->nullable();
            $table->timestampTz('issued_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('quote_id')->references('id')->on('quotes')->nullOnDelete();
            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->unsignedBigInteger('invoice_id');
            $table->string('provider');
            $table->string('provider_session_id')->nullable()->index();
            $table->string('provider_payment_id')->nullable()->index();
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('SAR');
            $table->string('status')->default('pending'); // pending|processing|paid|failed|refunded
            $table->string('idempotency_key')->unique();
            $table->text('error')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('invoice_id')->references('id')->on('invoices')->cascadeOnDelete();
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('tenant_id')->index();
            $table->uuid('payment_id');
            $table->string('status');
            $table->jsonb('provider_response')->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('payment_id')->references('id')->on('payments')->cascadeOnDelete();
            $table->index(['tenant_id', 'payment_id']);
        });

        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->string('provider');
            $table->string('event_id')->unique();
            $table->string('type')->nullable();
            $table->boolean('verified')->default(false);
            $table->jsonb('payload')->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->nullOnDelete();
            $table->index(['provider', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
        Schema::dropIfExists('payment_attempts');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('quotes');
    }
};
