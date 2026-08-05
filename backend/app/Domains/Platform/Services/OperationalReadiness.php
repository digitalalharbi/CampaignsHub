<?php

declare(strict_types=1);

namespace App\Domains\Platform\Services;

use App\Http\Controllers\HealthController;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * PROD-001 — is this deployment actually working, and how would anyone know?
 *
 * ## The failure this exists to catch
 *
 * The runbook has always said it plainly: without the queue worker, reports are created and then sit
 * at «قيد المعالجة» forever; without the scheduler, nothing syncs, no alert fires and no scheduled
 * report is dispatched. Both processes die quietly — a supervisor that was never installed, a cron
 * line lost in a server rebuild, an OOM kill at 3am — and NOTHING in the product noticed. `/ready`
 * answered `ready` because the database was up, which is a true statement about the database and an
 * answer to a question nobody asked. The product would go on showing yesterday's figures under a
 * «محدَّث» badge for as long as it took a customer to complain.
 *
 * So both processes leave a heartbeat, and this service reads them. A heartbeat is the only honest
 * way to check a background process: asking «is the supervisor running?» from inside the web node
 * tells you about the web node's view of a different machine, whereas a timestamp written BY the work
 * itself is evidence the work happened.
 *
 * ## Why «never seen» is not «down»
 *
 * A deployment that came up ninety seconds ago has no heartbeat yet, and neither does one whose cache
 * was just flushed. Reporting that as `down` would page somebody every release. It is reported as
 * `never_seen`, which is a different sentence and — crucially — a state the reader can act on
 * differently: wait two minutes, then treat it as down.
 *
 * ## Why this never fails the load balancer
 *
 * {@see HealthController::ready()} keeps its own narrow job: can THIS web node
 * serve a request. A dead queue worker is a serious operational fault and not a reason to take
 * healthy web nodes out of rotation — doing that turns a delayed report into an outage. The worker
 * and scheduler verdicts live on the platform operator's status endpoint instead, where somebody can
 * act on them.
 */
final class OperationalReadiness
{
    /** Where each background process records that it ran. */
    public const SCHEDULER_HEARTBEAT = 'ops:scheduler:heartbeat';

    public const QUEUE_HEARTBEAT = 'ops:queue:heartbeat';

    /**
     * How long a heartbeat may go unrefreshed before the process behind it is presumed dead.
     *
     * The heartbeat is written every minute, so five is four missed beats — long enough to ride out a
     * slow sweep holding the scheduler, short enough that a dead worker is known about before the next
     * scheduled report is due (five minutes).
     */
    public const HEARTBEAT_GRACE_MINUTES = 5;

    /** Heartbeats are kept well past the grace window so «stale» is distinguishable from «expired». */
    public const HEARTBEAT_TTL_MINUTES = 120;

    /**
     * Can this node serve a request — the load balancer's question, and only that.
     *
     * Probes the datastores the application is ACTUALLY configured to use. The previous version
     * pinged Redis unconditionally, so a deployment running the database queue and database sessions —
     * a supported configuration, and the one `config/queue.php` still defaults to — was reported
     * unready for a dependency it does not have, and would never have entered rotation.
     *
     * @return array{ready:bool, checks:array<string,string>}
     */
    public function serving(): array
    {
        $checks = ['database' => $this->probe(fn () => DB::connection()->getPdo())];

        if ($this->usesRedis()) {
            $checks['redis'] = $this->probe(fn () => Redis::connection()->ping());
        }

        return [
            'ready' => ! in_array('down', $checks, true),
            'checks' => $checks,
        ];
    }

