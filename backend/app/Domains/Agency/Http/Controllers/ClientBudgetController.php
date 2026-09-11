<?php

declare(strict_types=1);

namespace App\Domains\Agency\Http\Controllers;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\ClientWorkspaces\Services\ClientAccess;
use App\Domains\Metrics\Services\ClientBudgetRollup;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Projects\Models\Project;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * BUDGET-GOVERNANCE-001 — the CLIENT rung, which is where an agency's budget question starts.
 *
 * The hierarchy is Client → Project → Platform → Account → Campaign. The four lower rungs are grains
 * of one query; this one is a ROLL-UP across a client's projects, and the agency dashboard carried
 * counts of clients, projects and campaigns with no money on it at all — so «which client is
 * overspending» could not be asked anywhere in the product.
 *
 * Reach is the agency's own: `ClientAccess::restrictQuery` is the same ceiling the dashboard and the
 * client list use, so a member scoped to three clients totals three clients and cannot widen past it
 * by calling this instead.
 */
final class ClientBudgetController extends Controller
{
    public function __construct(
        private readonly ClientAccess $access,
        private readonly ClientBudgetRollup $rollup,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->hasPermission('clients.view') && $user->hasPermission('budget.view'), 403);

        [$from, $to] = $this->range($request);

        $clientQuery = ClientWorkspace::query()->whereNull('archived_at');
        $this->access->restrictQuery($clientQuery, $user);
        $clients = $clientQuery->get(['id', 'name']);

        if ($clients->isEmpty()) {
            return ApiResponse::success([], 'Client budgets.', ['from' => $from->toDateString(), 'to' => $to->toDateString()]);
        }

        /*
         * One query for every project, grouped in memory — a per-client lookup would make this cost
         * grow with the number of clients, which is exactly what an agency has a lot of.
         */
        $projects = Project::query()
            ->whereIn('client_workspace_id', $clients->pluck('id')->all())
            ->get(['id', 'client_workspace_id', 'name']);

        /*
         * ONE pacing query for the whole screen, grouped by the `project_id` the rows now carry.
         *
         * This ran once per client, under a comment claiming the opposite — an agency with forty
         * clients paid forty times over for a screen that answers one question, and the per-project
         * decomposition below would have multiplied that again. The campaigns of every client are
         * paced together and split in memory afterwards.
         */
        $allProjectIds = $projects->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all();

        $pacingByProject = $allProjectIds === []
            ? collect()
            : collect(app(MetricsAggregator::class)->forProjects($allProjectIds)->budgetPacing($from, $to, Carbon::today()))
                ->groupBy(static fn (array $r): string => (string) ($r['project_id'] ?? ''));

        $projectsByClient = $projects->groupBy('client_workspace_id');

        $rows = [];

        foreach ($clients as $client) {
            $clientProjects = $projectsByClient[$client->id] ?? collect();

            /*
             * A client with no projects reports itself with nothing rather than being dropped: an
             * agency reading this list is checking every client, and one that silently vanishes is
             * indistinguishable from one that is fine.
             */
            $pacing = $clientProjects
                ->flatMap(static fn ($p): array => ($pacingByProject[(string) $p->id] ?? collect())->all())
                ->all();

            /*
             * BUDGET-GOVERNANCE-001 — the PROJECT rung, the middle of the owner's hierarchy.
             *
             * The client rung reported a project COUNT and nothing else, so a client pacing at 1.4×
             * named no project responsible for it. It is the SAME roll-up at a finer grain, so a
             * client's total and the projects printed beneath it cannot disagree about the money.
             *
             * A project with no campaigns is left out: it is not a share of the client's budget, and
             * a list of empty rows is what made the count useless in the first place.
             */
            $breakdown = $clientProjects
                ->map(fn ($p): array => [
                    'project_id' => (string) $p->id,
                    'project_name' => $p->name,
                ] + $this->rollup->of(($pacingByProject[(string) $p->id] ?? collect())->all()))
                ->filter(static fn (array $r): bool => $r['campaigns'] > 0)
                ->sortByDesc(static fn (array $r): float => (float) ($r['budget'] ?? -1))
                ->values()
                ->all();

            $rows[] = [
                'client_id' => (string) $client->id,
                'client_name' => $client->name,
                'projects' => $clientProjects->count(),
                'projects_breakdown' => $breakdown,
            ] + $this->rollup->of($pacing);
        }

        /* Largest committed budget first — «where is the money» precedes every other question here. */
        usort($rows, static fn (array $a, array $b): int => ($b['budget'] ?? -1) <=> ($a['budget'] ?? -1));

        return ApiResponse::success($rows, 'Client budgets.', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ]);
    }

    /** @return array{0: Carbon, 1: Carbon} the window, defaulting to the last 30 days like every metrics surface */
    private function range(Request $request): array
    {
        $to = $request->string('to')->toString() !== ''
            ? Carbon::parse($request->string('to')->toString())
            : Carbon::today();

        $from = $request->string('from')->toString() !== ''
            ? Carbon::parse($request->string('from')->toString())
            : $to->copy()->subDays(29);

        return $from->lessThanOrEqualTo($to) ? [$from, $to] : [$to, $from];
    }
}
