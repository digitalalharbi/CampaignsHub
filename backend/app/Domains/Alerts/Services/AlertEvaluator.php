<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Services;

use App\Domains\Alerts\Models\AlertEvent;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Domains\Metrics\Models\SpendLimit;
use App\Domains\Metrics\Models\SpendLimitEvent;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Metrics\Services\SpendLimitGovernor;
use App\Domains\Notifications\Services\NotificationDispatcher;
use App\Domains\Tasks\Models\Task;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turns raw signals into alerts, honestly and without storms.
 *
 * Lifecycle per (rule, entity): the first breach opens an AlertEvent and raises a notification (via the shared
 * NotificationDispatcher, so quiet-hours / per-user preferences / in-app-vs-email honesty all apply) and,
 * optionally, a Task. A repeat breach is suppressed while the rule's COOLDOWN window is open, while the event
 * is SNOOZED, or while it stays de-duplicated — so a persistent problem alerts once, not every five minutes.
 * Resolving an event closes it; a fresh breach after the cooldown re-opens a new one.
 *
 * Alert types: budget_risk, cpa_increase, cpl_increase, roas_drop, no_results, sync_failure, token_expiry,
 * report_failed, sla_warning. Each maps to a notification type for the inbox + delivery ledger.
 */
final class AlertEvaluator
{
    /**
     * The types THIS evaluator raises on a schedule.
     *
     * `AlertController::TYPES` is what a person may create; this is what actually gets evaluated, and
     * the two drifted. `cpa_increase` and `cpl_increase` were accepted by the controller, labelled in
     * the alerts page («ارتفاع CPA»), named in this class's own docblock — and fell through `match`'s
     * `default => []`. The rule saved, listed as active, and could never fire. A silent no-op is worse
     * than a missing feature: the operator believes they are covered.
     *
     * `AlertEvaluatorCoverageTest` asserts PERIODIC + EVENT_DRIVEN + UNRAISED equals the controller's set,
     * so the two cannot drift apart again without a red test.
     */
    public const PERIODIC = [
        'budget_risk', 'cpa_increase', 'cpl_increase', 'roas_drop',
        'no_results', 'sync_failure', 'token_expiry',
    ];

    /** Raised by the thing that failed, not by a sweep — a report failing is an event, not a threshold. */
    public const EVENT_DRIVEN = ['report_failed'];

    /**
     * Creatable, and raised by nothing at all — ALERT-SLA-UNRAISED-001.
     *
     * `sla_warning` is offered by the picker and accepted by the controller, and no code anywhere
     * raises it. (`EvaluateSla` emits `request.sla_warning` in the Requests domain — a different
     * vocabulary.) Withdrawing it means moving the `alert.type` taxonomy option and the controller
     * list together, with a migration for the options already seeded in production; doing only part
     * of that turns a silent rule into a rejected form. It is named here so the coverage test can
     * assert it is KNOWN rather than merely missing.
     */
    public const UNRAISED = ['sla_warning'];

    /** alert rule type → notification type (the notification center's vocabulary). */
    private const NOTIFICATION_TYPE = [
        'budget_risk' => 'budget_risk',
        'cpa_increase' => 'budget_risk',
        'cpl_increase' => 'budget_risk',
        'roas_drop' => 'budget_risk',
        'no_results' => 'budget_risk',
        'sync_failure' => 'sync_failed',
        'token_expiry' => 'token_expiring',
        'report_failed' => 'report_failed',
        'sla_warning' => 'security',
    ];

    public function __construct(
        private readonly NotificationDispatcher $notifications,
        private readonly MetricsAggregator $metrics,
        private readonly TenantContext $tenants,
        private readonly SpendLimitGovernor $limits,
    ) {}

    /** Evaluate every active rule across all tenants. Returns the number of newly raised alerts. */
    public function evaluateAll(?Carbon $now = null): int
    {
        $now ??= Carbon::now();
        $raised = 0;

        // Rules are tenant-scoped models; iterate tenant-by-tenant so every scoped query (metrics, connections,
        // campaigns) resolves inside the correct tenant boundary.
        $tenantIds = DB::table('alert_rules')->where('active', true)->distinct()->pluck('tenant_id');
        $previous = $this->tenants->tenantId();

        foreach ($tenantIds as $tenantId) {
            $this->tenants->setTenantId((string) $tenantId);
            AlertRule::query()->where('active', true)->get()
                ->each(function (AlertRule $rule) use ($now, &$raised) {
                    $raised += $this->evaluateRule($rule, $now);
                });
        }

        $this->tenants->setTenantId($previous);

        return $raised;
    }

