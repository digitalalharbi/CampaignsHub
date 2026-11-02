<?php

declare(strict_types=1);

namespace App\Domains\Ops\Performance;

use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * PERF-RECOVERY-001 — what Production is actually slow at, measured where it happens.
 *
 * Nothing in the product could answer «which endpoint, how slow, how many queries». Every figure is
 * an hourly counter in Redis: route PATTERN and duration bucket for requests, the SQL with its `?`
 * placeholders for queries. A route pattern carries no share token and a placeholder carries no value,
 * so no customer data, id or secret can reach these keys.
 *
 * A failure here is swallowed. Measuring must never be the reason a request fails.
 */
final class PerformanceRecorder
{
    /** Upper bounds in milliseconds. A duration above the last is counted in the overflow bucket. */
    public const BUCKETS = [50, 100, 250, 500, 1000, 2500, 5000, 10000, 30000];

    public const OVERFLOW = 60000;

    public const SLOW_QUERY_MS = 200;

    private const TTL_SECONDS = 259200;

    private int $queries = 0;

    private float $queryMs = 0.0;

    public function noteQuery(string $sql, float $ms, string $context): void
    {
        $this->queries++;
        $this->queryMs += $ms;

        if ($ms < self::SLOW_QUERY_MS) {
            return;
        }

        $this->safely(function () use ($sql, $ms, $context): void {
            $id = substr(sha1($sql), 0, 16);
            $key = self::key('sql');
            Redis::hincrby($key, "{$id}|count", 1);
            Redis::hincrby($key, "{$id}|ms", (int) round($ms));
            Redis::hset($key, "{$id}|ctx", $context);
            Redis::hset($key, "{$id}|sql", mb_substr($sql, 0, 400));
            Redis::expire($key, self::TTL_SECONDS);
        });
    }

    public function noteRequest(string $route, float $ms): void
    {
        $queries = $this->queries;
        $queryMs = $this->queryMs;
        $this->queries = 0;
        $this->queryMs = 0.0;

        $this->safely(function () use ($route, $ms, $queries, $queryMs): void {
            $key = self::key('req');
            Redis::hincrby($key, $route.'|b'.self::bucketFor($ms), 1);
            Redis::hincrby($key, $route.'|count', 1);
            Redis::hincrby($key, $route.'|ms', (int) round($ms));
            Redis::hincrby($key, $route.'|queries', $queries);
            Redis::hincrby($key, $route.'|query_ms', (int) round($queryMs));
            Redis::expire($key, self::TTL_SECONDS);
        });
    }

    public static function bucketFor(float $ms): int
    {
        foreach (self::BUCKETS as $bound) {
            if ($ms <= $bound) {
                return $bound;
            }
        }

        return self::OVERFLOW;
    }

    /**
     * The bucket bound a percentile falls in — an upper limit, never an invented exact figure.
     *
     * @param  array<int, int>  $buckets  bound → requests
     */
    public static function percentile(array $buckets, float $p): ?int
    {
        ksort($buckets);
        $total = array_sum($buckets);

        if ($total === 0) {
            return null;
        }

        $target = (int) ceil($total * $p);
        $seen = 0;

        foreach ($buckets as $bound => $count) {
            $seen += $count;

            if ($seen >= $target) {
                return (int) $bound;
            }
        }

        return null;
    }

    /**
     * Requests per route across the last hours.
     *
     * @return array<string, array{count:int, ms:int, queries:int, query_ms:int, buckets:array<int,int>}>
     */
    public function requests(int $hours): array
    {
        $out = [];

        foreach ($this->hashes('req', $hours) as $hash) {
            foreach ($hash as $field => $value) {
                $at = strrpos((string) $field, '|');

                if ($at === false) {
                    continue;
                }

                $route = substr((string) $field, 0, $at);
                $metric = substr((string) $field, $at + 1);
                $out[$route] ??= ['count' => 0, 'ms' => 0, 'queries' => 0, 'query_ms' => 0, 'buckets' => []];

                if (str_starts_with($metric, 'b')) {
                    $bound = (int) substr($metric, 1);
                    $out[$route]['buckets'][$bound] = ($out[$route]['buckets'][$bound] ?? 0) + (int) $value;
                } elseif (in_array($metric, ['count', 'ms', 'queries', 'query_ms'], true)) {
                    $out[$route][$metric] += (int) $value;
                }
            }
        }

        return $out;
    }

    /**
     * Slow statements across the last hours.
     *
     * @return array<string, array{count:int, ms:int, ctx:string, sql:string}>
     */
    public function slowQueries(int $hours): array
    {
        $out = [];

        foreach ($this->hashes('sql', $hours) as $hash) {
            foreach ($hash as $field => $value) {
                [$id, $metric] = array_pad(explode('|', (string) $field, 2), 2, '');
                $out[$id] ??= ['count' => 0, 'ms' => 0, 'ctx' => '', 'sql' => ''];

                if ($metric === 'count' || $metric === 'ms') {
                    $out[$id][$metric] += (int) $value;
                } elseif ($metric === 'ctx' || $metric === 'sql') {
                    $out[$id][$metric] = (string) $value;
                }
            }
        }

        return $out;
    }

    /** @return list<array<string, string>> */
    private function hashes(string $kind, int $hours): array
    {
        $hashes = [];

        for ($i = 0; $i < $hours; $i++) {
            try {
                $hash = Redis::hgetall(self::key($kind, $i));
            } catch (Throwable) {
                return $hashes;
            }

            if (is_array($hash) && $hash !== []) {
                $hashes[] = $hash;
            }
        }

        return $hashes;
    }

    private static function key(string $kind, int $hoursAgo = 0): string
    {
        return "perf:{$kind}:".gmdate('YmdH', time() - $hoursAgo * 3600);
    }

    private function safely(callable $write): void
    {
        if (! config('app.perf_recording', true)) {
            return;
        }

        try {
            $write();
        } catch (Throwable) {
            // See the class comment.
        }
    }
}
