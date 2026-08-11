<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * COMMERCE-TZ-001 — a merchant's day is not a UTC day.
 *
 * ## What was stored, and why nobody noticed
 *
 * `SallaConnector::date()` was handed `{ date: "2026-08-05 01:30:00", timezone: "Asia/Riyadh" }` and
 * kept only the string. `Carbon::parse()` then read that wall clock in the application's own
 * timezone, so `placed_at` held **01:30 UTC** for a sale made at 01:30 in Riyadh: the merchant's
 * clock wearing a UTC label, three hours adrift as an instant.
 *
 * It survived because it was wrong consistently. Report windows were built the same way —
 * `startOfDay()` on a UTC Carbon — so a query for «5 August» matched precisely the rows the merchant
 * would call 5 August. Two errors cancelling, and the only visible symptom was a rendered TIME being
 * off by the store's offset for any reader outside it.
 *
 * ## Why this migration corrects the data rather than only adding columns
 *
 * Leaving the old rows as wall clocks and writing true instants for new ones would put two different
 * meanings in one column, and no reader could tell which it had. Every existing `placed_at` is
 * therefore re-interpreted in the zone it was always in.
 *
 * The re-interpretation is exact and reversible: the stored value IS the merchant's wall clock, so
 * reading it back out as a naive timestamp and re-anchoring it in the store's zone recovers the
 * instant that was thrown away. Nothing is estimated.
 *
 * ## `placed_on` — the merchant's own calendar date, computed once
 *
 * A merchant-day total must not be re-derived from an instant at read time; that would make the day a
 * property of whoever is looking. It is settled at ingest, from the merchant's clock, and stored. For
 * these backfilled rows it is simply the date the wall clock already carried — which is why the
 * backfill can be certain about it even where it has to guess the zone.
 *
 * ## The zone chain, and the one case that is a guess
 *
 * The store's own timezone, then the client workspace's, then UTC. The last is a guess and is written
 * down as `assumed_utc` rather than blended in with the others — an assumption a reader cannot see is
 * the defect this unit exists to close. The rows it applies to keep their timestamps unchanged, which
 * is the honest outcome: we do not know that they are wrong, only that we cannot prove them right.
 */
return new class extends Migration
{
    /** project id → the client's timezone, for stores that never reported one. */
    private const WORKSPACE_TZ = <<<'SQL'
        SELECT p.id AS project_id, NULLIF(w.timezone, '') AS tz
        FROM projects p
        LEFT JOIN client_workspaces w ON w.id = p.client_workspace_id
    SQL;

    public function up(): void
    {
        Schema::table('commerce_orders', function (Blueprint $table): void {
            // The zone the instant was resolved in — never inferred at read time.
            $table->string('placed_at_timezone', 64)->nullable()->after('placed_at');
            // The merchant's own calendar date. Indexed: it is what a merchant-day total groups by.
            $table->date('placed_on')->nullable()->after('placed_at_timezone');
            // payload | store | workspace | assumed_utc — where the zone came from.
            $table->string('time_source', 16)->nullable()->after('placed_on');
            $table->index(['project_id', 'placed_on']);
        });

        Schema::table('commerce_abandoned_carts', function (Blueprint $table): void {
            $table->string('abandoned_at_timezone', 64)->nullable()->after('abandoned_at');
            $table->date('abandoned_on')->nullable()->after('abandoned_at_timezone');
            $table->string('time_source', 16)->nullable()->after('abandoned_on');
            $table->index(['project_id', 'abandoned_on']);
        });

        $this->backfill('commerce_orders', 'placed_at', 'placed_at_timezone', 'placed_on');
        $this->backfill('commerce_abandoned_carts', 'abandoned_at', 'abandoned_at_timezone', 'abandoned_on');
    }

    /**
     * Re-anchor every stored wall clock in the zone it was always being read in.
     *
     * `AT TIME ZONE 'UTC'` strips the label back off, recovering the naive merchant clock the
     * connector wrote; the second `AT TIME ZONE` re-anchors it in the store's real zone. Postgres
     * applies that zone's rules AT THAT DATE, so a summer order and a winter order in the same shop
     * are each shifted by the offset actually in force — which a fixed offset would get wrong for
     * half the year.
     */
    private function backfill(string $table, string $at, string $tzColumn, string $onColumn): void
    {
        // The date is the merchant's whatever the zone turns out to be: it is on their own clock.
        DB::statement("UPDATE {$table} SET {$onColumn} = ({$at} AT TIME ZONE 'UTC')::date WHERE {$at} IS NOT NULL");

        // 1. The store told us its zone. Exact.
        DB::statement("
            UPDATE {$table} AS t
            SET {$at} = (t.{$at} AT TIME ZONE 'UTC') AT TIME ZONE a.timezone,
                {$tzColumn} = a.timezone,
                time_source = 'store'
            FROM external_accounts AS a
            WHERE a.id = t.external_account_id
              AND t.{$at} IS NOT NULL
              AND NULLIF(a.timezone, '') IS NOT NULL
        ");

        // 2. It did not, but the client this project reports to has one.
        DB::statement("
            UPDATE {$table} AS t
            SET {$at} = (t.{$at} AT TIME ZONE 'UTC') AT TIME ZONE w.tz,
                {$tzColumn} = w.tz,
                time_source = 'workspace'
            FROM (".self::WORKSPACE_TZ.") AS w
            WHERE w.project_id = t.project_id
              AND t.{$at} IS NOT NULL
              AND t.time_source IS NULL
              AND w.tz IS NOT NULL
        ");

        /*
         * 3. Nobody states a zone. The timestamp is LEFT ALONE and the guess is recorded.
         *
         * Shifting it by some default would be inventing a fact; dropping the row would lose a real
         * sale from every total. Saying «we assumed UTC» is the only one of the three a reader can
         * act on.
         */
        DB::statement("
            UPDATE {$table}
            SET {$tzColumn} = 'UTC', time_source = 'assumed_utc'
            WHERE {$at} IS NOT NULL AND time_source IS NULL
        ");
    }

    public function down(): void
    {
        // Put the wall clocks back, so a rollback leaves the column meaning what it used to.
        foreach ([['commerce_orders', 'placed_at'], ['commerce_abandoned_carts', 'abandoned_at']] as [$table, $at]) {
            DB::statement("
                UPDATE {$table}
                SET {$at} = ({$at} AT TIME ZONE COALESCE(NULLIF(".($at === 'placed_at' ? 'placed_at_timezone' : 'abandoned_at_timezone').", ''), 'UTC')) AT TIME ZONE 'UTC'
                WHERE {$at} IS NOT NULL AND time_source IN ('payload', 'store', 'workspace')
            ");
        }

        Schema::table('commerce_orders', function (Blueprint $table): void {
            $table->dropIndex(['project_id', 'placed_on']);
            $table->dropColumn(['placed_at_timezone', 'placed_on', 'time_source']);
        });

        Schema::table('commerce_abandoned_carts', function (Blueprint $table): void {
            $table->dropIndex(['project_id', 'abandoned_on']);
            $table->dropColumn(['abandoned_at_timezone', 'abandoned_on', 'time_source']);
        });
    }
};