    /** Evaluate one rule; returns newly raised alerts for it. */
    public function evaluateRule(AlertRule $rule, ?Carbon $now = null): int
    {
        $now ??= Carbon::now();
        $breaches = match ($rule->type) {
            'sync_failure' => $this->syncFailures($rule),
            'token_expiry' => $this->tokenExpiries($rule, $now),
            'budget_risk' => $this->budgetRisks($rule, $now),
            'no_results' => $this->noResults($rule, $now),
            'roas_drop' => $this->roasDrops($rule, $now),
            'cpa_increase' => $this->costPerIncreases($rule, $now, 'cpa', 'conversions'),
            'cpl_increase' => $this->costPerIncreases($rule, $now, 'cpl', 'leads'),
            default => $this->unevaluated($rule),
        };

        $raised = 0;
        foreach ($breaches as $b) {
            if ($this->raise($rule, $b, $now)) {
                $raised++;
            }
        }

        return $raised;
    }

    /**
     * Open (or refresh) an alert for a breach, honoring cooldown / snooze / dedup. Returns true only when a
     * NEW alert is opened (a within-cooldown refresh or a suppressed repeat returns false).
     *
     * @param  array{entity_type:string,entity_id:?string,project_id:?string,title:string,message:string,context:array<string,mixed>}  $b
     */
    private function raise(AlertRule $rule, array $b, Carbon $now): bool
    {
        $dedupKey = hash('sha256', implode('|', [$rule->id, $b['entity_type'], $b['entity_id'] ?? '']));
        $cooldownUntil = fn (AlertEvent $e) => ($e->last_triggered_at ?? $e->created_at)
            ->copy()->addMinutes($rule->cooldown_minutes);

        // An active (open|snoozed) event for this rule+entity?
        $active = AlertEvent::query()
            ->where('dedup_key', $dedupKey)->whereIn('status', ['open', 'snoozed'])
            ->latest('last_triggered_at')->first();

        if ($active !== null) {
            // Snoozed into the future, or still inside the cooldown window → suppress (no storm).
            if ($active->status === 'snoozed' && $active->snoozed_until !== null && $active->snoozed_until->isFuture()) {
                return false;
            }
            if ($cooldownUntil($active)->isFuture()) {
                return false;
            }
            // Cooldown elapsed on a still-open problem: refresh the same event + re-notify (not a new alert).
            $active->forceFill([
                'last_triggered_at' => $now,
                'context' => array_merge($b['context'], ['title' => $b['title'], 'message' => $b['message']]),
                'status' => 'open',
            ])->save();
            $this->notify($rule, $b);

            return false;
        }

        // No active event. If the most recent (possibly resolved) event is still inside cooldown, suppress.
        $recent = AlertEvent::query()->where('dedup_key', $dedupKey)->latest('last_triggered_at')->first();
        if ($recent !== null && $cooldownUntil($recent)->isFuture()) {
            return false;
        }

        // Fresh alert.
        $notification = $this->notify($rule, $b);
        $taskId = $rule->create_task ? $this->openTask($rule, $b) : null;

        AlertEvent::create([
            'tenant_id' => $rule->tenant_id,
            'project_id' => $b['project_id'] ?? $rule->project_id,
            'rule_id' => $rule->id,
            'type' => $rule->type,
            'entity_type' => $b['entity_type'],
            'entity_id' => $b['entity_id'],
            'dedup_key' => $dedupKey,
            'status' => 'open',
            'severity' => $rule->severity,
            // Persist the human title/message alongside the measured values so the alerts UI can render them.
            'context' => array_merge($b['context'], ['title' => $b['title'], 'message' => $b['message']]),
            'notification_id' => $notification,
            'task_id' => $taskId,
            'last_triggered_at' => $now,
        ]);

        return true;
    }

