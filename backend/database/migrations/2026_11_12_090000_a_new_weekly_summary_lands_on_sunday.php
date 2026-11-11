<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DIGESTS-CADENCE-BUDGETS-001 — a NEW notification row's weekly summary lands on Sunday (ISO 7), not Monday.
 *
 * The working week here runs Sunday to Thursday and the Owner's weekly is «Sunday 08:00 Riyadh». Only the column DEFAULT
 * changes: no stored row is rewritten, because a stored 1 cannot tell «chose Monday» from «never chose», and moving
 * somebody's chosen day without asking is not this migration's decision. Anybody can pick Sunday on the settings screen.
 *
 * Raw SQL rather than `->change()`, so it needs no doctrine/dbal and touches nothing but the default.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE notification_preferences ALTER COLUMN digest_weekday SET DEFAULT 7');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE notification_preferences ALTER COLUMN digest_weekday SET DEFAULT 1');
    }
};
