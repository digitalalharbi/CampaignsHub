<?php

declare(strict_types=1);

namespace App\Domains\Agency\Http\Controllers;

use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\ClientWorkspaces\Services\ClientAccess;
use App\Domains\Projects\Models\Project;
use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Tenancy\Services\ClientScopeResolver;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The agency's own overview — across the clients this operator may actually reach (ADR 0002).
 *
 * Every figure is computed over the SAME scoped set the client list returns, so the dashboard can
 * never report totals that include a client the user cannot open. An account manager responsible for
 * three clients sees three clients' worth of numbers, not the agency's.
 *
 * Counts come from the existing tables — no separate aggregate store to drift out of date, and no
 * demo figures: an agency with no campaigns sees zero, which is the truth.
 */
final class AgencyDashboardController extends Controller
{
    public function __construct(
        private readonly ClientAccess $access,
        private readonly ClientScopeResolver $scopes,
    ) {}

    /** GET /api/v1/agency/dashboard */
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->hasPermission('clients.view'), 403);

        // The authoritative set. Everything below is derived from it, so nothing can widen past it.
        $clientQuery = ClientWorkspace::query()->whereNull('archived_at');
        $this->access->restrictQuery($clientQuery, $user);
        $clientIds = $clientQuery->pluck('id')->map(fn ($id) => (string) $id)->all();

        $projectIds = $clientIds === []
            ? []
            : Project::query()->whereIn('client_workspace_id', $clientIds)
                ->pluck('id')->map(fn ($id) => (string) $id)->all();

        return ApiResponse::success([
            'scope' => [
                // Says plainly what these numbers cover, so a partial view is never mistaken for the whole.
                'client_count' => count($clientIds),
                'is_restricted' => $this->scopes->reachableClientIds($user) !== null,
            ],
            'clients' => [
                'total' => count($clientIds),
                'active' => (clone $clientQuery)->where('client_status', 'active')->count(),
                'onboarding' => (clone $clientQuery)->where('client_status', 'onboarding')->count(),
                'needs_attention' => (clone $clientQuery)->where('client_status', 'needs_attention')->count(),
            ],
            'projects' => [
                'total' => count($projectIds),
                'active' => $projectIds === [] ? 0
                    : Project::query()->whereIn('id', $projectIds)->where('status', 'active')->count(),
            ],
            'campaigns' => $this->campaignSummary($clientIds),
            'requests' => $this->requestSummary($clientIds),
        ], 'Agency overview.');
    }

    /** @param  list<string>  $clientIds @return array<string,mixed> */
    private function campaignSummary(array $clientIds): array
    {
        if ($clientIds === []) {
            return ['total' => 0, 'active' => 0, 'paused' => 0, 'by_objective' => []];
        }

        // Campaigns carry the client directly, so the scoped set applies without going via projects.
        $base = UnifiedCampaign::query()->whereIn('client_workspace_id', $clientIds);

        return [
            'total' => (clone $base)->count(),
            'active' => (clone $base)->where('status', 'active')->count(),
            'paused' => (clone $base)->where('status', 'paused')->count(),
            // Objective-aware, because comparing awareness with sales in one number is misleading.
            'by_objective' => (clone $base)->selectRaw('objective, count(*) as c')
                ->groupBy('objective')->pluck('c', 'objective')->all(),
        ];
    }

    /** @param  list<string>  $clientIds @return array<string,mixed> */
    private function requestSummary(array $clientIds): array
    {
        if ($clientIds === []) {
            return ['open' => 0, 'awaiting_client' => 0];
        }

        // Requests name the client as `client_id`, and their status is a row rather than a string —
        // `is_terminal` is what closes one, so "open" cannot drift as new statuses are added.
        $base = ExternalRequest::query()->whereIn('client_id', $clientIds)->whereNull('archived_at');

        return [
            'open' => (clone $base)->whereHas('status', fn ($q) => $q->where('is_terminal', false))->count(),
            'awaiting_client' => (clone $base)->whereHas('status', fn ($q) => $q->where('key', 'client_review'))->count(),
        ];
    }
}
