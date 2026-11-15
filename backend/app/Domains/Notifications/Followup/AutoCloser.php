<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Followup;

use App\Domains\Agents\Models\ActionProposal;
use App\Domains\Funding\Services\ProjectBudgetReader;
use App\Domains\Notifications\Followup\Models\FollowupTask;
use App\Domains\Notifications\Internal\ActiveProjects;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * NOTIF-V2-FOLLOWUP-001 — a task closes by itself when the thing it asked for happened (spec §6): a budget was added, a
 * sync succeeded, a held message was decided, a recommendation was decided. The assistant only READS these facts; the person
 * (or the sync) did the work.
 *
 * Runs after a budget / sync change and from the scheduled scan. Safe to run any number of times.
 */
final class AutoCloser
{
    /** A budget that covers more than this many days no longer needs renewing. */
    public const RENEW_WITHIN_DAYS = 5;

    public function __construct(
        private readonly FollowupTasks $tasks,
        private readonly ProjectBudgetReader $budgets,
        private readonly TenantContext $tenants,
    ) {}

    /** @return int how many tasks closed */
    public function reconcile(string $tenantId, ?string $projectId = null): int
    {
        $before = $this->tenants->tenantId();
        $this->tenants->setTenantId($tenantId);
        try {
            $closed = 0;
            $rows = FollowupTask::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereIn('status', ['open', 'snoozed'])
                ->when($projectId !== null, fn ($q) => $q->where('project_id', $projectId))->orderBy('opened_at')->limit(1000)->get();
            $readings = [];
            foreach ($rows as $task) {
                $why = $this->satisfied($task, $readings);
                if ($why !== null) {
                    $this->tasks->close($task, null, 'auto', $why);
                    $closed++;
                }
            }

            return $closed;
        } finally {
            $this->tenants->setTenantId($before);
        }
    }

    /** @param array<string, array<string, mixed>> $readings per-project budget reading memo */
    private function satisfied(FollowupTask $task, array &$readings): ?string
    {
        $tenantId = (string) $task->tenant_id;
        $projectId = $task->project_id === null ? null : (string) $task->project_id;
        if ($projectId !== null && ! ActiveProjects::isLive($tenantId, $projectId)) {
            return 'project_inactive';
        }

        return match ($task->kind) {
            'renew_budget' => $this->budgetCovers($projectId, $readings, true),
            'set_budget' => $this->budgetCovers($projectId, $readings, false),
            'relink_platform' => $this->synced($task),
            'review_held_message' => $this->decided($task),
            'decide_recommendation' => $this->recommendationDecided($task),
            default => null,
        };
    }

    /** @param array<string, array<string, mixed>> $readings */
    private function budgetCovers(?string $projectId, array &$readings, bool $needsCover): ?string
    {
        if ($projectId === null) {
            return null;
        }
        $r = $readings[$projectId] ??= $this->budgets->forProject($projectId);
        if (($r['has_budget'] ?? false) !== true) {
            return null;
        }
        if (! $needsCover) {
            return 'budget_added';
        }
        $remaining = $r['remaining'] ?? null;
        $days = $r['cover_days'] ?? null;
        $covered = ($remaining === null || (float) $remaining > 0) && ($days === null || (int) $days > self::RENEW_WITHIN_DAYS);

        return $covered ? 'budget_added' : null;
    }

    private function synced(FollowupTask $task): ?string
    {
        if ($task->project_id === null) {
            return null;
        }
        $ok = DB::table('integration_sync_runs')->where('tenant_id', $task->tenant_id)->where('project_id', $task->project_id)
            ->where('status', 'success')->where('finished_at', '>', $task->opened_at)->exists();

        return $ok ? 'sync_succeeded' : null;
    }

    private function decided(FollowupTask $task): ?string
    {
        if ($task->object_id === null) {
            return null;
        }
        $done = DB::table('notification_delivery_log')->where('tenant_id', $task->tenant_id)->where('delivery_id', $task->object_id)
            ->whereIn('status', ['sent', 'skipped', 'failed'])->exists();

        return $done ? 'message_decided' : null;
    }

    private function recommendationDecided(FollowupTask $task): ?string
    {
        $query = ActionProposal::withoutGlobalScopes()->where('tenant_id', $task->tenant_id);
        if ($task->object_id !== null) {
            return $query->whereKey($task->object_id)->where('status', ActionProposal::PENDING)->exists() ? null : 'recommendation_decided';
        }

        return $query->where('project_id', $task->project_id)->where('status', ActionProposal::PENDING)->exists() ? null : 'recommendation_decided';
    }
}
