<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * META-SYNC-UPSERT-001 — a bulk upsert whose rows do not all carry the same columns.
 *
 * `INSERT … VALUES` needs every row to be the same length. The metric writers build each row from
 * only the measures a platform actually reported — absent stays absent, never a zero — so one Meta
 * ad that sent a purchase and the next that sent none are different lengths, and Postgres refuses
 * the whole statement («VALUES lists must all be the same length»). That exception escaped the
 * syncer's own try, so every HENKA Meta run stayed «running» with nothing stored, three per sweep.
 *
 * Padding the short rows with null would repair the length and corrupt the data: on update, a null
 * overwrites the figure the previous sync stored. So rows are GROUPED by their column set and each
 * group is upserted on its own, updating only the columns that group actually carries.
 */
final class GroupedUpsert
{
    /**
     * @param  list<array<string,mixed>>  $rows
     * @param  list<string>  $uniqueBy
     * @param  list<string>  $updateColumns  the columns a re-sync may replace, when the row carries them
     */
    public static function run(string $table, array $rows, array $uniqueBy, array $updateColumns, int $chunk = 500): void
    {
        $groups = [];

        foreach ($rows as $row) {
            $columns = array_keys($row);
            sort($columns);
            $groups[implode(',', $columns)][] = $row;
        }

        foreach ($groups as $group) {
            $present = array_keys($group[0]);
            $update = array_values(array_intersect($updateColumns, $present));

            foreach (array_chunk($group, $chunk) as $batch) {
                DB::table($table)->upsert($batch, $uniqueBy, $update);
            }
        }
    }
}
