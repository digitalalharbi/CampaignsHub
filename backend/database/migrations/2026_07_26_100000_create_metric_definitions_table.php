<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * C3.1 — Metric catalogue (global, not tenant-scoped). Defines each metric the platform can store and
 * how it aggregates, so daily_metrics rows are self-describing and aggregation stays consistent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metric_definitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key')->unique();            // impressions | clicks | spend | conversions | revenue | ...
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('unit');                     // count | currency | rate | ratio | duration
            $table->string('value_type')->default('decimal'); // integer | decimal
            $table->string('default_aggregation')->default('sum'); // sum | avg | max | last
            $table->boolean('is_currency')->default(false);     // monetary → needs currency normalization
            $table->boolean('is_additive')->default(true);      // false for rates/ratios (cannot be summed)
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metric_definitions');
    }
};
