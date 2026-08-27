<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MONEY-USD-001 — `reports.currency` defaulted to SAR, which is now the wrong label.
 *
 * The column does not convert anything. Every figure a report carries arrives from the aggregator
 * already normalised, and this column only says which currency the reader is looking at. With the
 * aggregation basis fixed to USD, a row left at the old default prints dollar figures under «SAR».
 *
 * ## Existing rows are deliberately left alone
 *
 * Reports generated before the basis moved really were built from SAR-normalised metrics, so their
 * stored figures ARE riyals and their label is honest. Rewriting it to USD would relabel a correct
 * number as the wrong currency — the exact defect this change exists to remove, applied backwards.
 * They are re-stated only when their underlying metrics are, by `metrics:renormalise-currency`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->string('currency', 3)->default('USD')->change();
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->string('currency', 3)->default('SAR')->change();
        });
    }
};
