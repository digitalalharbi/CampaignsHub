<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * C3.1 — Daily FX rates (global). Spend/revenue are stored in their original currency AND converted
 * to the project currency using the rate for the metric date, so historical conversions stay stable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currency_rates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->char('base_currency', 3);
            $table->char('quote_currency', 3);
            $table->decimal('rate', 24, 12);
            $table->date('rate_date');
            $table->string('source')->default('manual'); // ecb | openexchange | manual | sandbox
            $table->timestampsTz();

            $table->unique(['base_currency', 'quote_currency', 'rate_date'], 'currency_rates_natural_key');
            $table->index(['base_currency', 'quote_currency', 'rate_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currency_rates');
    }
};
