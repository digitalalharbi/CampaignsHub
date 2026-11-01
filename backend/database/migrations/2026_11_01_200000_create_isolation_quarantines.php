<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ACCOUNT-SCOPE-ISOLATION-002 — where a row goes when it is taken OUT of a project without being lost.
 *
 * The incident of 2026-11-01 left another advertiser's campaigns and figures filed under an account
 * row they never belonged to. Those rows must stop being read by that project — the reports, the
 * board, the exports all scope by it — and they must not be destroyed: they are the evidence of what
 * happened, and the only way to put them back if a classification turns out wrong.
 *
 * So a quarantine is a copy and a removal, in one transaction, under one batch id. Every live row
 * `integrations:account-ownership-audit --apply` removes is written here first, whole, as the row was
 * (`payload`), with the table and the id it came from. `--restore=<batch>` puts the batch back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('isolation_quarantines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('batch')->index();
            $table->string('reason');
            $table->string('table_name');
            $table->uuid('row_id');
            $table->jsonb('payload');
            $table->timestampTz('created_at');
            $table->timestampTz('restored_at')->nullable();

            $table->index(['table_name', 'row_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('isolation_quarantines');
    }
};