    /**
     * The full operational picture — for the platform operator and their monitoring, not for a client.
     *
     * @return array<string,mixed>
     */
    public function status(?Carbon $now = null): array
    {
        $now ??= Carbon::now();

        $serving = $this->serving();
        $scheduler = $this->heartbeat(self::SCHEDULER_HEARTBEAT, $now);
        $queue = $this->heartbeat(self::QUEUE_HEARTBEAT, $now);

        return [
            'serving' => $serving,
            'processes' => [
                'scheduler' => $scheduler + [
                    'why_it_matters_ar' => 'بدون المجدول لا تُزامَن المنصات ولا تُقيَّم التنبيهات ولا تُرسَل التقارير المجدولة.',
                    'why_it_matters_en' => 'Without the scheduler nothing syncs, no alert is evaluated and no scheduled report is dispatched.',
                    'fix' => '* * * * * php artisan schedule:run',
                ],
                'queue' => $queue + [
                    'why_it_matters_ar' => 'بدون العامل تبقى التقارير عند «قيد المعالجة» ولا تكتمل أبدًا.',
                    'why_it_matters_en' => 'Without the worker, reports stay at «processing» and never complete.',
                    'fix' => 'php artisan horizon   (or: php artisan queue:work --queue=reports,default --tries=3 --timeout=120)',
                ],
            ],
            'queue_depth' => $this->queueDepth(),
            /*
             * Everything above is a claim about this deployment, so the verdict is only as good as its
             * weakest part — and it is stated rather than left for the reader to assemble from three
             * sections. `degraded` and `down` are separated because they call for different urgency:
             * a stopped worker is a delay, an unreachable database is an outage.
             */
            'verdict' => match (true) {
                ! $serving['ready'] => 'down',
                $scheduler['state'] === 'down' || $queue['state'] === 'down' => 'degraded',
                $scheduler['state'] === 'never_seen' || $queue['state'] === 'never_seen' => 'unverified',
                default => 'healthy',
            },
        ];
    }

    /** Record that the scheduler ran. Called once a minute from `routes/console.php`. */
    public function markScheduler(?Carbon $now = null): void
    {
        Cache::put(self::SCHEDULER_HEARTBEAT, ($now ?? Carbon::now())->toIso8601String(), Carbon::now()->addMinutes(self::HEARTBEAT_TTL_MINUTES));
    }

    /** Record that a queued job was actually PROCESSED — written from inside the job, on the worker. */
    public function markQueue(?Carbon $now = null): void
    {
        Cache::put(self::QUEUE_HEARTBEAT, ($now ?? Carbon::now())->toIso8601String(), Carbon::now()->addMinutes(self::HEARTBEAT_TTL_MINUTES));
    }

    /**
     * @return array{state:string, last_seen_at:?string, minutes_since:?int}
     */
    private function heartbeat(string $key, Carbon $now): array
    {
        $raw = Cache::get($key);

        if ($raw === null) {
            return ['state' => 'never_seen', 'last_seen_at' => null, 'minutes_since' => null];
        }

        $at = Carbon::parse((string) $raw);
        $minutes = (int) $at->diffInMinutes($now);

        return [
            'state' => $minutes > self::HEARTBEAT_GRACE_MINUTES ? 'down' : 'up',
            'last_seen_at' => $at->toIso8601String(),
            'minutes_since' => $minutes,
        ];
    }

    /**
     * How much work is waiting, and how much has given up.
     *
     * `failed` is the number that matters most and the one nobody looks at: a failed job is work that
     * was accepted and silently never done. Counted from the database in every configuration, because
     * `failed_jobs` is where Laravel puts them whatever the queue connection is.
     *
     * @return array<string,int|null>
     */
    private function queueDepth(): array
    {
        return [
            'failed' => $this->count('failed_jobs'),
            // Only meaningful on the database driver; null (not zero) on redis, where Horizon owns it.
            'pending' => config('queue.default') === 'database' ? $this->count('jobs') : null,
        ];
    }

    private function count(string $table): ?int
    {
        try {
            return (int) DB::table($table)->count();
        } catch (Throwable) {
            return null;
        }
    }

    /** True when any of the drivers this app uses actually resolve to Redis. */
    private function usesRedis(): bool
    {
        return in_array('redis', [
            config('queue.default'),
            config('cache.default'),
            config('session.driver'),
        ], true);
    }

    private function probe(callable $probe): string
    {
        try {
            $probe();

            return 'up';
        } catch (Throwable) {
            return 'down';
        }
    }
}
