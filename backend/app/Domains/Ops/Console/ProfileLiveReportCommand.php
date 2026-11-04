<?php

declare(strict_types=1);

namespace App\Domains\Ops\Console;

use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Models\ReportShareAccessLog;
use App\Domains\Reports\Services\LiveReportService;
use App\Domains\Reports\Services\ShareService;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Request;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * PERF-RECOVERY-001 — where a public live report spends its time. Read-only.
 *
 * Builds the live payload for the most recently opened active live links exactly as the public
 * endpoint does (the same service, no query filters) and prints wall time, query count, database
 * time and the heaviest statements. Statements are shown with their bindings stripped; a link is
 * named by the first 8 characters of its id, never by its token.
 */
final class ProfileLiveReportCommand extends Command
{
    protected $signature = 'ops:profile-live-report {--links=2 : Live links to profile} {--top=20 : Statements to print} {--explain=0 : EXPLAIN ANALYZE the N heaviest SELECTs (reads only)} {--http : Also run the request through the HTTP kernel in-process and time the whole path (share token swapped inside a rolled-back transaction; no log row kept)} {--token=* : Explain why this link answers as it does (state and recent access log); read-only}';

    protected $description = 'Read-only: time, query count and heaviest queries of the public live report build.';

    /** @var array<string, mixed> the current link's counters */
    private array $m = [];

    public function handle(LiveReportService $live, ShareService $shareService): int
    {
        foreach ((array) $this->option('token') as $raw) {
            $this->explain((string) $raw);
        }

        $shares = ReportShare::withoutGlobalScopes()->get()
            ->filter(static fn (ReportShare $s): bool => $s->isActive() && $s->isLive())
            ->sortByDesc(static fn (ReportShare $s) => $s->updated_at)
            ->take(max(1, (int) $this->option('links')));

        // Registered ONCE, writing to the current link's counters: registered per link they stack, and every figure after the first doubles.
        DB::listen(function ($q): void {
            $sql = preg_replace('/\s+/', ' ', $q->sql);
            $this->m['stats'][$sql]['n'] = ($this->m['stats'][$sql]['n'] ?? 0) + 1;
            $this->m['stats'][$sql]['ms'] = ($this->m['stats'][$sql]['ms'] ?? 0.0) + $q->time;
            $this->m['stats'][$sql]['max'] = max($this->m['stats'][$sql]['max'] ?? 0.0, $q->time);
            $this->m['stats'][$sql]['bindings'] ??= $q->bindings;
            $this->m['db'] = ($this->m['db'] ?? 0.0) + $q->time;
            $this->m['queries'] = ($this->m['queries'] ?? 0) + 1;
        });
        try {
            Redis::connection()->listen(function (CommandExecuted $e): void {
                $this->m['redis'] = ($this->m['redis'] ?? 0.0) + $e->time;
                $this->m['redisN'] = ($this->m['redisN'] ?? 0) + 1;
            });
        } catch (\Throwable) {
            // The configured cache/queue store may not be Redis; nothing to time then.
        }
        Event::listen(CacheHit::class, function (): void {
            $this->m['hit'] = ($this->m['hit'] ?? 0) + 1;
        });
        Event::listen(CacheMissed::class, function (): void {
            $this->m['miss'] = ($this->m['miss'] ?? 0) + 1;
        });
        Event::listen(KeyWritten::class, function (): void {
            $this->m['write'] = ($this->m['write'] ?? 0) + 1;
        });
        Event::listen(ResponseReceived::class, function (ResponseReceived $e): void {
            $this->m['ext'] = ($this->m['ext'] ?? 0.0) + (float) ($e->response->transferStats?->getTransferTime() ?? 0) * 1000;
            $this->m['extN'] = ($this->m['extN'] ?? 0) + 1;
        });

        foreach ($shares as $share) {
            $report = Report::withoutGlobalScopes()->find($share->report_id);

            if (! $report) {
                continue;
            }

            $this->m = ['stats' => [], 'db' => 0.0, 'queries' => 0, 'redis' => 0.0, 'redisN' => 0, 'ext' => 0.0, 'extN' => 0, 'hit' => 0, 'miss' => 0, 'write' => 0];

            $t = microtime(true);
            $payload = $live->build($share, [], (string) $report->currency);
            $wall = (microtime(true) - $t) * 1000;

            // The controller's own remaining work: the hide-flag sanitising and the JSON the client receives.
            $t2 = microtime(true);
            $sanitised = $shareService->sanitizeLive($payload, $share);
            $json = (string) json_encode($sanitised);
            $render = (microtime(true) - $t2) * 1000;

            $php = max(0.0, $wall - $this->m['db'] - $this->m['redis'] - $this->m['ext']);
            $this->line(sprintf(
                'LINK %s  BUILD %.0f ms = DB %.0f ms (%d queries) + REDIS %.0f ms (%d cmds) + EXTERNAL %.0f ms (%d calls) + PHP/other %.0f ms',
                substr((string) $share->id, 0, 8), $wall, $this->m['db'], $this->m['queries'], $this->m['redis'], $this->m['redisN'], $this->m['ext'], $this->m['extN'], $php,
            ));
            $this->line(sprintf(
                '  SERIALIZE/RENDER (sanitise + json_encode) %.0f ms · json %d B · cache hit %d miss %d write %d · peak mem %d MB · TOTAL (build+render) %.0f ms',
                $render, strlen($json), $this->m['hit'], $this->m['miss'], $this->m['write'], memory_get_peak_usage(true) / 1048576, $wall + $render,
            ));

            /** @var array<string, array{n: int, ms: float, max: float, bindings: array<int, mixed>}> $stats */
            $stats = $this->m['stats'];
            $buildCounters = $this->m;
            uasort($stats, static fn (array $a, array $b): int => $b['ms'] <=> $a['ms']);
            $this->line(sprintf('  %-6s %-9s %-9s %-9s %-9s %s', 'runs', 'total ms', 'mean ms', 'max ms', 'finger', 'statement'));

            foreach (array_slice($stats, 0, max(1, (int) $this->option('top')), true) as $sql => $s) {
                $this->line(sprintf('  %-6d %-9.1f %-9.2f %-9.1f %-9s %s', $s['n'], $s['ms'], $s['ms'] / max(1, $s['n']), $s['max'], substr(md5((string) $sql), 0, 8), substr((string) $sql, 0, 260)));
            }

            $this->explainTop($stats, max(0, (int) $this->option('explain')));

            if ($this->option('http')) {
                $this->m = $buildCounters;
                $this->profileHttp($share);
            }

            $this->newLine();
        }

        return self::SUCCESS;
    }

