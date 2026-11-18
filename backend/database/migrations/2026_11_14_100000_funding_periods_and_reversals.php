<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BUDGET-PERIOD-001 / BUDGET-LEDGER-001 — a budget has a period, and every correction is an entry.
 *
 *  - `project_funding.ends_on`   — the last day whose spend counts (null = open until renewed or closed).
 *    `starts_on` already existed and is the period's first day; null now means «the first day of real spend».
 *  - `project_funding.voided_*`  — a deleted budget is hidden, never removed; who, when and why are kept.
 *  - `funding_payments.seq`      — the order entries were written in. `created_at` has second precision, and
 *    «the latest entry» (the only one that can be undone) must not depend on a tie.
 *  - `funding_payments.reverses_id / reason / meta` — a reversal names the entry it cancels; `meta` carries the
 *    period before and after an edit so undoing it can put the period back.
 *
 * Nothing in `funding_payments` is edited or deleted: the model still refuses both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_funding', function (Blueprint $table): void {
            $table->date('ends_on')->nullable()->after('starts_on');
            $table->timestampTz('voided_at')->nullable();
            $table->string('void_reason', 300)->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('funding_payments', function (Blueprint $table): void {
            $table->uuid('reverses_id')->nullable()->index();
            $table->string('reason', 300)->nullable();
            $table->jsonb('meta')->nullable();
        });
        DB::statement('ALTER TABLE funding_payments ADD COLUMN seq BIGSERIAL');
        DB::statement('CREATE UNIQUE INDEX funding_payments_seq_unique ON funding_payments (seq)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS funding_payments_seq_unique');
        Schema::table('funding_payments', function (Blueprint $table): void {
            $table->dropColumn(['seq', 'reverses_id', 'reason', 'meta']);
        });
        Schema::table('project_funding', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn(['ends_on', 'voided_at', 'void_reason']);
        });
    }
};
