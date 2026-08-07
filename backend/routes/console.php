<?php

use App\Domains\Platform\Jobs\QueueHeartbeatJob;
use App\Domains\Platform\Services\OperationalReadiness;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Prune expired upload sessions + orphaned files hourly.
Schedule::command('requests:prune-uploads')->hourly();

// Evaluate request SLA (warnings + breaches) every 10 minutes.
Schedule::command('requests:evaluate-sla')->everyTenMinutes();

// Dispatch due scheduled reports (snapshot + honest delivery ledger) every 5 minutes.
Schedule::command('reports:dispatch-scheduled')->everyFiveMinutes();

/*
 * The subscription lifecycle (PAY-003) — daily, early.
 *
 * Trials convert, renewals are charged, unpaid periods go past due and expired grace ends in
 * suspension. `withoutOverlapping` because a slow run must not have a second one starting behind it:
 * every step is idempotent, but two sweeps racing on the same subscription is a needless way to test
 * that.
 */
Schedule::command('subscriptions:lifecycle')->dailyAt('01:00')->withoutOverlapping();

// DEV-only: scheduler liveness heartbeat consumed by /dev/status (never scheduled in production).
if (! app()->environment('production')) {
    Schedule::call(function (): void {
        Cache::put('dev:scheduler:heartbeat', now(), now()->addMinutes(10));
    })->everyMinute()->name('dev-scheduler-heartbeat');
}

/*
 * PROD-001 — the two heartbeats that make a dead background process visible.
 *
 * This runs in EVERY environment, production included, which is the difference between it and the
 * dev heartbeat above. Both of these processes die quietly — a supervisor never installed, a cron
 * line lost in a rebuild, an OOM kill at 3am — and until now nothing in the product noticed: reports
 * sat at «قيد المعالجة» and the platforms stopped syncing while `/ready` went on answering `ready`
 * because the database was up.
 *
 * The scheduler stamps itself here. The queue's stamp is written by the job on the worker, because
 * dispatching proves only that Redis accepted a push — a job that comes back out is the only thing
 * that proves somebody is consuming.
 */
Schedule::call(function (): void {
    app(OperationalReadiness::class)->markScheduler();
    QueueHeartbeatJob::dispatch();
})->everyMinute()->name('ops-heartbeat')->withoutOverlapping();

// Evaluate alert rules (budget risk, no results, ROAS drop, sync failure, token expiry) every 15 minutes.
Schedule::command('alerts:evaluate')->everyFifteenMinutes();

/*
 * The ad-platform sweep (INTEG-SYNC-001).
 *
 * Every half hour, and it re-asks for the last seven days rather than only today — every platform
 * restates recent figures as conversions attribute late and spend is corrected, so a sweep that only
 * asked for today would freeze each day at its most wrong. The upsert is idempotent and the job is
 * unique per (account, window), so an overlapping manual sync adds nothing.
 *
 * `withoutOverlapping` because a slow sweep must not have a second one starting behind it: the work
 * is deduplicated, but two sweeps racing to enqueue the same thousand jobs is a needless way to find
 * that out.
 */
Schedule::command('integrations:sync')->everyThirtyMinutes()->withoutOverlapping();

/*
 * Structure discovery (STRUCT-001) — campaigns, ad sets, ads and creatives.
 *
 * Six-hourly rather than half-hourly: a hierarchy changes when a human changes it, and each pass is
 * four calls per account against APIs that count them.
 *
 * It runs at :55, five minutes AHEAD of the metrics sweep on the hour, so discovery lands first —
 * `AccountMetricsSyncer` DROPS an insight row for a campaign it has never seen and reports the run as
 * partial, so a campaign created since the last pass would otherwise lose its first day of spend.
 */
Schedule::command('integrations:sync-structure')->cron('55 */6 * * *')->withoutOverlapping();

/*
 * Refresh tokens BEFORE a sync needs them.
 *
 * The vault refreshes on use too, but discovering a revoked authorisation from a queue worker at 3am
 * surfaces it as a sync failure rather than as «أعد ربط حسابك» on the integrations page, hours before
 * the figures would have stopped arriving.
 */
Schedule::command('integrations:refresh-tokens')->hourly()->withoutOverlapping();

/*
 * The daily digest sweep — hourly, not daily (MAIL-003).
 *
 * A single «dailyAt(08:00)» sends at the SERVER's eight o'clock, which is somebody else's three in
 * the morning. The command asks each recipient whether it is currently their chosen hour in their
 * own timezone, so the schedule has to come round every hour for that question to be answerable.
 * Sending is idempotent by database constraint, so an overlapping run cannot double-send.
 */
Schedule::command('notifications:send-digests')->hourly()->withoutOverlapping();

/*
 * The store sweep (COMMERCE-001) — Salla and Zid.
 *
 * Hourly, at :20, out of the way of both ad sweeps: a store's four paginated reads should not be
 * queued in the same minute as a thousand ad-account jobs, or the two starve each other on a small
 * worker pool.
 *
 * Fourteen days back by default, because an order is not final when it is placed — it is paid,
 * fulfilled, returned and refunded over the following fortnight, and both providers restate it each
 * time. A sweep that asked only for today would leave a client's report showing revenue that has
 * already been refunded.
 */
Schedule::command('commerce:sync')->hourlyAt(20)->withoutOverlapping();

// Retain raw platform payloads for ninety days — long enough to settle a dispute about a figure,
// short enough that the audit trail does not become the largest table in the database.
Schedule::command('integrations:prune-raw')->dailyAt('03:30');
