<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use JsonException;

/**
 * PERF-RECOVERY-001 — the rows of an upsert the table already holds, so they need not be written.
 *
 * A half-hourly sweep re-asks three days, and almost all of what comes back is what is stored. Sent
 * through `INSERT … ON CONFLICT DO UPDATE`, every one of those rows was written again: a new row
 * version, a new entry in each of the table's nine to twelve indexes, a dead row for autovacuum, and
 * the write-ahead log for all of it. Measured on a world of Production's size, a sweep in which the
 * provider changed nothing rewrote 42,560 rows and produced 55 MB of log.
 *
 * So the question «is this row already there?» is asked first, and asked of PostgreSQL rather than
 * answered in PHP. `json_populate_record(null::<table>, …)` turns each offered row into the table's
 * own row type, with the same input conversion and rounding an INSERT applies, and the comparison is
 * `IS NOT DISTINCT FROM` on the columns the upsert would update. A row is dropped only when every
 * one of them is equal; a row with no stored counterpart, or with any difference, is written by the
 * same upsert as before, unchanged.
 *
 * What is NOT done is `ON CONFLICT … DO UPDATE … WHERE … IS DISTINCT FROM`: that form still locks
 * every conflicting row, which is a write to its page and a log record per row.
 *
 * Numbers are offered as the text PDO would bind (`(string) $float`), so the comparison sees exactly
 * the value the upsert would store. A row carrying a value this cannot offer faithfully (a JSON body,
 * an array) is never dropped.
 */
final class UnchangedRows
{
    /**
     * The rows of `$rows` that are new or differ from what is stored, in their original order.
     *
     * @param  list<array<string,mixed>>  $rows  all with the same columns
     * @param  list<string>  $uniqueBy  the upsert's conflict target
     * @param  list<string>  $compare  the columns whose equality makes a row «already there»
     * @return list<array<string,mixed>>
     */
    public static function drop(string $table, array $rows, array $uniqueBy, array $compare): array
    {
        if ($rows === [] || $compare === [] || $uniqueBy === []
            || ! (bool) config('integrations.sync.skip_unchanged_rows', true)
            || DB::connection()->getDriverName() !== 'pgsql') {
            return $rows;
        }

        $grammar = DB::connection()->getQueryGrammar();
        $columns = array_values(array_unique([...$uniqueBy, ...$compare]));
        $offered = [];

        foreach ($rows as $index => $row) {
            $record = self::offer($row, $columns, $grammar->getDateFormat());

            if ($record !== null) {
                $offered[] = ['i' => $index, 'r' => $record];
            }
        }

        if ($offered === []) {
            return $rows;
        }

        $wrapped = $grammar->wrapTable($table);
        $on = implode(' and ', array_map(static fn (string $c): string => 't.'.$grammar->wrap($c).' = v.'.$grammar->wrap($c), $uniqueBy));
        $stored = implode(', ', array_map(static fn (string $c): string => 't.'.$grammar->wrap($c), $compare));
        $sent = implode(', ', array_map(static fn (string $c): string => 'v.'.$grammar->wrap($c), $compare));

        try {
            $same = DB::select(
                "select (e.j->>'i')::int as i
                   from json_array_elements(?::json) as e(j)
                  cross join lateral json_populate_record(null::{$wrapped}, e.j->'r') as v
                   join {$wrapped} as t on {$on}
                  where row({$stored}) is not distinct from row({$sent})",
                [json_encode($offered, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)],
            );
        } catch (JsonException) {
            // A value JSON cannot carry: nothing was sent, so nothing is known to be unchanged.
            return $rows;
        } catch (QueryException $e) {
            // Inside a transaction the failed statement has already aborted it; outside one, write everything as before.
            if (DB::transactionLevel() > 0) {
                throw $e;
            }
            report($e);

            return $rows;
        }

        if ($same === []) {
            return $rows;
        }

        foreach ($same as $hit) {
            unset($rows[(int) $hit->i]);
        }

        return array_values($rows);
    }

    /**
     * One row as PostgreSQL would be sent it, or null when a value cannot be offered faithfully.
     *
     * @param  array<string,mixed>  $row
     * @param  list<string>  $columns
     * @param  string  $dateFormat  the grammar's, which is how the upsert binds a date
     * @return array<string,string|int|bool|null>|null
     */
    private static function offer(array $row, array $columns, string $dateFormat): ?array
    {
        $record = [];

        foreach ($columns as $column) {
            if (! array_key_exists($column, $row)) {
                return null;
            }

            $value = $row[$column];

            // A string in a JSON column is a body, not a scalar: it is written, never compared.
            if (is_string($value) && in_array($column, self::BODIES, true)) {
                return null;
            }

            if ($value === null || is_int($value) || is_bool($value) || is_string($value)) {
                $record[$column] = $value;
            } elseif (is_float($value)) {
                // The text PDO binds for a float; `json_encode` would print more digits than the upsert sends.
                $record[$column] = (string) $value;
            } elseif ($value instanceof DateTimeInterface) {
                $record[$column] = $value->format($dateFormat);
            } else {
                return null;
            }
        }

        return $record;
    }

    /** Columns that hold a JSON body as a string. */
    private const BODIES = ['raw', 'meta', 'payload'];
}
