<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BUDGET-FX-DEFAULT-001 — a workspace's own default exchange rate. ADDITIVE ONLY: one new table, nothing rewritten.
 *
 * A row says «in this workspace, 1 USD = N SAR when no dated rate exists». It is a fallback behind the dated
 * `currency_rates` and never replaces one. A workspace with no row uses the SAR peg (3.75), stated in the UI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_fx_defaults', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('base_currency', 3);
            $table->string('quote_currency', 3);
            $table->decimal('rate', 18, 8);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'base_currency', 'quote_currency'], 'workspace_fx_defaults_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_fx_defaults');
    }
};