    /** Raise the notification through the shared dispatcher; returns the notification id (or null if deduped). */
    private function notify(AlertRule $rule, array $b): ?string
    {
        $n = $this->notifications->dispatch([
            'tenant_id' => $rule->tenant_id,
            'project_id' => $b['project_id'] ?? $rule->project_id,
            'type' => self::NOTIFICATION_TYPE[$rule->type] ?? 'performance',
            'severity' => $rule->severity === 'critical' ? 'error' : ($rule->severity === 'info' ? 'info' : 'warning'),
            'title' => $b['title'],
            'message' => $b['message'],
            'source' => 'alerts',
            'entity_type' => $b['entity_type'],
            'entity_id' => $b['entity_id'],
            /*
             * PORTAL-RELATIVE (REG-011). An alert is tenant-wide and its recipients are not all in
             * the same portal, so a fixed `/app/alerts` sent an agency operator into the advertiser
             * portal — and once that tree was guarded, into a refusal. The client resolves this
             * against whichever portal the reader is in; rows written before this still carry an
             * absolute path and are left alone by that resolution.
             */
            'action_url' => '/alerts',
        ]);

        return $n?->id !== null ? (string) $n->id : null;
    }

    /** Open a follow-up task for the breach (create-task capability). */
    private function openTask(AlertRule $rule, array $b): string
    {
        $task = Task::create([
            'tenant_id' => $rule->tenant_id,
            'project_id' => $b['project_id'] ?? $rule->project_id,
            'title' => $b['title'],
            'description' => $b['message'],
            'status' => 'todo',
            'priority' => $rule->severity === 'critical' ? 'urgent' : 'high',
            'meta' => ['source' => 'alert', 'alert_type' => $rule->type],
        ]);

        return (string) $task->id;
    }

    // ---- Resolve / snooze --------------------------------------------------------------------------------

    public function resolve(AlertEvent $event, ?Carbon $now = null): AlertEvent
    {
        $event->forceFill(['status' => 'resolved', 'resolved_at' => $now ?? Carbon::now(), 'snoozed_until' => null])->save();

        return $event;
    }

    public function snooze(AlertEvent $event, Carbon $until): AlertEvent
    {
        $event->forceFill(['status' => 'snoozed', 'snoozed_until' => $until])->save();

        return $event;
    }

    // ---- Signal handlers ---------------------------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    private function syncFailures(AlertRule $rule): array
    {
        // Latest sync run per connection; a failed latest run is an active problem.
        $latest = MetricSyncRun::query()
            ->whereIn('id', function ($q) {
                $q->selectRaw('DISTINCT ON (connection_id) id')
                    ->from('metric_sync_runs')
                    ->orderByRaw('connection_id, started_at DESC NULLS LAST, created_at DESC');
            })
            ->where('status', 'failed')
            ->get();

        return $latest->map(fn (MetricSyncRun $r) => [
            'entity_type' => ProviderConnection::class,
            'entity_id' => (string) $r->connection_id,
            'project_id' => $r->project_id ? (string) $r->project_id : null,
            'title' => 'Data sync failed',
            'message' => 'The latest sync for '.$r->provider.' failed'.($r->error ? ': '.$r->error : '.'),
            'context' => ['provider' => $r->provider, 'sync_run_id' => (string) $r->id, 'error' => $r->error],
        ])->all();
    }

    /** @return list<array<string,mixed>> */
    private function tokenExpiries(AlertRule $rule, Carbon $now): array
    {
        $days = (int) ($rule->threshold['days'] ?? 7);
        $cutoff = $now->copy()->addDays($days);

        return ProviderConnection::query()
            ->whereNotNull('token_expires_at')
            ->where('token_expires_at', '<=', $cutoff)
            ->get()
            ->map(fn (ProviderConnection $c) => [
                'entity_type' => ProviderConnection::class,
                'entity_id' => (string) $c->id,
                'project_id' => null,
                'title' => 'Connection token expiring',
                'message' => 'The '.$c->provider.' connection token expires '.optional($c->token_expires_at)->toDateString().'. Reconnect to avoid a sync gap.',
                'context' => ['provider' => $c->provider, 'expires_at' => optional($c->token_expires_at)->toIso8601String()],
            ])->all();
    }

