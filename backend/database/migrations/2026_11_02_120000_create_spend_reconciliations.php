<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SPEND-RECONCILE-RECORD-001 — «آخر مطابقة مؤكدة مع المصدر», kept apart from «آخر مزامنة ناجحة».
 *
 * A successful sync says we asked and the platform answered. A reconciliation says we asked the
 * platform for the account's spend over a window and set it beside what we stored, and the two
 * agreed (or did not). They are different promises, and a report that stamps the first must not be
 * read as the second — so the second gets its own row, written ONLY by
 * `integrations:spend-reconcile --record`.
 *
 * Totals only: no campaign names, no account names, no tokens. `run_ref` is whatever the operator
 * passed to tie the row to a CI run or a ticket; it is free text and never a secret.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spend_reconciliations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            // Null when the account was bound to no project at the time — the row is then nobody's to show.
            $table->uuid('project_id')->nullable();
            $table->uuid('external_account_id');
            $table->string('provider', 32);
            $table->date('window_from');
            $table->date('window_to');
            // Null when the provider could not be asked (`status = failed`).
            $table->decimal('provider_total', 24, 6)->nullable();
            $table->decimal('stored_total', 24, 6);
            $table->string('currency', 8)->nullable();
            $table->decimal('diff', 24, 6)->nullable();
            $table->decimal('diff_pct', 12, 4)->nullable();
            $table->string('status', 16); // matched | drifted | failed
            $table->timestampTz('checked_at');
            $table->string('run_ref', 120)->nullable();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['project_id', 'provider', 'checked_at']);
            $table->index(['external_account_id', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spend_reconciliations');
    }
};
