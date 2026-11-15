<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Internal;

use App\Domains\Agents\Models\ActionProposal;
use App\Domains\Funding\Services\ProjectBudgetReader;
use App\Domains\Notifications\Engine\Contracts\EntitlementGate;
use App\Domains\Notifications\Engine\Contracts\UsageCounter;
use App\Domains\Notifications\Engine\Enums\EntitlementKey;
use App\Domains\Notifications\Followup\AutoCloser;
use App\Domains\Notifications\Followup\FollowupTasks;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * NOTIF-V2-INTERNAL-001 — the facts that have no single place in the code where they «happen», found by looking (every 10 min).
 *
 * - an ACTIVE project with no budget → `project.no_budget_for_period` (+ a «set budget» task); a budget covering few days →
 *   a «renew budget» task;
 * - the external pipeline's own log: a message held for review or for privacy → the matching event (+ a «review» task);
 * - the subscription log: a recipient unsubscribed / reduced / has not confirmed in 7 days;
 * - a pending recommendation → `recommendation.awaiting_approval` (+ a task);
 * - a plan limit at 80% or more → `plan.limit_nearing`.
 * Then the follow-up list is reconciled (auto-close) and snoozed tasks wake.
 *
 * Reading only. Each sweep raises an event once per fact (a short-lived marker on top of the engine's own per-day de-dup,
 * so a fact seen again after midnight is not announced twice). Nothing here writes to a budget, a campaign or a platform.
 */
final class InternalScan
{
    private const WINDOW_HOURS = 3;

    private const PLAN_LIMIT_RATIO = 0.8;

    public function __construct(
        private readonly InternalEvents $events,
        private readonly FollowupTasks $tasks,
        private readonly AutoCloser $closer,
        private readonly ProjectBudgetReader $budgets,
        private readonly EntitlementGate $gate,
        private readonly UsageCounter $usage,
        private readonly TenantContext $tenants,
    ) {}

    /** @return array<string, int> rows touched per sweep */
    public function run(?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $out = ['budgets' => 0, 'external' => 0, 'subscriptions' => 0, 'recommendations' => 0, 'plan' => 0, 'closed' => 0, 'woken' => 0];

        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            $tenantId = (string) $tenantId;
            $before = $this->tenants->tenantId();
            $this->tenants->setTenantId($tenantId);
            try {
                foreach (['budgets', 'external', 'subscriptions', 'recommendations', 'plan'] as $sweep) {
                    try {
                        $out[$sweep] += $this->{$sweep}($tenantId, $now);
                    } catch (Throwable $e) {
                        report($e);
                    }
                }
                $out['woken'] += $this->tasks->wake($tenantId, $now);
                $out['closed'] += $this->closer->reconcile($tenantId);
            } finally {
                $this->tenants->setTenantId($before);
            }
        }