    /** @return list<array<string,mixed>> */
    private function budgetRisks(AlertRule $rule, Carbon $now): array
    {
        $ratio = (float) ($rule->threshold['ratio'] ?? 0.9); // spend / budget threshold
        $from = $now->copy()->subDays(30);
        $out = [];

        UnifiedCampaign::query()->where('total_budget', '>', 0)
            ->when($rule->project_id, fn ($q) => $q->where('project_id', $rule->project_id))
            ->get()->each(function (UnifiedCampaign $c) use ($from, $now, $ratio, &$out) {
                $spend = (float) $this->totalsFor((string) $c->id, $from, $now)['spend'];
                $budget = (float) $c->total_budget;
                if ($budget > 0 && $spend / $budget >= $ratio) {
                    $out[] = [
                        'entity_type' => UnifiedCampaign::class,
                        'entity_id' => (string) $c->id,
                        'project_id' => (string) $c->project_id,
                        'title' => 'Budget at risk',
                        'message' => $c->name.' has spent '.round($spend / $budget * 100).'% of its budget.',
                        'context' => ['spend' => $spend, 'budget' => $budget, 'ratio' => round($spend / $budget, 4)],
                    ];
                }
            });

        return array_merge($out, $this->internalSpendLimits($rule, $now));
    }

    /**
     * BUDGET-GOVERNANCE-001 — the workspace's OWN limits, evaluated by the same rule that watches the
     * platforms'.
     *
     * One detector, deliberately. A second engine watching a second kind of budget would eventually
     * disagree with this one about the same campaign's spend, and the customer would meet both.
     *
     * The message says «internal limit» in as many words, because the two objects behave completely
     * differently: a platform budget stops delivery when it is exhausted; nothing stops an internal
     * one. An operator who reads a generic «budget at risk» and assumes the first will not go and
     * pause anything.
     *
     * The crossing is also written to `spend_limit_events`, whose unique (limit, threshold) index is
     * the audit trail AND the dedup: 80% is recorded once, with the figures as they stood, rather
     * than recomputed on every sweep for the rest of the period.
     *
     * @return list<array<string,mixed>>
     */
    private function internalSpendLimits(AlertRule $rule, Carbon $now): array
    {
        $out = [];

        SpendLimit::query()
            ->where('active', true)
            ->whereDate('starts_on', '<=', $now->toDateString())
            ->whereDate('ends_on', '>=', $now->toDateString())
            ->when($rule->project_id, fn ($q) => $q->where('project_id', $rule->project_id))
            ->get()
            ->each(function (SpendLimit $limit) use ($now, &$out): void {
                $reading = $this->limits->read($limit, $now->copy()->startOfDay());

                // No comparable figure is not a breach. It is the state where nothing can be said.
                if ($reading['utilisation'] === null) {
                    return;
                }

                $crossed = $this->highestCrossedThreshold($limit, (float) $reading['utilisation']);

                if ($crossed === null) {
                    return;
                }

                $recorded = SpendLimitEvent::query()->firstOrCreate(
                    ['spend_limit_id' => $limit->getKey(), 'threshold' => $crossed],
                    [
                        'tenant_id' => $limit->tenant_id,
                        'consumed' => $reading['consumed'],
                        'limit_amount' => $reading['amount'],
                        'currency' => $limit->currency,
                        'crossed_at' => $now,
                    ],
                );

                // Already announced. The ledger is the dedup; re-raising would be a second alert for
                // one event, which is the shape MAIL-006's cooldown exists to prevent.
                if (! $recorded->wasRecentlyCreated) {
                    return;
                }

                $out[] = [
                    'entity_type' => SpendLimit::class,
                    'entity_id' => (string) $limit->getKey(),
                    'project_id' => (string) $limit->project_id,
                    'title' => 'Internal spend limit at '.$crossed.'%',
                    'message' => 'This workspace’s own '.$limit->scope->value.' limit of '
                        .round((float) $reading['amount'], 2).' '.$limit->currency.' is '.$crossed
                        .'% used. CampaignsHub does not stop delivery — pause on the platform if that is the intent.',
                    'context' => [
                        'enforcement' => SpendLimit::ENFORCEMENT,
                        'scope' => $limit->scope->value,
                        'threshold' => $crossed,
                        'consumed' => $reading['consumed'],
                        'limit' => $reading['amount'],
                        'currency' => $limit->currency,
                        'projected_exhaustion' => $reading['projected_exhaustion'],
                    ],
                ];
            });

        return $out;
    }

    /** The highest configured threshold this utilisation has passed, or null for none. */
    private function highestCrossedThreshold(SpendLimit $limit, float $utilisation): ?int
    {
        $crossed = array_filter(
            $limit->thresholdPercents(),
            static fn (int $t): bool => $utilisation * 100 >= $t,
        );

        return $crossed === [] ? null : max($crossed);
    }

