<?php

declare(strict_types=1);

namespace App\Domains\ClientWorkspaces\Http\Controllers\Internal;

use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Client portfolio + command center. ClientWorkspace is globally tenant-scoped (BelongsToTenant), so
 * every read is confined to the current tenant. The command center never mixes agency-wide totals into a
 * single client's page — every tab is filtered by client_id.
 */
final class ClientsController
{
    public function __construct(private readonly TenantContext $tenant) {}

    /** GET /api/v1/app/clients — the tenant's client portfolio. */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('clients.view'), 403);

        $query = ClientWorkspace::query()
            ->when($request->filled('status'), fn ($q) => $q->where('client_status', $request->string('status')))
            ->when($request->filled('service_level'), fn ($q) => $q->where('service_level', $request->string('service_level')))
            ->when($request->filled('q'), fn ($q) => $q->where('name', 'like', '%'.$request->string('q').'%'))
            ->orderByDesc('created_at');

        $page = $query->paginate(min($request->integer('per_page', 24), 100));

        return response()->json([
            'data' => collect($page->items())->map(fn (ClientWorkspace $c) => $this->card($c))->all(),
            'meta' => ['total' => $page->total(), 'per_page' => $page->perPage(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    /** GET /api/v1/app/clients/{client} — command-center detail, all tabs scoped to this client. */
    public function show(Request $request, string $client): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('clients.view'), 403);
        $c = ClientWorkspace::findOrFail($client); // global tenant scope → 404 for other tenants

        $projects = Project::where('client_workspace_id', $c->id)->orderByDesc('created_at')
            ->get(['id', 'name', 'status', 'created_at'])
            ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'status' => $p->status, 'created_at' => optional($p->created_at)->toIso8601String()]);

        $campaigns = UnifiedCampaign::where('client_workspace_id', $c->id)->orderByDesc('created_at')
            ->get(['id', 'project_id', 'name', 'objective', 'status', 'total_budget', 'budget_currency'])
            ->map(fn ($m) => ['id' => $m->id, 'project_id' => $m->project_id, 'name' => $m->name, 'objective' => $m->objective, 'status' => $m->status, 'budget' => $m->total_budget, 'currency' => $m->budget_currency]);

        $requests = ExternalRequest::where('tenant_id', $this->tenant->tenantId())->where('client_id', $c->id)
            ->with(['type', 'status'])->orderByDesc('submitted_at')->get()
            ->map(fn (ExternalRequest $r) => ['id' => $r->id, 'reference' => $r->reference, 'service' => $r->type->name_en, 'status' => $r->status->key, 'submitted_at' => optional($r->submitted_at)->toIso8601String()]);

        return response()->json(['data' => [
            'id' => $c->id,
            'name' => $c->name,
            'client_status' => $c->client_status,
            'service_level' => $c->service_level,
            'industry' => $c->industry,
            'source' => $c->client_source,
            'overview' => [
                'projects' => $projects->count(),
                'active_campaigns' => $campaigns->where('status', '!=', 'draft')->count(),
                'draft_campaigns' => $campaigns->where('status', 'draft')->count(),
                'open_requests' => $requests->whereNotIn('status', ['completed', 'rejected', 'cancelled', 'archived'])->count(),
            ],
            'projects' => $projects,
            'campaigns' => $campaigns,
            'requests' => $requests,
        ]]);
    }

    /** @return array<string,mixed> */
    private function card(ClientWorkspace $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'client_status' => $c->client_status,
            'service_level' => $c->service_level,
            'industry' => $c->industry,
            'projects' => Project::where('client_workspace_id', $c->id)->count(),
            'active_campaigns' => UnifiedCampaign::where('client_workspace_id', $c->id)->where('status', '!=', 'draft')->count(),
            'open_requests' => ExternalRequest::where('tenant_id', $this->tenant->tenantId())->where('client_id', $c->id)
                ->whereHas('status', fn ($q) => $q->where('is_terminal', false))->count(),
        ];
    }
}