        return $out;
    }

    private function budgets(string $tenantId, Carbon $now): int
    {
        $ids = ActiveProjects::liveIds($tenantId);
        $n = 0;
        foreach ($this->budgets->forProjects($ids, $now) as $projectId => $r) {
            if (($r['has_budget'] ?? false) !== true) {
                // The engine's own per-day de-dup makes this once a day; the task is the standing reminder.
                $this->events->raise('project.no_budget_for_period', $tenantId, (string) $projectId, objectType: 'project', objectId: (string) $projectId);
                $n++;

                continue;
            }
            $days = $r['cover_days'] ?? null;
            if ($days !== null && (int) $days <= AutoCloser::RENEW_WITHIN_DAYS && (float) ($r['remaining'] ?? 0) > 0) {
                $this->tasks->open($tenantId, 'renew_budget', (string) $projectId, ['days' => (int) $days, 'until' => (string) ($r['run_out_date'] ?? '')], 'project', (string) $projectId, null, null, $now->copy());
                $n++;
            }
        }

        return $n;
    }

    private function external(string $tenantId, Carbon $now): int
    {
        $n = 0;
        $rows = DB::table('notification_delivery_log')->where('tenant_id', $tenantId)->where('scope', 'external')
            ->whereIn('status', ['held_for_review', 'held_for_privacy'])->where('created_at', '>=', $now->copy()->subHours(self::WINDOW_HOURS))->orderBy('seq')->limit(500)->get();
        foreach ($rows as $row) {
            if ($row->project_id === null || ! $this->once('extlog|'.$row->id, 3 * 86400)) {
                continue;
            }
            $this->events->raise('external.'.$row->status, $tenantId, (string) $row->project_id, [
                'recipient' => (string) $row->recipient_address, 'reference' => (string) $row->reference,
            ], null, 'delivery', (string) $row->delivery_id);
            $n++;
        }

        return $n;
    }

    private function subscriptions(string $tenantId, Carbon $now): int
    {
        $map = ['unsubscribed' => 'external.recipient_unsubscribed', 'reduced' => 'external.recipient_reduced', 'no_confirmation_7d' => 'external.recipient_unconfirmed_7d'];
        $n = 0;
        $rows = DB::table('notification_subscription_log')->where('tenant_id', $tenantId)->whereIn('action', array_keys($map))
            ->where('created_at', '>=', $now->copy()->subHours(self::WINDOW_HOURS))->orderBy('seq')->limit(500)->get();
        foreach ($rows as $row) {
            if (! $this->once('sublog|'.$row->id, 3 * 86400)) {
                continue;
            }
            $detail = (array) json_decode((string) ($row->detail ?? '[]'), true);
            $recipient = $row->recipient_id === null ? null : DB::table('notification_external_recipients')->where('tenant_id', $tenantId)->where('id', $row->recipient_id)->first(['name', 'email']);
            $projectId = isset($detail['project_id']) ? (string) $detail['project_id'] : null;
            $this->events->raise($map[$row->action], $tenantId, $projectId, [
                'recipient' => (string) ($recipient->name ?? $recipient->email ?? ''),
                'date' => Carbon::parse((string) $row->created_at)->toDateString(),
            ], null, 'subscription_log', (string) $row->id);
            $n++;
        }

        return $n;
    }

    private function recommendations(string $tenantId, Carbon $now): int
    {
        $n = 0;
        $live = ActiveProjects::liveIds($tenantId);
        if ($live === []) {
            return 0;
        }
        $rows = ActionProposal::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('status', ActionProposal::PENDING)
            ->whereIn('project_id', $live)->orderBy('created_at')->limit(200)->get(['id', 'project_id']);
        foreach ($rows as $p) {
            // The task is the standing reminder; the notification is said once.
            $this->tasks->open($tenantId, 'decide_recommendation', (string) $p->project_id, [], 'proposal', (string) $p->getKey(), null, 'recommendation.awaiting_approval');
            if ($this->once('reco|'.$p->getKey(), 30 * 86400)) {
                $this->events->raise('recommendation.awaiting_approval', $tenantId, (string) $p->project_id, [], null, 'proposal', (string) $p->getKey());
                $n++;
            }
        }

        return $n;
    }

    private function plan(string $tenantId, Carbon $now): int
    {
        $n = 0;
        $checks = [
            ['external_recipients', UsageCounter::EXTERNAL_RECIPIENTS, EntitlementKey::External],
            ['monthly_messages', UsageCounter::EXTERNAL_MESSAGES, EntitlementKey::External],
            ['monthly_push', UsageCounter::PUSH_NOTIFICATIONS, EntitlementKey::Push],
        ];
        foreach ($checks as [$limitKey, $metric, $key]) {
            $cap = $this->gate->limit($tenantId, $limitKey);
            if ($cap === null || $cap <= 0 || ! $this->gate->allows($tenantId, $key)) {
                continue;
            }
            if ($this->usage->current($tenantId, $metric, $now) >= $cap * self::PLAN_LIMIT_RATIO && $this->once("plan|{$tenantId}|{$limitKey}|".$now->format('Y-m'), 35 * 86400)) {
                $this->events->raise('plan.limit_nearing', $tenantId, null, ['limit' => $limitKey], null, 'plan_limit', $limitKey, $now->format('Y-m'));
                $n++;
            }
        }

        return $n;
    }

    private function once(string $key, int $ttlSeconds): bool
    {
        return Cache::add('nv2:scan:'.sha1($key), 1, $ttlSeconds);
    }
}
