<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record the VAT treatment on quotes and invoices so the tax amount is derived from an explicit, auditable
 * choice — not a free-typed number — and can be shown on cards and detail pages. Values:
 *   basic_15 | zero_rated | exempt | out_of_scope | historical_5 (legacy 5%, kept only for old records).
 * Nullable: pre-existing rows keep no treatment ("unspecified") rather than being mislabeled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            $table->string('tax_treatment')->nullable()->after('tax');
        });
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('tax_treatment')->nullable()->after('tax');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', fn (Blueprint $table) => $table->dropColumn('tax_treatment'));
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn('tax_treatment'));
    }
};
