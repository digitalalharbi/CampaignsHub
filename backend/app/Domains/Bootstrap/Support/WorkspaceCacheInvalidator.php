<?php

declare(strict_types=1);

namespace App\Domains\Bootstrap\Support;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * PERF-BOOTSTRAP-001 — bumps a {@see WorkspaceReadCache} section's epoch when a table it is derived from is written.
 *
 * It reads the SQL of every statement the application runs, which is the one place that sees EVERY write path: a branding save, a
 * plan change by the console, a webhook activating a subscription, a role's permissions `sync()`, an access-center override, a
 * client-scope change, an invitation accepted, a seeder, a queue worker. Hooking the writers one by one is how a stale permission
 * document survives the one path somebody forgot.
 *
 * Only a statement that STARTS as INSERT / UPDATE / DELETE / TRUNCATE on a watched table counts, so the cost on a read is one
 * character comparison.
 *
 * Inside a transaction the epoch is bumped twice: now, and again after COMMIT. The second one closes the window where another
 * request recomputes a section from the not-yet-committed state under the new epoch and files the old answer for ten minutes.
 */
final class WorkspaceCacheInvalidator
{
    /** @var array<string, list<string>>|null table => sections */
    private static ?array $sectionsOfTable = null;

    private static bool $busy = false;

    public static function listen(): void
    {
        DB::listen(static function (QueryExecuted $query): void {
            self::inspect($query->sql);
        });
    }

    public static function inspect(string $sql): void
    {
        $first = $sql[0] ?? '';
        // i/I(nsert) u/U(pdate) d/D(elete) t/T(runcate); a leading space or comment is not how the framework writes them.
        if ($first !== 'i' && $first !== 'u' && $first !== 'd' && $first !== 'I' && $first !== 'U' && $first !== 'D' && $first !== 't' && $first !== 'T') {
            return;
        }
        if (self::$busy) {
            return;
        }
        if (preg_match('/^(?:insert\s+into|update|delete\s+from|truncate(?:\s+table)?)\s+(?:only\s+)?(?:"?[a-z0-9_]+"?\.)?"?([a-z0-9_]+)"?/i', $sql, $m) !== 1) {
            return;
        }

        $sections = (self::$sectionsOfTable ??= self::index())[strtolower($m[1])] ?? null;
        if ($sections === null) {
            return;
        }

        // A cache store that is itself the database would write through this listener: do not recurse.
        self::$busy = true;
        try {
            self::bump($sections);
            if (DB::transactionLevel() > 0) {
                DB::afterCommit(static fn () => self::bump($sections));
            }
        } finally {
            self::$busy = false;
        }
    }

    /** @param list<string> $sections */
    private static function bump(array $sections): void
    {
        foreach ($sections as $section) {
            WorkspaceReadCache::bump($section);
        }
    }

    /** @return array<string, list<string>> */
    private static function index(): array
    {
        $index = [];
        foreach (WorkspaceReadCache::WATCHED as $section => $tables) {
            foreach ($tables as $table) {
                $index[$table][] = $section;
            }
        }

        return $index;
    }
}
