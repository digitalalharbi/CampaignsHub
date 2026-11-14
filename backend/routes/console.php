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
// PRICING-SEATS-001 — released ad-account seats stamped within the hour, so the 30-day replacement window starts on time.
Schedule::command('seats:reconcile')->hourly()->withoutOverlapping();

// PAY-RECONCILE-001 — a payment the gateway took is settled even when its webhook was dropped after the gateway's retries.
Schedule::command('subscriptions:reconcile-payments')->everyFifteenMinutes()->withoutOverlapping();

// PLATFORM-PAYMENT-ALERTS-001 — tells the platform owner about customers stuck at payment or sign-up. Reads only; a
// unique key per attempt makes a re-run inert. Offset from the reconcile sweep so a payment it settles is not reported.
Schedule::command('platform:payment-alerts')->cron('7,22,37,52 * * * *')->withoutOverlapping();

// FORMS-PDPL-001 — the evidence around a form submission expires; the consent record does not.
Schedule::command('forms:purge-evidence')->dailyAt('02:30')->withoutOverlapping();

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
// PERF-RECOVERY-001 — a sweep never stacks on itself, which could have raised one breach twice. The lock's default life is a day, so a killed run (a deploy, the OOM killer)
// would silence every alert for a day; 14 minutes means the next tick after a killed run still fires. A run that outlives it can overlap the next, as every run could before.
Schedule::command('alerts:evaluate')->everyFifteenMinutes()->withoutOverlapping(14);

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
// FIN-LOOP-001 — count the spend the sync just stored against each project's approved budget (idempotent per account-day).
Schedule::command('funding:refresh')->cron('20,50 * * * *')->withoutOverlapping();
// FIN-LOOP-001 — fix last month's budget summary once; daily so a missed day is caught; a fixed month is never rewritten.
Schedule::command('funding:close-month')->dailyAt('02:40')->timezone('Asia/Riyadh')->withoutOverlapping();

// PERF-SYNC-ISOLATION-001 — the half-hourly sweep above asks 3 days back; this daily pass re-asks the
// fortnight in which providers restate (late attribution, corrected spend). Same command, same
// ledger rows (`scheduled_runs.command` is the artisan name), same assigned-accounts-only gate.
Schedule::command('integrations:sync --days=14')->dailyAt('03:50')->timezone('Asia/Riyadh')->withoutOverlapping();

// SALLA-ONBOARD-001 — a store uninstalled 30 days ago has its synced data removed (the retention the merchant is promised).
Schedule::command('commerce:purge-uninstalled')->dailyAt('03:30')->withoutOverlapping();

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

// TIKTOK-MEDIA-002 — covers into our own storage.
// CREATIVE-PREVIEWS-001 — hourly, not nightly: a TikTok image link lives an hour and a video cover six, so a
// once-a-night pass met most of them dead. Each run queues only what is due (no copy, or a newer link, and
// not backing off), spaced out; a lapsed link is renewed through the platform's own rate budget first.
Schedule::command('creatives:backfill-covers', ['--execute'])->hourlyAt(40)->withoutOverlapping();

/*
 * Close the runs a worker abandoned — before the next sweep reads them as work in progress.
 *
 * `SyncAccountStructureJob::failed()` closes a run whose job the QUEUE knows died. It cannot close
 * one whose worker vanished: a SIGKILL, an OOM, a container replaced mid-flight. Nothing calls that
 * hook then, and the row stays `running` with no upper bound.
 *
 * That is not hypothetical here. A forced Snapchat sync on 2026-09-07 was refused by its own guard
 * because a structure run had been «running» since 2026-08-26 — twelve days, on a job whose timeout
 * is fifteen minutes. An open run is read as work in progress by the Integration Centre, by the
 * diagnosis and by the accept command, so a worker that died in August blocked an operator in
 * September and the product's answer to «what is happening» was wrong the whole time.
 *
 * Ten past the hour: after the metrics sweep on the hour has had time to open its own runs
 * legitimately, and well before the next structure pass at :55.
 */
Schedule::command('integrations:close-abandoned-runs --apply')->hourlyAt(10)->withoutOverlapping();

/*
 * INTEG-SOURCE-REMOVAL-001 — hide authorisations that were started in the wizard and never finished.
 *
 * Scheduled daily but inert until INTEGRATIONS_ABANDONED_SWEEP_ENABLED=true: without it the command only
 * reports. It hides (never deletes) a connection older than a day with no selection, no sync and not one
 * binding, metric, run or mapping row referencing it.
 */
Schedule::command('integrations:sweep-abandoned --apply')->dailyAt('04:20')->withoutOverlapping();

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
// DIGESTS-CADENCE-BUDGETS-001 — pinned to Riyadh, as every other morning job here: the app clock is UTC (`config/app.php`, and no `APP_TIMEZONE` in the production env), so
// the scheduler's own clock is UTC. The sweep is hourly and asks each reader's own hour (08:00 Asia/Riyadh by default), so the pin
// states the frame the 08:00 promise is made in rather than moving any send.
Schedule::command('notifications:send-digests')->hourly()->timezone('Asia/Riyadh')->withoutOverlapping();
// SPEND-SUMMARY-EXTERNAL-RECIPIENTS-001 — «ملخص الإنفاق»: hourly, acting from 08:00 in the reader's timezone; idempotent per period. At :10, after the digests.
Schedule::command('notifications:send-spend-summaries')->hourlyAt(10)->timezone('Asia/Riyadh')->withoutOverlapping();
// EMAIL-DELIVERY-LEDGER-001 — a failed digest comes back after its backoff; the sweep alone never reached it again.
Schedule::command('notifications:retry-failed')->everyFifteenMinutes()->withoutOverlapping();

