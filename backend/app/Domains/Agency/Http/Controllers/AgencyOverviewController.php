<?php

declare(strict_types=1);

namespace App\Domains\Agency\Http\Controllers;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\ClientWorkspaces\Services\ClientAccess;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Services\ProjectListSummary;
use App\Domains\Tenancy\Services\ClientScopeResolver;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * DASHBOARD-FIRST-SCREEN-001 — what the agency's reachable clients spent, got, and where.
 *
 * The agency dashboard's first screen counted things — clients, projects, campaigns, requests — and
 * said nothing about money, results, platforms, trend or freshness until the reader scrolled past
 * three charts. Those figures exist for every project through `MetricsAggregator`; nothing joined
 * them across the clients an operator reaches. This does, under the dashboard's own ceiling:
 *
 *   - the client set is `ClientAccess::restrictQuery`, the same bound `AgencyDashboardController`
 *     counts with, so a scoped operator's money covers exactly the clients their counts cover;
 *   - every figure is the aggregator's, read `forProjects(...)->acrossProjects()` — the tenant scope
 *     stays, the active-project bound is lifted by name, and demo policy, account visibility,
 *     money truth and coverage all travel with it unchanged;
 *   - the currency is `currencyBasis`: named when every row agrees, null when the rows mix or there
 *     are none, and the page prints bare figures rather than a unit nobody stated.
 *
 * The previous window is the same length ending the day before, so the page can compare — but only
 * where both windows' coverage allows it; that rule is the page's (`comparableWindows`), as on
 * Analytics, and the server does not pre-judge it.
 */
final class AgencyOverviewController extends Controller
{
    private const MAX_DAYS = 365;

    public function __construct(
        private readonly ClientAccess $access,
        private readonly ClientScopeResolver $scopes,
        private readonly MetricsAggregator $agg,
        private readonly ProjectListSummary $summary,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->hasPermission('clients.view'), 403);

        [$from, $to] = $this->range($request);
        $len = $from->diffInDays($to) + 1;
        $prevTo = $from->copy()->subDay();
        $prevFrom = $prevTo->copy()->subDays($len - 1);

        $clientQuery = ClientWorkspace::query()->whereNull('archived_at');
        $this->access->restrictQuery($clientQuery, $user);
        $clientIds = $clientQuery->pluck('id')->map(fn ($id) => (string) $id)->all();

        $projects = $clientIds === []
            ? collect()
            : Project::query()->whereIn('client_workspace_id', $clientIds)->get();
        $projectIds = $projects->map(fn (Project $p) => (string) $p->getKey())->all();

        $scope = [
            'client_count' => count($clientIds),
            'project_count' => count($projectIds),
            'is_restricted' => $this->scopes->reachableClientIds($user) !== null,
        ];
        $period = ['from' => $from->toDateString(), 'to' => $to->toDateString()];
        $previous = ['from' => $prevFrom->toDateString(), 'to' => $prevTo->toDateString()];

        if ($projectIds === []) {
            return ApiResponse::success([
                'scope' => $scope, 'period' => $period, 'previous_period' => $previous,
                'currency' => null, 'current' => null, 'previous' => null,
                'by_provider' => [], 'timeseries' => [], 'freshness' => ['last_synced_at' => null],
            ], 'Agency overview.');
        }

        $agg = $this->agg->forProjects($projectIds)->acrossProjects();

        /** @var array<string, array<string, mixed>> $summaries */
        $summaries = $this->summary->for($projects);
        $synced = collect($summaries)
            ->map(fn (array $s) => $s['data_last_synced_at'] ?? null)
            ->filter(fn ($v) => is_string($v) && $v !== '')
            ->sort()
            ->last();

        return ApiResponse::success([
            'scope' => $scope,
            'period' => $period,
            'previous_period' => $previous,
            'currency' => $agg->currencyBasis($from, $to)['currency'],
            'current' => $agg->totals($from, $to),
            'previous' => $agg->totals($prevFrom, $prevTo),
            'by_provider' => $agg->byProvider($from, $to),
            'timeseries' => $agg->timeseries($from, $to),
            'freshness' => ['last_synced_at' => is_string($synced) ? $synced : null],
        ], 'Agency overview.');
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function range(Request $request): array
    {
        $to = $request->filled('to') ? Carbon::parse($request->string('to')->toString())->startOfDay() : Carbon::today();
        $from = $request->filled('from') ? Carbon::parse($request->string('from')->toString())->startOfDay() : $to->copy()->subDays(29);

        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }
        if ($from->diffInDays($to) + 1 > self::MAX_DAYS) {
            $from = $to->copy()->subDays(self::MAX_DAYS - 1);
        }

        return [$from, $to];
    }
}
