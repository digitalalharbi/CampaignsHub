<?php

declare(strict_types=1);

namespace App\Domains\Agents\Services;

use App\Domains\Agents\Models\ActionProposal;
use App\Domains\Agents\Models\AgentAutonomyGrant;
use App\Domains\Agents\Support\ProposalRules;
use App\Domains\Projects\Models\Project;
use Illuminate\Support\Carbon;

/**
 * AGENTS-AUTONOMY-001 — the trust ladder: autonomy is EARNED by a measured record, then GRANTED by an owner.
 *
 * Only two action classes can ever climb it, both of which reduce spend: pausing, and LOWERING a daily
 * budget. Resuming and raising a budget always wait for a person — there is no grant for them.
 *
 * A class is eligible when, for this project, the first decided proposal of that class is at least
 * `min_days` old, at least `min_decided` were decided, at least `min_approval_rate` of those were approved,
 * and none of the approved ones failed on the platform in the last 30 days. Eligibility is read again at
 * the moment of every automatic run, so a bad week withdraws it without anyone having to notice.
 */
final class TrustLadder
{
    public function __construct(private readonly ProposalRules $rules) {}

    public const CLASSES = ['pause', 'budget_decrease'];

    /** @return array{class: string, eligible: bool, decided: int, approved: int, rate: float|null, first_decided_at: string|null, days: int, failed_recent: int, needs: list<string>, granted: bool} */
    public function measure(Project $project, string $class): array
    {
        $rows = ActionProposal::withoutGlobalScopes()
            ->where('project_id', $project->getKey())
            ->whereNotNull('decided_at')
            ->whereIn('status', [ActionProposal::EXECUTED, ActionProposal::FAILED, ActionProposal::REJECTED, ActionProposal::BLOCKED, ActionProposal::APPROVED])
            ->where('decided_at', '>=', Carbon::now()->subDays(90))
            ->get()
            ->filter(fn (ActionProposal $p): bool => $this->classOf($p) === $class)
            // Automatic runs do not count towards earning autonomy: only a person's decisions do.
            ->filter(fn (ActionProposal $p): bool => ($p->decision_note ?? '') !== 'auto: trust ladder');

        $decided = $rows->count();
        $approved = $rows->whereIn('status', [ActionProposal::EXECUTED, ActionProposal::FAILED, ActionProposal::APPROVED])->count();
        $failed = $rows->where('status', ActionProposal::FAILED)->filter(fn (ActionProposal $p): bool => $p->decided_at?->gte(Carbon::now()->subDays(30)) ?? false)->count();
        $first = $rows->min('decided_at');
        $days = $first !== null ? (int) Carbon::parse($first)->diffInDays(Carbon::now()) : 0;
        $rate = $decided > 0 ? round($approved / $decided, 3) : null;

        $cfg = (array) config('agents.autonomy');
        $needs = [];
        if ($days < (int) $cfg['min_days']) {
            $needs[] = 'history_days';
        }
        if ($decided < (int) $cfg['min_decided']) {
            $needs[] = 'decided_count';
        }
        if ($rate === null || $rate < (float) $cfg['min_approval_rate']) {
            $needs[] = 'approval_rate';
        }
        if ($failed > 0) {
            $needs[] = 'recent_failures';
        }

        return [
            'class' => $class, 'eligible' => $needs === [], 'decided' => $decided, 'approved' => $approved, 'rate' => $rate,
            'first_decided_at' => $first !== null ? Carbon::parse($first)->toIso8601String() : null, 'days' => $days,
            'failed_recent' => $failed, 'needs' => $needs, 'granted' => $this->activeGrant($project, $class) !== null,
        ];
    }

    public function activeGrant(Project $project, string $class): ?AgentAutonomyGrant
    {
        return AgentAutonomyGrant::withoutGlobalScopes()->where('project_id', $project->getKey())
            ->where('action_class', $class)->whereNull('revoked_at')->latest('granted_at')->first();
    }

    /** The ladder class of a proposal, or null when it can never run on its own (resume, any raise). */
    public function classOf(ActionProposal $p): ?string
    {
        if ($p->action === 'pause') {
            return 'pause';
        }
        if ($p->action === 'set_daily_budget') {
            // Read from the stored proposal for history, else from the target now (for a new proposal).
            $current = $p->evidence['current_daily_budget'] ?? $this->rules->currentDailyBudget($p);
            $next = $p->payload['daily_budget'] ?? null;

            return is_numeric($current) && is_numeric($next) && (float) $next < (float) $current ? 'budget_decrease' : null;
        }

        return null;
    }
}
