<?php

declare(strict_types=1);

namespace App\Domains\Ops\Console;

use App\Domains\Ops\Performance\PerformanceRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * PERF-RECOVERY-001 — Production's load, read in one place. Writes nothing and calls no provider.
 *
 * Prints route patterns, statements with their placeholders, table names and counts. A running
 * query's text is shown with its literals stripped, because `pg_stat_activity` holds real values.
 */
final class PerfSnapshotCommand extends Command
{
    protected $signature = 'ops:perf-snapshot {--hours=24 : Hours of request and query counters to read}';

    protected $description = 'Read-only: slowest routes and queries, Postgres, Redis, queues and sync load.';

    public function handle(PerformanceRecorder $recorder): int
    {
        $hours = max(1, (int) $this->option('hours'));

        $this->heading('RUNTIME');
        $this->line('  config cached: '.(app()->configurationIsCached() ? 'yes' : 'NO')
            .' · routes cached: '.(app()->routesAreCached() ? 'yes' : 'NO')
            .' · events cached: '.(app()->eventsAreCached() ? 'yes' : 'NO')
            .' · debug: '.(config('app.debug') ? 'ON' : 'off')
            .' · cache: '.config('cache.default').' · session: '.config('session.driver').' · queue: '.config('queue.default'));
        $this->line('  php '.PHP_VERSION.' · memory_limit '.ini_get('memory_limit').' · opcache extension '.(extension_loaded('Zend OPcache') ? 'loaded' : 'MISSING'));

        $this->routes($recorder, $hours);
        $this->queries($recorder, $hours);
        $this->postgres();
        $this->redis();
        $this->work();

        return self::SUCCESS;
    }

    private function routes(PerformanceRecorder $recorder, int $hours): void
    {
        $this->heading("SLOWEST ROUTES, last {$hours}h (percentiles are bucket upper bounds)");
        $routes = $recorder->requests($hours);

        if ($routes === []) {
            $this->line('  no counters yet');

            return;
        }

        uasort($routes, static fn (array $a, array $b): int => $b['ms'] <=> $a['ms']);
        $this->line(sprintf('  %-8s %-8s %-8s %-8s %-9s %-9s %s', 'count', 'avg ms', 'p50<=', 'p95<=', 'avg sql#', 'sql ms', 'route'));

        foreach (array_slice($routes, 0, 40, true) as $route => $r) {
            $count = max(1, $r['count']);
            $this->line(sprintf('  %-8d %-8d %-8s %-8s %-9d %-9d %s',
                $r['count'], (int) ($r['ms'] / $count),
                PerformanceRecorder::percentile($r['buckets'], 0.5) ?? '—',
                PerformanceRecorder::percentile($r['buckets'], 0.95) ?? '—',
                (int) ($r['queries'] / $count), (int) ($r['query_ms'] / $count), $route));
        }
    }

    private function queries(PerformanceRecorder $recorder, int $hours): void
    {
        $this->heading('SLOWEST STATEMENTS (each at least '.PerformanceRecorder::SLOW_QUERY_MS.' ms), by total time');
        $slow = $recorder->slowQueries($hours);

        if ($slow === []) {
            $this->line('  none recorded');

            return;
        }

        uasort($slow, static fn (array $a, array $b): int => $b['ms'] <=> $a['ms']);

        foreach (array_slice($slow, 0, 20, true) as $q) {
            $this->line(sprintf('  count %-6d total %-9d avg %-7d %-4s %s', $q['count'], $q['ms'], (int) ($q['ms'] / max(1, $q['count'])), $q['ctx'], $q['sql']));
        }
    }