    /**
     * The plans of the heaviest statements, as Postgres ran them. SELECTs only, with the bindings they really ran with.
     *
     * @param  array<string, array{n: int, ms: float, max: float, bindings: array<int, mixed>}>  $stats  heaviest first
     */
    private function explainTop(array $stats, int $count): void
    {
        foreach (array_slice($stats, 0, $count, true) as $sql => $s) {
            if (! str_starts_with(strtolower(ltrim((string) $sql)), 'select')) {
                continue;
            }

            $this->line('  EXPLAIN '.substr(md5((string) $sql), 0, 8));

            // One statement that will not explain (timeout, lock, a binding that no longer fits) must not end the profile.
            try {
                foreach (DB::select('EXPLAIN (ANALYZE, BUFFERS) '.$sql, $s['bindings']) as $row) {
                    $this->line('    '.substr((string) (array_values((array) $row)[0] ?? ''), 0, 200));
                }
            } catch (\Throwable $e) {
                $this->line('  EXPLAIN FAILED '.substr(md5((string) $sql), 0, 8).' — '.substr(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 0, 160));
            }
        }
    }

    /** Why a link answers 404, from the row itself: revoked, paused, expired or not live. Changes nothing. */
    private function explain(string $raw): void
    {
        $share = ReportShare::withoutGlobalScopes()->where('token_hash', hash('sha256', $raw))->first();
        $label = substr($raw, 0, 4).'…';

        if (! $share) {
            $this->line("LINK {$label}: no row for this token");

            return;
        }

        $this->line(sprintf(
            'LINK %s: id %s · mode %s · live %s · active %s · revoked_at %s · paused_at %s · expires_at %s · views %d · last viewed %s',
            $label,
            substr((string) $share->id, 0, 8),
            $share->mode,
            $share->isLive() ? 'yes' : 'no',
            $share->isActive() ? 'yes' : 'no',
            $share->revoked_at?->toIso8601String() ?? '-',
            $share->paused_at?->toIso8601String() ?? '-',
            $share->expires_at?->toIso8601String() ?? '-',
            (int) $share->view_count,
            $share->last_viewed_at?->toIso8601String() ?? '-',
        ));

        foreach ($share->logs()->latest()->limit(5)->get() as $log) {
            $this->line(sprintf('    %s  %s  %s', $log->created_at?->toIso8601String(), $log->action, substr((string) $log->detail, 0, 80)));
        }
    }

