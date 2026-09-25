<?php

declare(strict_types=1);

namespace App\Domains\Projects\Services;

use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * PORTFOLIO-SCOPE-001 — «جميع المشاريع» as a deliberate product, never as a fallback.
 *
 * ## The distinction this exists to make
 *
 * A PROJECT scope answers about one client. A PORTFOLIO scope answers about the agency. The state
 * that must not exist is the middle one — a surface that widens to every project because nobody
 * chose one, so a figure appears that no reader asked for and cannot attribute to anything.
 *
 * The product already had the correct half of that: with no project selected the analytics queries
 * do not run at all. Fail-closed, and right. What it did not have was the other half, so «all
 * projects» was UNAVAILABLE rather than explicit — and unavailable is not the same answer.
 *
 * ## The ceiling comes first
 *
 * A portfolio is bounded by the projects the READER may reach, and an empty ceiling means an empty
 * portfolio. Never «everything». That inversion — an absent scope read as an unlimited one — is what
 * `ClientScopeResolver` and `DigestScope` were each written to prevent, and an agency-wide endpoint
 * is the third and most tempting place to repeat it.
 *
 * `projects.view.all` is the positive grant for the whole estate, checked rather than inferred; every
 * other reader gets the projects they are an ACTIVE member of. That is the same rule
 * `ProjectController::reachable()` applies to the listing, deliberately, so two agency surfaces
 * cannot disagree about who may see what.
 *
 * ## Money is segmented, never added across currencies
 *
 * Summing 100 SAR and 100 USD into «200» is the classic portfolio lie, and it is worse than useless
 * because it looks precise. Spend is reported per currency with the number of projects reporting in
 * it, and `comparable` says plainly whether a single total would mean anything. There is
 * deliberately no `total` key: a shape that cannot express the lie is stronger than a rule against
 * telling it.
 *
 * ## And no second rulebook
 *
 * Attention states are `ProjectListSummary`'s — `no_accounts`, `never_synced`, `stale` — so the
 * count here and the badge on a project card cannot drift apart.
 */
final class PortfolioOverview
{
    public function __construct(private readonly ProjectListSummary $summary) {}

    /** @return array<string,mixed> */
    public function for(User $user, string $tenantId, Carbon $from, Carbon $to): array
    {
        $projects = $this->reachableProjects($user, $tenantId);

        if ($projects->isEmpty()) {
            return [
                'scope' => 'portfolio',
                'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
                'projects' => ['total' => 0, 'by_status' => [], 'items' => []],
                'spend' => ['by_currency' => [], 'comparable' => true],
                'attention' => ['total' => 0, 'by_state' => []],
            ];
        }

        $summaries = $this->summary->for($projects);

        $byStatus = [];
        $attention = [];
        $items = [];

        foreach ($projects as $project) {
            $id = (string) $project->getKey();
            $status = (string) $project->status;
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;

            $state = $summaries[$id]['attention'] ?? null;
            if ($state !== null) {
                $attention[$state] = ($attention[$state] ?? 0) + 1;
            }

            $items[] = [
                'id' => $id,
                'name' => (string) $project->name,
                'status' => $status,
                'client_workspace_id' => (string) $project->client_workspace_id,
                'accounts' => $summaries[$id]['accounts'] ?? 0,
                'providers' => $summaries[$id]['providers'] ?? [],
                'data_last_synced_at' => $summaries[$id]['data_last_synced_at'] ?? null,
                'attention' => $state,
            ];
        }

        return [
            /*
             * Stated in the payload, not inferred by the reader.
             *
             * Every surface that can be either scope has to say which one produced its numbers, and
             * a caller that forgets to draw the label still cannot claim this was one project's
             * figures — the answer carries its own scope.
             */
            'scope' => 'portfolio',
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'projects' => [
                'total' => count($items),
                'by_status' => $byStatus,
                'items' => $items,
            ],
            'spend' => $this->spendByCurrency($projects->modelKeys(), $tenantId, $from, $to),
            'attention' => ['total' => array_sum($attention), 'by_state' => $attention],
        ];
    }

    /**
     * The projects this reader may be answered about.
     *
     * @return Collection<int,Project>
     */
    private function reachableProjects(User $user, string $tenantId)
    {
        $query = Project::query()->where('tenant_id', $tenantId);

        /*
         * Unrestricted access is a POSITIVE grant. Inferring it from the absence of memberships is
         * the exact inversion this product has already paid for once.
         */
        if (! $user->hasPermission('projects.view.all')) {
            $reachable = ProjectMembership::query()
                ->where('user_id', $user->getKey())
                ->where('status', 'active')
                ->pluck('project_id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->all();

            $query->whereIn('id', $reachable);
        }

        return $query->orderBy('name')->get();
    }

    /**
     * Spend per currency, and whether a single total would mean anything.
     *
     * @param  list<string>  $projectIds
     * @return array<string,mixed>
     */
    private function spendByCurrency(array $projectIds, string $tenantId, Carbon $from, Carbon $to): array
    {
        $rows = DB::table('daily_metrics')
            ->where('tenant_id', $tenantId)
            ->whereIn('project_id', $projectIds)
            ->where('metric_key', 'spend')
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('project_currency')
            ->selectRaw('project_currency, sum(converted_amount) as spend, count(distinct project_id) as projects')
            ->get();

        $byCurrency = $rows
            ->filter(static fn (object $r): bool => is_string($r->project_currency) && $r->project_currency !== '')
            ->map(static fn (object $r): array => [
                'currency' => (string) $r->project_currency,
                'spend' => round((float) $r->spend, 2),
                'projects' => (int) $r->projects,
            ])
            ->sortBy('currency')
            ->values()
            ->all();

        return [
            'by_currency' => $byCurrency,
            /*
             * `comparable` is a fact about the estate, not a suggestion.
             *
             * One currency across every reporting project means a reader may add these figures up
             * themselves and be right. Two means they may not, and no exchange rate was supplied to
             * make them wrong quietly.
             */
            'comparable' => count($byCurrency) <= 1,
        ];
    }
}
