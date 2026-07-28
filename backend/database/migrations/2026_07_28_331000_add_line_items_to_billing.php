<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive `line_items` jsonb on quotes and invoices. Each line item is { key, label, label_ar, amount } — the
 * `key` is a stable `request.paid_service` option key so a request's selected services carry into the quote and
 * (on approval) the invoice, priceable later. Nullable: existing rows keep no line items and are untouched. The
 * subtotal/tax/discount/total money columns are deliberately left as the source of truth for amounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            if (! Schema::hasColumn('quotes', 'line_items')) {
                $table->jsonb('line_items')->nullable()->after('total');
            }
            // external_requests uses ULID ids; the existing `request_id` column is uuid-typed, so a request
            // built from an external request is linked via this additive nullable ULID column instead.
            if (! Schema::hasColumn('quotes', 'external_request_id')) {
                $table->ulid('external_request_id')->nullable()->index()->after('request_id');
            }
        });

        Schema::table('invoices', function (Blueprint $table): void {
            if (! Schema::hasColumn('invoices', 'line_items')) {
                $table->jsonb('line_items')->nullable()->after('total');
            }
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            foreach (['line_items', 'external_request_id'] as $column) {
                if (Schema::hasColumn('quotes', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('invoices', function (Blueprint $table): void {
            if (Schema::hasColumn('invoices', 'line_items')) {
                $table->dropColumn('line_items');
            }
        });
    }
};
