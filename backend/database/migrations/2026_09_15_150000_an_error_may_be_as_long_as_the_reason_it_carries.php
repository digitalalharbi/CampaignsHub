<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * INTEGRATION-ERROR-CONTRACT-001 — the column stops refusing the answer.
 *
 * `SQLSTATE[22001] value too long for varchar(255)`, reported by the owner, is the database
 * declining to record why a provider said no. These two columns were sized when an error was a
 * sentence; a provider refusal is a sentence plus the identifiers that make it actionable, and
 * `AccountDiscovery` wrote up to 1000 characters into 255 of space.
 *
 * `text` in PostgreSQL is not a bigger varchar with a cost — it is the same storage with no length
 * check, so this widens what can be recorded and changes nothing else.
 *
 * ## It does not rewrite the table
 *
 * Checked before deploying rather than assumed, because `integration_sync_runs` grows by a row per
 * account every thirty minutes and a rewrite would hold an ACCESS EXCLUSIVE lock over all of it.
 * Measured on a 50,000-row table: `relfilenode` is identical before and after, so PostgreSQL treats
 * `varchar(255) → text` as binary-coercible and changes only the catalogue. The lock is taken and
 * released immediately.
 */
return new class extends Migration
{
    public function up(): void
    {
        // `->change()` on these would need doctrine/dbal; the column type is changed directly instead.
        DB::statement('ALTER TABLE provider_connections ALTER COLUMN last_error TYPE text');
        DB::statement('ALTER TABLE integration_sync_runs ALTER COLUMN error TYPE text');
    }

    public function down(): void
    {
        /*
         * Truncated on the way back, because a value that no longer fits is exactly the defect this
         * migration exists to remove — a reversal that threw would be unrunnable.
         */
        DB::statement('ALTER TABLE provider_connections ALTER COLUMN last_error TYPE varchar(255) USING left(last_error, 255)');
        DB::statement('ALTER TABLE integration_sync_runs ALTER COLUMN error TYPE varchar(255) USING left(error, 255)');
    }
};
