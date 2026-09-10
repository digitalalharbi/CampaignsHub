<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AUTOMATION-FIRST-OPERATIONS-001 — the ledger records how much a run actually did.
 *
 * Nullable on purpose, and it is the point rather than a default: «swept and deleted nothing» and
 * «this command does not count what it does» are different facts about a night's run, and a column
 * defaulting to 0 would render both as a sweep that found nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduled_runs', function (Blueprint $table): void {
            $table->unsignedInteger('rows_affected')->nullable()->after('exit_code');
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_runs', function (Blueprint $table): void {
            $table->dropColumn('rows_affected');
        });
    }
};