    /**
     * The same link through the whole HTTP kernel, in-process: middleware, controller, build, sanitise, JSON.
     *
     * Only the hash of a token is stored, so no raw token can be recovered. The share's hash is swapped for a throwaway
     * token INSIDE a transaction that is always rolled back, so the row is never left changed. The view log row the
     * controller would write is cancelled before it is inserted. The phase split is read from that cancelled insert:
     * it happens right after the build and the sanitising, so everything before it is "to log", everything after is
     * the response (JSON encode and the middleware on the way out).
     */
    private function profileHttp(ReportShare $share): void
    {
        $label = substr((string) $share->id, 0, 8);
        $raw = Str::random(48);
        $state = new \ArrayObject(['armed' => false, 'log' => 0.0]);
        $event = 'eloquent.creating: '.ReportShareAccessLog::class;

        DB::beginTransaction();

        try {
            DB::table('report_shares')->where('id', $share->id)->update(['token_hash' => hash('sha256', $raw)]);

            // Fresh counters AFTER the swap, so the swap itself is not billed to the request.
            $this->m = ['stats' => [], 'db' => 0.0, 'queries' => 0, 'redis' => 0.0, 'redisN' => 0, 'ext' => 0.0, 'extN' => 0, 'hit' => 0, 'miss' => 0, 'write' => 0];

            // Not forgotten afterwards: the model registers its own listener on this event name (the id), and forgetting removes it too.
            Event::listen($event, static function () use ($state): ?bool {
                if (! $state['armed']) {
                    return null;
                }
                $state['log'] = microtime(true);

                return false;
            });
            $state['armed'] = true;

            $request = Request::create('/api/v1/reports/shared/'.$raw.'/live', 'GET', server: ['HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '127.0.0.1']);
            $t = microtime(true);
            $response = app(Kernel::class)->handle($request);
            $end = microtime(true);

            $bytes = strlen((string) $response->getContent());
            $status = $response->getStatusCode();
        } catch (\Throwable $e) {
            $this->line(sprintf('LINK %s  HTTP FAILED — %s', $label, substr(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 0, 160)));

            return;
        } finally {
            $state['armed'] = false;
            DB::rollBack();
        }

        $wall = ($end - $t) * 1000;
        $php = max(0.0, $wall - $this->m['db'] - $this->m['redis'] - $this->m['ext']);
        $this->line(sprintf(
            'LINK %s  HTTP %.0f ms (status %d, %d bytes) = DB %.0f ms (%d queries) + PHP/other %.0f ms   [vs BUILD above]',
            $label, $wall, $status, $bytes, $this->m['db'], $this->m['queries'], $php,
        ));

        $toLog = $state['log'] > 0.0 ? ($state['log'] - $t) * 1000 : null;
        $this->line(sprintf(
            '  HTTP PHASES to-log (middleware + share + build + sanitise) %s ms · after-log (json + response) %s ms · REDIS %.0f ms (%d cmds) · cache hit %d miss %d write %d · view log not written',
            $toLog === null ? 'n/a' : sprintf('%.0f', $toLog),
            $toLog === null ? 'n/a' : sprintf('%.0f', $wall - $toLog),
            $this->m['redis'], $this->m['redisN'], $this->m['hit'], $this->m['miss'], $this->m['write'],
        ));
    }
}