    private function postgres(): void
    {
        $this->heading('POSTGRES');
        $this->attempt(function (): void {
            $this->line('  max_connections '.DB::selectOne('SHOW max_connections')->max_connections
                .' · shared_buffers '.DB::selectOne('SHOW shared_buffers')->shared_buffers
                .' · database size '.DB::selectOne('SELECT pg_size_pretty(pg_database_size(current_database())) AS s')->s);

            foreach (DB::select("SELECT COALESCE(state, 'background') AS state, COUNT(*) AS n FROM pg_stat_activity WHERE datname = current_database() GROUP BY 1 ORDER BY 2 DESC") as $row) {
                $this->line("  connections {$row->state}: {$row->n}");
            }

            $hit = DB::selectOne('SELECT ROUND(100.0 * SUM(blks_hit) / NULLIF(SUM(blks_hit) + SUM(blks_read), 0), 2) AS pct FROM pg_stat_database WHERE datname = current_database()');
            $this->line('  cache hit: '.($hit->pct ?? '—').'%');

            foreach (DB::select("SELECT ROUND(EXTRACT(EPOCH FROM now() - query_start)) AS secs, state, query FROM pg_stat_activity WHERE datname = current_database() AND state <> 'idle' AND pid <> pg_backend_pid() ORDER BY query_start LIMIT 10") as $row) {
                $this->line("  running {$row->secs}s {$row->state}: ".self::withoutLiterals((string) $row->query));
            }

            $this->line('  largest tables, with scans:');

            foreach (DB::select('SELECT relname, pg_size_pretty(pg_total_relation_size(relid)) AS size, n_live_tup, n_dead_tup, seq_scan, seq_tup_read, idx_scan FROM pg_stat_user_tables ORDER BY pg_total_relation_size(relid) DESC LIMIT 15') as $t) {
                $this->line(sprintf('    %-32s %-10s live %-10d dead %-9d seq %-8d seq_rows %-14d idx %d', $t->relname, $t->size, $t->n_live_tup, $t->n_dead_tup, $t->seq_scan, $t->seq_tup_read, $t->idx_scan ?? 0));
            }

            $statements = DB::selectOne("SELECT COUNT(*) AS n FROM pg_extension WHERE extname = 'pg_stat_statements'");
            $this->line('  pg_stat_statements: '.((int) $statements->n > 0 ? 'installed' : 'not installed'));
        });
    }

    private function redis(): void
    {
        $this->heading('REDIS');
        $this->attempt(function (): void {
            $info = Redis::info();
            $flat = [];

            array_walk_recursive($info, static function ($value, $key) use (&$flat): void {
                $flat[$key] = $value;
            });

            foreach (['used_memory_human', 'maxmemory_human', 'connected_clients', 'blocked_clients', 'instantaneous_ops_per_sec', 'evicted_keys', 'mem_fragmentation_ratio'] as $key) {
                $this->line("  {$key}: ".($flat[$key] ?? '—'));
            }

            $hits = (int) ($flat['keyspace_hits'] ?? 0);
            $misses = (int) ($flat['keyspace_misses'] ?? 0);
            $this->line('  hit rate: '.($hits + $misses > 0 ? round(100 * $hits / ($hits + $misses), 1).'%' : '—'));
        });
    }

    private function work(): void
    {
        $this->heading('QUEUES AND SYNC LOAD');
        $this->attempt(function (): void {
            foreach (['default', 'reports'] as $queue) {
                $this->line("  queue {$queue}: ".Queue::size($queue).' waiting');
            }

            $since = Carbon::now()->subDay();
            $this->line('  failed jobs last 24h: '.DB::table('failed_jobs')->where('failed_at', '>=', $since)->count());

            foreach (DB::table('failed_jobs')->where('failed_at', '>=', $since)->selectRaw("payload::json->>'displayName' AS job, COUNT(*) AS n")->groupBy('job')->orderByDesc('n')->limit(10)->get() as $row) {
                $this->line("    {$row->job}: {$row->n}");
            }

            $this->line('  metric sync runs last 24h, by provider:');

            foreach (DB::table('metric_sync_runs')->where('started_at', '>=', $since)->selectRaw('provider, status, COUNT(*) AS n, ROUND(AVG(EXTRACT(EPOCH FROM finished_at - started_at))) AS avg_s, ROUND(MAX(EXTRACT(EPOCH FROM finished_at - started_at))) AS max_s, SUM(metrics_upserted) AS upserted')->groupBy('provider', 'status')->orderByDesc('n')->get() as $row) {
                $this->line(sprintf('    %-12s %-16s runs %-6d avg %-6ss max %-6ss upserted %s', $row->provider, $row->status, $row->n, $row->avg_s ?? '—', $row->max_s ?? '—', $row->upserted ?? '0'));
            }

            $this->line('  structure runs last 24h: '.DB::table('integration_sync_runs')->where('started_at', '>=', $since)->count());
        });
    }

    private function attempt(callable $read): void
    {
        try {
            $read();
        } catch (Throwable $e) {
            $this->line('  could not read: '.$e::class);
        }
    }

    private function heading(string $title): void
    {
        $this->line('------------------------------------------------------------------------------');
        $this->line('  '.$title);
    }

    /** A running statement with every quoted value and number removed. */
    public static function withoutLiterals(string $sql): string
    {
        $sql = (string) preg_replace("/'(?:[^']|'')*'/", '?', $sql);
        $sql = (string) preg_replace('/\b\d+(\.\d+)?\b/', '?', $sql);

        return mb_substr((string) preg_replace('/\s+/', ' ', $sql), 0, 300);
    }
}