    /** @return list<array<string,mixed>> */
    private function noResults(AlertRule $rule, Carbon $now): array
    {
        $days = (int) ($rule->threshold['days'] ?? 3);
        $from = $now->copy()->subDays($days);
        $out = [];

        UnifiedCampaign::query()
            ->when($rule->project_id, fn ($q) => $q->where('project_id', $rule->project_id))
            ->get()->each(function (UnifiedCampaign $c) use ($from, $now, $days, &$out) {
                $totals = $this->totalsFor((string) $c->id, $from, $now);
                $spend = (float) $totals['spend'];
                $conv = (float) $totals['conversions'];
                if ($spend > 0 && $conv <= 0) {
                    $out[] = [
                        'entity_type' => UnifiedCampaign::class,
                        'entity_id' => (string) $c->id,
                        'project_id' => (string) $c->project_id,
                        'title' => 'Spending with no results',
                        'message' => $c->name.' spent '.round($spend, 2).' with no conversions in the last '.$days.' days.',
                        'context' => ['spend' => $spend, 'conversions' => $conv, 'days' => $days],
                    ];
                }
            });

        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function roasDrops(AlertRule $rule, Carbon $now): array
    {
        $pct = (float) ($rule->threshold['pct'] ?? 25); // % drop vs previous window
        $days = (int) ($rule->threshold['days'] ?? 7);
        $out = [];
        $curFrom = $now->copy()->subDays($days);
        $prevFrom = $now->copy()->subDays($days * 2);
        $prevTo = $now->copy()->subDays($days + 1);

        UnifiedCampaign::query()
            ->when($rule->project_id, fn ($q) => $q->where('project_id', $rule->project_id))
            ->get()->each(function (UnifiedCampaign $c) use ($curFrom, $now, $prevFrom, $prevTo, $pct, &$out) {
                $cur = $this->campaignRoas((string) $c->id, $curFrom, $now);
                $prev = $this->campaignRoas((string) $c->id, $prevFrom, $prevTo);
                if ($cur !== null && $prev !== null && $prev > 0 && ($prev - $cur) / $prev * 100 >= $pct) {
                    $out[] = [
                        'entity_type' => UnifiedCampaign::class,
                        'entity_id' => (string) $c->id,
                        'project_id' => (string) $c->project_id,
                        'title' => 'ROAS dropped',
                        'message' => $c->name.' ROAS fell from '.round($prev, 2).' to '.round($cur, 2).'.',
                        'context' => ['roas_current' => round($cur, 4), 'roas_previous' => round($prev, 4)],
                    ];
                }
            });

        return $out;
    }

    /**
     * One campaign's figures, from the SAME engine every screen reads (UNIFIED-001).
     *
     * These handlers used to sum `daily_metrics` themselves and divide revenue by spend inline. The
     * arithmetic agreed with {@see MetricsAggregator} on the day it was written and nothing held it
     * there — so an alert firing on a ROAS the dashboard never showed was one edit away, and the reader
     * who spotted the discrepancy would have had no way to tell which number was the real one. There is
     * one definition of spend, of conversions and of ROAS now, and alerts read it.
     *
     * The aggregator is scoped by campaign only, deliberately: this runs from the scheduler with no
     * request behind it and therefore no active project, and a campaign id already names exactly one
     * project. The tenant scope is live throughout — {@see evaluateAll()} sets it per tenant.
     *
     * @return array<string,float|null>
     */
    private function totalsFor(string $campaignId, Carbon $from, Carbon $to): array
    {
        return $this->metrics->acrossProjects()->forCampaign($campaignId)->totals($from, $to);
    }

    /**
     * A rule nobody evaluates, said out loud.
     *
     * `default => []` returned «no breaches», which is indistinguishable from «this rule is fine» —
     * the same shape as every other defect in this product's history: absence of evidence rendered
     * as evidence of absence. An unhandled type is a bug in this class, and now it says so where the
     * operator's logs will show it, while still returning no breaches so the sweep continues.
     */
    private function unevaluated(AlertRule $rule): array
    {
        Log::warning('Alert rule type has no evaluator; the rule can never fire.', [
            'alert_rule_id' => (string) $rule->id,
            'type' => $rule->type,
            'periodic_types' => self::PERIODIC,
        ]);

        return [];
    }

    /**
     * A cost per result that got worse — CPA or CPL, period over period.
     *
     * ## Why this cannot simply read `totals()['cpa']`
     *
     * `spend` is `COALESCE(SUM(value), 0)`, so a window whose money the provider withheld sums to
     * ZERO, and `cpa` is then `0 / conversions` = `0.00` — a real-looking figure for a cost nobody
     * knows. Comparing a withheld window against a converted one produces «CPA rose from 0.00 to
     * 50.00», which is not a cost increase; it is a rate arriving. The operator would be paged for
     * an FX gap.
     *
     * So both windows must be fully converted — `spend_withheld_rows === 0` on each — AND completely
     * covered, since a failed or stale provider leaves the same hole an unconverted one does. Either
     * way there is no verdict. This is the same rule the cards and the charts follow: a partial figure is not a
     * smaller figure, and nothing may be derived from it.
     *
     * A zero previous cost is also refused: every increase from zero is infinite, and «up ∞%» is not
     * a threshold anyone set.
     *
     * @param  'cpa'|'cpl'  $key
     */
    private function costPerIncreases(AlertRule $rule, Carbon $now, string $key, string $resultKey): array
    {
        $pct = (float) ($rule->threshold['pct'] ?? 25);
        $days = (int) ($rule->threshold['days'] ?? 7);
        $out = [];
        $curFrom = $now->copy()->subDays($days);
        $prevFrom = $now->copy()->subDays($days * 2);
        $prevTo = $now->copy()->subDays($days + 1);
        $label = strtoupper($key);

        UnifiedCampaign::query()
            ->when($rule->project_id, fn ($q) => $q->where('project_id', $rule->project_id))
            ->get()->each(function (UnifiedCampaign $c) use ($curFrom, $now, $prevFrom, $prevTo, $pct, $key, $resultKey, $label, &$out) {
                $cur = $this->totalsFor((string) $c->id, $curFrom, $now);
                $prev = $this->totalsFor((string) $c->id, $prevFrom, $prevTo);

                // Either window holding withheld money has no comparable cost — no verdict, no alert.
                /*
                 * AGGREGATION-TRUTH-001 widened this guard, and the reason is the paging.
                 *
                 * It refused a verdict when money was withheld for want of an exchange rate, which was
                 * right and too narrow: a provider whose sync FAILED produces exactly the same shape —
                 * a total missing a contributor that should be in it — and the comparison then reads
                 * «CPA rose from 12.00 to 50.00» when what actually happened is that a platform stopped
                 * reporting. The operator gets paged for a broken connector wearing a cost increase's
                 * name, which is the most expensive kind of false alarm: it is actionable, and every
                 * action it suggests is wrong.
                 *
                 * Coverage answers the general question — is this total the whole answer — so both
                 * windows must now be complete, not merely fully converted.
                 */
                $curComplete = ($cur['coverage']['state'] ?? 'complete') === 'complete';
                $prevComplete = ($prev['coverage']['state'] ?? 'complete') === 'complete';

                if (! $curComplete || ! $prevComplete) {
                    // A closure over each campaign — `return` is this iteration's «no verdict».
                    return;
                }

                if ((int) ($cur['spend_withheld_rows'] ?? 0) > 0 || (int) ($prev['spend_withheld_rows'] ?? 0) > 0) {
                    return;
                }

                $now_ = $cur[$key] ?? null;
                $was = $prev[$key] ?? null;

                if ($now_ === null || $was === null || $was <= 0) {
                    return;
                }

                $rise = ($now_ - $was) / $was * 100;

                if ($rise < $pct) {
                    return;
                }

                $out[] = [
                    'entity_type' => UnifiedCampaign::class,
                    'entity_id' => (string) $c->id,
                    'project_id' => (string) $c->project_id,
                    'title' => $label.' increased',
                    'message' => $c->name.' '.$label.' rose from '.round($was, 2).' to '.round($now_, 2)
                        .' ('.round($rise).'% up) on '.(int) ($cur[$resultKey] ?? 0).' '.$resultKey.'.',
                    'context' => [
                        $key.'_current' => round($now_, 4),
                        $key.'_previous' => round($was, 4),
                        'rise_pct' => round($rise, 2),
                        $resultKey => (int) ($cur[$resultKey] ?? 0),
                    ],
                ];
            });

        return $out;
    }

    /** ROAS (revenue / spend) for a campaign over a date range, or null when there was no spend. */
    private function campaignRoas(string $campaignId, Carbon $from, Carbon $to): ?float
    {
        return $this->totalsFor($campaignId, $from, $to)['roas'];
    }
}