/*
 * The alert sweep (MAIL-006) — four times a day, not hourly.
 *
 * An alert says «this needs a decision», and the decisions this product surfaces do not change
 * between nine and ten in the morning: a budget running ahead of plan is still ahead an hour later,
 * and a source that stopped syncing has not started. Four sweeps is soon enough to act on and rare
 * enough not to become noise — and the cooldown inside `AlertDispatcher` is what stops the same
 * finding arriving four times in one day.
 *
 * At :40, out of the way of the digest sweep on the hour.
 */
Schedule::command('notifications:send-alerts')->cron('40 6,10,14,18 * * *')->withoutOverlapping();

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

/*
 * INTEG-WEBHOOK-ENGINE-001 — webhook reconciliation: read-only toward the provider, bounded, OFF by default.
 *
 * Listed unconditionally so the scheduled-work inventory sees it; `when()` is what keeps it quiet until the
 * Owner sets WEBHOOK_RECONCILE_ENABLED. Every six hours at :40, clear of the :20 store sweep, and only the
 * stores bound to a project, at most twenty per run.
 */
Schedule::command('webhooks:reconcile')->cron('40 */6 * * *')->withoutOverlapping()
    ->when(static fn (): bool => (bool) config('integrations.webhooks.reconcile.enabled'));

/*
 * GA4-INTEGRATION-001 — the measurement sweep.
 *
 * Four-hourly, not half-hourly. GA4 is not restated as often as an ad platform's spend, and the Data
 * API meters in TOKENS per property per day rather than in requests: a half-hourly pass would spend
 * a client's whole daily allowance re-reading days that did not change, and the exhaustion lands on
 * the client's own Analytics usage as well as ours.
 *
 * Offset to :25 so it does not start in the same minute as the hourly commerce sweep and the token
 * refresh — three sweeps opening connections at once is how a worker pool is starved by its own
 * schedule.
 */
Schedule::command('measurement:sync')->cron('25 */4 * * *')->withoutOverlapping();

// Retain raw platform payloads for ninety days — long enough to settle a dispute about a figure,
// short enough that the audit trail does not become the largest table in the database.
Schedule::command('integrations:prune-raw')->dailyAt('03:30');

/*
 * FX-FEED-001 — the exchange rates the conversions need (FX-001, COMMERCE-FX-001).
 *
 * Scheduled even though no deployment in this repository has a rate source configured, and that is
 * deliberate: the day an operator sets FX_RATE_DRIVER, rates start arriving without anybody having to
 * remember to add a cron line. Until then the command reports that nothing is configured and writes
 * NOTHING — it must never invent a rate to fill the gap it is reporting.
 *
 * Early, before the ad and store sweeps of the working day, so a figure ingested this morning finds
 * today's rate already on file rather than being withheld until tomorrow.
 */
Schedule::command('fx:rates')->dailyAt('02:30')->withoutOverlapping();

/*
 * The periodic delivery packs (WD-SCHEDULE-001, DELIVERY-SETTINGS-001) — every morning, Riyadh time; each client is prepared on its own day.
 *
 * Prepares last ISO week's DRAFT pack for each client that opted in; sending is the Owner's decision.
 * Idempotent per project and week, so a late retry or a manual preparation cannot double it.
 */
// AGENT-CHAT-PRIVACY-001 — conversations past their retention are deleted.
Schedule::command('agents:purge-chat')->dailyAt('03:10')->timezone('Asia/Riyadh')->withoutOverlapping();

Schedule::command('delivery:prepare-weekly')->dailyAt('06:00')->timezone('Asia/Riyadh')->withoutOverlapping();

// DELIVERY-REMINDERS-001 — the chosen reviewer is nudged about a waiting, failed or unsent delivery (once a day per reason).
Schedule::command('delivery:remind')->dailyAt('09:00')->timezone('Asia/Riyadh')->withoutOverlapping();

/*
 * AGENTS-ROUTINES-001 — the agent routines, each run only for projects that switched it on (each run
 * spends the tenant's own model tokens). Times are the client's (Riyadh). A change an agent wants is a
 * PROPOSAL in Approvals; no routine changes a campaign.
 */
Schedule::command('agents:run-routines daily_analysis')->dailyAt('08:45')->timezone('Asia/Riyadh')->withoutOverlapping();
Schedule::command('agents:run-routines budget_guard')->cron('5 */2 * * *')->withoutOverlapping();
Schedule::command('agents:run-routines creative_review')->cron('0 9 * * 0,3')->timezone('Asia/Riyadh')->withoutOverlapping();
Schedule::command('agents:run-routines weekly_report')->cron('0 14 * * 4')->timezone('Asia/Riyadh')->withoutOverlapping();

/*
 * AUTO-RULES-001 — automated rules: every five minutes the due ones run (each rule keeps its own
 * frequency and timezone). Changes go through the approval gateway; nothing here bypasses it.
 */
Schedule::command('automation:evaluate')->everyFiveMinutes()->withoutOverlapping();

// PERF-RECOVERY-001 — logs an error when a route's p95 or a queue backlog passes its limit.
Schedule::command('ops:perf-check')->everyFiveMinutes()->withoutOverlapping();
