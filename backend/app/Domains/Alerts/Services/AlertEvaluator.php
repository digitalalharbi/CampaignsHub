<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Services;

use App\Domains\Alerts\Models\AlertEvent;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Domains\Notifications\Services\NotificationDispatcher;
use App\Domains\Tasks\Models\Task;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
        private readonly TenantContext $tenants,
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
            default => [],
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
            $active->forceFill(['last_triggered_at' => $now, 'context' => $b['context'], 'status' => 'open'])->save();
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
            'context' => $b['context'],
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
        $from = $now->copy()->subDays(30)->toDateString();
        $out = [];

        UnifiedCampaign::query()->where('total_budget', '>', 0)
            ->when($rule->project_id, fn ($q) => $q->where('project_id', $rule->project_id))
            ->get()->each(function (UnifiedCampaign $c) use ($from, $now, $ratio, &$out) {
                $spend = (float) DB::table('daily_metrics')
                    ->where('unified_campaign_id', $c->id)->where('metric_key', 'spend')
                    ->whereBetween('metric_date', [$from, $now->toDateString()])->sum('value');
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

        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function noResults(AlertRule $rule, Carbon $now): array
    {
        $days = (int) ($rule->threshold['days'] ?? 3);
        $from = $now->copy()->subDays($days)->toDateString();
        $out = [];

        UnifiedCampaign::query()
            ->when($rule->project_id, fn ($q) => $q->where('project_id', $rule->project_id))
            ->get()->each(function (UnifiedCampaign $c) use ($from, $now, $days, &$out) {
                $agg = DB::table('daily_metrics')
                    ->where('unified_campaign_id', $c->id)
                    ->whereBetween('metric_date', [$from, $now->toDateString()])
                    ->selectRaw("COALESCE(SUM(value) FILTER (WHERE metric_key = 'spend'),0) AS spend")
                    ->selectRaw("COALESCE(SUM(value) FILTER (WHERE metric_key = 'conversions'),0) AS conv")
                    ->first();
                $spend = (float) ($agg->spend ?? 0);
                $conv = (float) ($agg->conv ?? 0);
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
        $curFrom = $now->copy()->subDays($days)->toDateString();
        $prevFrom = $now->copy()->subDays($days * 2)->toDateString();
        $prevTo = $now->copy()->subDays($days + 1)->toDateString();

        UnifiedCampaign::query()
            ->when($rule->project_id, fn ($q) => $q->where('project_id', $rule->project_id))
            ->get()->each(function (UnifiedCampaign $c) use ($curFrom, $now, $prevFrom, $prevTo, $pct, &$out) {
                $cur = $this->campaignRoas((string) $c->id, $curFrom, $now->toDateString());
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

    /** ROAS (revenue / spend) for a campaign over a date range, or null when there was no spend. */
    private function campaignRoas(string $campaignId, string $from, string $to): ?float
    {
        $r = DB::table('daily_metrics')->where('unified_campaign_id', $campaignId)
            ->whereBetween('metric_date', [$from, $to])
            ->selectRaw("COALESCE(SUM(value) FILTER (WHERE metric_key = 'revenue'),0) AS rev")
            ->selectRaw("COALESCE(SUM(value) FILTER (WHERE metric_key = 'spend'),0) AS spend")
            ->first();
        $spend = (float) ($r->spend ?? 0);

        return $spend > 0 ? (float) $r->rev / $spend : null;
    }
}
