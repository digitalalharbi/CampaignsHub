<?php

declare(strict_types=1);

namespace App\Domains\ClientWorkspaces\Http\Controllers\Internal;

use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\ClientWorkspaces\Services\ClientAccess;
use App\Domains\ClientWorkspaces\Services\ClientPortfolioStats;
use App\Domains\Projects\Models\Project;
use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Client portfolio + command center. ClientWorkspace is globally tenant-scoped (BelongsToTenant); on top of
 * that, ClientAccess narrows visibility to clients the user actually has access to (unless clients.view_all).
 * The command center never mixes agency-wide totals into a single client's page — every tab filters by client_id.
 */
final class ClientsController
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ClientAccess $access,
        private readonly ClientPortfolioStats $stats,
    ) {}

    /** GET /api/v1/app/clients — the tenant's client portfolio (access-scoped, richly filterable). */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->hasPermission('clients.view'), 403);

        $query = ClientWorkspace::query();
        $this->access->restrictQuery($query, $user);

        $query
            ->when($request->filled('status'), fn ($q) => $q->where('client_status', $request->string('status')))
            ->when($request->filled('service_level'), fn ($q) => $q->where('service_level', $request->string('service_level')))
            ->when($request->filled('industry'), fn ($q) => $q->where('industry', $request->string('industry')))
            ->when($request->filled('owner_id'), fn ($q) => $q->where('owner_id', $request->integer('owner_id')))
            ->when($request->boolean('needs_attention'), fn ($q) => $q->where('client_status', 'needs_attention'))
            ->when($request->filled('q'), fn ($q) => $q->where('name', 'like', '%'.$request->string('q').'%'));

        // Archived are hidden unless explicitly requested (archive is a pause, not a delete).
        if (! $request->boolean('include_archived')) {
            $query->whereNull('archived_at');
        }

        match ($request->string('sort')->toString()) {
            'name' => $query->orderBy('name'),
            'oldest' => $query->orderBy('created_at'),
            default => $query->orderByDesc('created_at'),
        };

        $page = $query->paginate(min($request->integer('per_page', 24), 100));

        /** @var list<ClientWorkspace> $items */
        $items = collect($page->items())->all();
        $statsMap = $this->stats->forClients(array_map(fn (ClientWorkspace $c) => (string) $c->id, $items));

        // Post-filters that depend on computed stats (open requests / active campaigns present).
        $rows = collect($items)->map(fn (ClientWorkspace $c) => $this->card($c, $statsMap[$c->id] ?? []));
        if ($request->boolean('has_open_requests')) {
            $rows = $rows->filter(fn ($r) => $r['open_requests'] > 0)->values();
        }
        if ($request->boolean('has_active_campaigns')) {
            $rows = $rows->filter(fn ($r) => $r['active_campaigns'] > 0)->values();
        }

        return response()->json([
            'data' => $rows->all(),
            'meta' => ['total' => $page->total(), 'per_page' => $page->perPage(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    /** GET /api/v1/app/clients/{client} — command-center detail, all tabs scoped to this client. */
    public function show(Request $request, string $client): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->hasPermission('clients.view'), 403);
        $c = $this->access->resolve($client);          // global tenant scope → 404 for other tenants
        $this->access->assertView($user, $c);          // membership-gated → 403 without access

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
            'classification' => $this->classification($c),
            'is_archived' => $c->isArchived(),
            'archived_at' => optional($c->archived_at)->toIso8601String(),
            'can' => [
                'update' => $user->hasPermission('clients.update'),
                'manage_settings' => $user->hasPermission('clients.manage_settings'),
                'manage_team' => $user->hasPermission('clients.manage_team'),
                'archive' => $user->hasPermission('clients.archive'),
                'view_analytics' => $user->hasPermission('clients.view_analytics'),
                'view_reports' => $user->hasPermission('clients.view_reports'),
                'manage_files' => $user->hasPermission('clients.manage_files'),
            ],
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
    private function classification(ClientWorkspace $c): array
    {
        return [
            'client_status' => $c->client_status,
            'service_level' => $c->service_level,
            'industry' => $c->industry,
            'owner_id' => $c->owner_id,
            'owner_name' => $c->owner?->name,
            'priority' => $c->priority,
            'default_currency' => $c->default_currency,
            'timezone' => $c->timezone,
            'language' => $c->language,
            'week_start' => $c->week_start,
        ];
    }

    /**
     * @param  array<string,mixed>  $stats
     * @return array<string,mixed>
     */
    private function card(ClientWorkspace $c, array $stats): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'client_status' => $c->client_status,
            'service_level' => $c->service_level,
            'industry' => $c->industry,
            'priority' => $c->priority,
            'owner_id' => $c->owner_id,
            'is_archived' => $c->isArchived(),
            'projects' => $stats['projects'] ?? 0,
            'active_campaigns' => $stats['active_campaigns'] ?? 0,
            'open_requests' => $stats['open_requests'] ?? 0,
            'alerts' => $stats['alerts'] ?? 0,
            'spend' => $stats['spend'] ?? null,
            'spend_currency_mode' => $stats['spend_currency_mode'] ?? 'none',
            'currency' => $stats['currency'] ?? null,
            'data_sources' => $stats['data_sources'] ?? [],
            'last_report_at' => $stats['last_report_at'] ?? null,
            'last_sync_at' => $stats['last_sync_at'] ?? null,
        ];
    }
}
