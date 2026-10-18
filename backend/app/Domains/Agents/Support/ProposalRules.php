<?php

declare(strict_types=1);

namespace App\Domains\Agents\Support;

use App\Domains\Agents\Models\ActionProposal;
use App\Domains\Campaigns\Models\ExternalAdSet;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Services\AccountAssignment;
use App\Domains\Projects\Models\Project;

/**
 * Every rule a proposal must satisfy, as codes.
 *
 * Run twice: when the proposal is written (a failing one is stored `blocked`, visible, never queued for
 * approval) and again, against fresh state, at the moment of approval — because the world moves between
 * the two (the account may have been unbound, the budget changed by hand).
 *
 * Codes rather than sentences: the screen words them in the reader's language, and a test asserts the
 * rule that fired.
 */
final class ProposalRules
{
    public function __construct(private readonly AccountAssignment $assignment) {}

    /**
     * @return list<string> the codes of every rule broken; empty means the proposal may stand
     */
    public function violations(ActionProposal $p): array
    {
        $out = [];

        $action = ProposalAction::tryFrom($p->action);
        if ($action === null) {
            // Not in the vocabulary: nothing else can be judged, and nothing may run.
            return ['action_not_allowed'];
        }

        if (! in_array($p->target_type, ['campaign', 'ad_set', 'ad'], true)) {
            $out[] = 'target_type_unknown';
        }

        $account = ExternalAccount::withoutGlobalScopes()->find($p->external_account_id);
        $project = Project::withoutGlobalScopes()->find($p->project_id);

        if ($account === null || $project === null
            || (string) $account->tenant_id !== (string) $p->tenant_id
            || (string) $project->tenant_id !== (string) $p->tenant_id) {
            return [...$out, 'account_not_in_tenant'];
        }

        if ($account->provider !== $p->provider) {
            $out[] = 'provider_mismatch';
        }

        // The account must be ACTIVELY bound to THIS project — the same fence every report applies.
        if ($this->assignment->projectIdFor($account) !== (string) $p->project_id || ! $this->assignment->isActivelyAssigned($account)) {
            $out[] = 'account_not_bound_to_project';
        }

        if ($action === ProposalAction::SetDailyBudget) {
            array_push($out, ...$this->budgetViolations($p, $account, $project));
        }

        return array_values(array_unique($out));
    }

    /** @return list<string> */
    private function budgetViolations(ActionProposal $p, ExternalAccount $account, Project $project): array
    {
        if (! in_array($p->target_type, ['campaign', 'ad_set'], true)) {
            return ['budget_target_not_supported'];
        }

        $new = $p->payload['daily_budget'] ?? null;
        if (! is_numeric($new) || (float) $new <= 0) {
            // A zero budget is a pause by another name; it must be asked for as one.
            return ['budget_invalid'];
        }
        $new = (float) $new;

        $row = $p->target_type === 'campaign'
            ? ExternalCampaign::withoutGlobalScopes()
                ->where('project_id', $p->project_id)->where('external_account_id', $account->getKey())
                ->where('external_id', $p->target_external_id)->first()
            : ExternalAdSet::withoutGlobalScopes()
                ->where('project_id', $p->project_id)->where('external_id', $p->target_external_id)->first();

        if ($row === null) {
            return ['target_not_found'];
        }

        $limits = $this->limitsFor($project);
        $out = [];

        $current = $row->daily_budget === null ? null : (float) $row->daily_budget;

        if ($current !== null && $new > $current) {
            if ($current <= 0 || (($new - $current) / $current) * 100 > $limits['max_increase_percent'] + 1e-9) {
                $out[] = 'increase_exceeds_cap';
            }
        } elseif ($current === null) {
            // A raise cannot be judged against a budget nobody knows; a lowering is safe either way.
            $out[] = 'current_budget_unknown';
        }

        if ($limits['daily_budget_ceiling'] !== null && $new > $limits['daily_budget_ceiling'] + 1e-9
            && ($current === null || $new > $current)) {
            $out[] = 'above_daily_ceiling';
        }

        return $out;
    }

    /** @return array{max_increase_percent: float, daily_budget_ceiling: float|null} */
    public function limitsFor(Project $project): array
    {
        $own = (array) (((array) $project->meta)['agent_limits'] ?? []);
        $base = (array) config('agents.limits');

        $percent = $own['max_increase_percent'] ?? $base['max_increase_percent'] ?? 20.0;
        $ceiling = $own['daily_budget_ceiling'] ?? $base['daily_budget_ceiling'] ?? null;

        return [
            'max_increase_percent' => max(0.0, (float) $percent),
            'daily_budget_ceiling' => is_numeric($ceiling) ? (float) $ceiling : null,
        ];
    }
}
