<?php

declare(strict_types=1);

namespace App\Domains\Funding\Services;

use App\Domains\Audit\AuditLogger;
use App\Domains\Funding\Models\FundingPayment;
use App\Domains\Funding\Models\ProjectFunding;
use App\Domains\Funding\Support\BudgetFx;
use App\Domains\Metrics\Enums\SpendLimitScope;
use App\Domains\Metrics\Models\SpendLimit;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ONE-BUDGET-001 — the spend limits become part of the approved budget, automatically, without deleting anything.
 *
 * For every limit not yet linked (`project_funding_id` null), oldest decision first:
 *
 *  - the project HAS an approved budget → the limit is linked to it as one of its thresholds (its scope — project,
 *    platform, account or campaign — and its own alert steps are kept);
 *  - the project has none and the limit is an ACTIVE project-scope limit → it becomes the project's budget: a budget row
 *    for the limit's period and one `initial` ledger entry of the limit's amount (converted to SAR through `BudgetFx`;
 *    with no rate it is skipped, never guessed), then linked;
 *  - otherwise (a platform / account / campaign limit, or an inactive one, on a project with no budget) it is left as it
 *    is and counted as «needs a budget» — a platform number is never turned into a project budget.
 *
 * Linking writes `project_funding_id`, `converted_at` and `legacy_snapshot` (the row exactly as it was) and nothing
 * else; the row, its events and every other limit survive. Running it twice changes nothing the second time (linked rows
 * are skipped, and the budget entry carries the idempotency key `limit:<id>`). Dry run by default: it only counts.
 */
final class ConvertSpendLimits
{
    public function __construct(
        private readonly BudgetFx $fx,
        private readonly FundingSpendRefresher $refresher,
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{seen: int, linked: int, budgets_created: int, needs_budget: int, no_rate: int, no_client: int, already: int} */
    public function run(bool $apply): array
    {
        $out = ['seen' => 0, 'linked' => 0, 'budgets_created' => 0, 'needs_budget' => 0, 'no_rate' => 0, 'no_client' => 0, 'already' => 0];
        $out['already'] = SpendLimit::withoutGlobalScopes()->whereNotNull('project_funding_id')->count();

        // Projects that would get a budget in this run — so a dry run counts the second limit of a project as a link.
        $planned = [];

        SpendLimit::withoutGlobalScopes()->whereNull('project_funding_id')
            ->orderByDesc('active')->orderByDesc('ends_on')->orderBy('created_at')
            ->each(function (SpendLimit $limit) use ($apply, &$out, &$planned): void {
                $out['seen']++;
                $this->tenant->setTenantId((string) $limit->tenant_id);

                $funding = ProjectFunding::withoutGlobalScopes()->where('project_id', $limit->project_id)->whereNull('voided_at')->first();
                if ($funding === null && isset($planned[$limit->project_id])) {
                    $out['linked']++;

                    return;
                }
                if ($funding !== null) {
                    $out['linked']++;
                    if ($apply) {
                        $this->link($limit, $funding);
                    }

                    return;
                }

                if (! $limit->active || $limit->scope !== SpendLimitScope::Project) {
                    $out['needs_budget']++;

                    return;
                }

                $project = Project::withoutGlobalScopes()->find($limit->project_id);
                if ($project === null || $project->client_workspace_id === null) {
                    $out['no_client']++;

                    return;
                }
                $converted = $this->fx->convert((float) $limit->amount, (string) $limit->currency, 'SAR', Carbon::parse($limit->starts_on->toDateString()), (string) $limit->tenant_id);
                if ($converted === null) {
                    $out['no_rate']++;

                    return;
                }

                $planned[$limit->project_id] = true;
                $out['budgets_created']++;
                $out['linked']++;
                if ($apply) {
                    $this->link($limit, $this->budgetFrom($limit, $project, $converted));
                }
            });

        return $out;
    }

    /**
     * The automatic half, for one project: when it has an approved budget, its unlinked limits are linked to it. Never
     * creates a budget (that is `run(apply: true)`'s, after its dry run). Returns how many were linked.
     */
    public function linkProject(string $projectId): int
    {
        $funding = ProjectFunding::query()->where('project_id', $projectId)->whereNull('voided_at')->first();
        if ($funding === null) {
            return 0;
        }
        $limits = SpendLimit::query()->where('project_id', $projectId)->whereNull('project_funding_id')->get();
        foreach ($limits as $limit) {
            $this->link($limit, $funding);
        }

        return $limits->count();
    }

    private function link(SpendLimit $limit, ProjectFunding $funding): void
    {
        DB::transaction(function () use ($limit, $funding): void {
            $snapshot = $limit->getRawOriginal();
            unset($snapshot['legacy_snapshot']);
            DB::table('spend_limits')->where('id', $limit->id)->whereNull('project_funding_id')->update([
                'project_funding_id' => $funding->id,
                'converted_at' => now(),
                'legacy_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            ]);
            $this->audit->log(action: 'spend_limit.linked_to_budget', entityType: SpendLimit::class, entityId: (string) $limit->id,
                before: ['project_funding_id' => null], after: ['project_funding_id' => (string) $funding->id], tenantId: (string) $limit->tenant_id);
        });
    }

    /** @param array{amount: float, rate: float, rate_date: string|null, source: string|null} $converted */
    private function budgetFrom(SpendLimit $limit, Project $project, array $converted): ProjectFunding
    {
        return DB::transaction(function () use ($limit, $project, $converted): ProjectFunding {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ['funding:'.$project->id]);
            $steps = array_values(array_unique(array_merge(ProjectFunding::DEFAULT_THRESHOLDS, $limit->thresholdPercents())));
            sort($steps);
            $funding = ProjectFunding::withoutGlobalScopes()->firstOrCreate(
                ['project_id' => $project->id],
                ['tenant_id' => $limit->tenant_id, 'client_workspace_id' => $project->client_workspace_id, 'payment_method' => 'prepaid',
                    'starts_on' => $limit->starts_on->toDateString(), 'ends_on' => $limit->ends_on->toDateString(), 'alert_thresholds' => $steps],
            );
            FundingPayment::withoutGlobalScopes()->firstOrCreate(
                ['project_id' => $project->id, 'idempotency_key' => 'limit:'.$limit->id],
                [
                    'tenant_id' => $limit->tenant_id, 'client_workspace_id' => $project->client_workspace_id, 'kind' => 'initial',
                    'amount' => round((float) $limit->amount, 2), 'currency' => strtoupper((string) $limit->currency), 'amount_sar' => $converted['amount'],
                    'rate' => $converted['rate'], 'rate_date' => $converted['rate_date'] ?? $limit->starts_on->toDateString(),
                    'rate_source' => $converted['source'] ?? 'unknown', 'paid_on' => $limit->starts_on->toDateString(),
                    'reference' => 'limit:'.substr((string) $limit->id, 0, 8), 'note' => 'converted from a spend limit',
                    'meta' => ['source' => ['type' => 'spend_limit', 'spend_limit_id' => (string) $limit->id]],
                ],
            );
            $this->refresher->refresh($funding);
            $this->audit->log(action: 'funding.created_from_spend_limit', entityType: ProjectFunding::class, entityId: (string) $funding->id,
                after: ['spend_limit_id' => (string) $limit->id, 'amount_sar' => $converted['amount']], tenantId: (string) $limit->tenant_id);

            return $funding;
        });
    }
}
