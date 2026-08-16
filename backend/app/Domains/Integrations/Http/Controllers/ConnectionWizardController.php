<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Http\Controllers;

use App\Domains\Integrations\Catalogue\ProviderCatalogue;
use App\Domains\Integrations\Catalogue\ProviderHierarchy;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Services\AccountAssignment;
use App\Domains\Integrations\Services\ConnectionWizardState;
use App\Domains\Subscriptions\Services\SubscriptionService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ORCH-100 §3 §38 §39 — what the connection wizard reads between authorisation and confirmation.
 *
 * ## The step this product did not have
 *
 * A customer authorised Snapchat and 309 ad accounts arrived. The interface showed a green card and
 * a sync button; there was no way to say which of the 309 should feed which project, and nothing
 * asked. This controller is the missing middle: the inventory an authorisation produced, offered
 * back in a shape somebody can actually choose from.
 *
 * ## Why it is paginated and parent-scoped rather than a list
 *
 * 309 is not a hypothetical scale, it is the live one, and the next agency may have more. Sending
 * every discovered account in one response and rendering it flat is a defect at both ends — a large
 * payload and a DOM nobody can find anything in. Accounts are read under a chosen parent, filtered
 * and paged, so the size of what is drawn is bounded by the page and not by the authorisation.
 *
 * ## And nothing here is a connection
 *
 * Every account this returns is DISCOVERED inventory. `assigned` says whether it has been connected
 * to a project, `assignable` says whether it may be; neither is changed by reading. The plan usage
 * returned alongside is what the review step needs to tell somebody «4 / 5 after this», before a
 * binding exists rather than after.
 */
final class ConnectionWizardController extends Controller
{
    public function __construct(
        private readonly AccountAssignment $assignment,
        private readonly SubscriptionService $subscriptions,
        private readonly TenantContext $tenant,
        private readonly ConnectionWizardState $state,
    ) {}

    /**
     * GET /integrations/connections/resumable — authorisations waiting on a decision.
     *
     * ORCH-100 §39. Somebody who authorised Snapchat and then closed the tab has a live connection,
     * a valid token and 309 discovered accounts, and the product used to offer them nothing but the
     * connect button again — a second consent for an authorisation that never lapsed. This is what
     * «لديك ربط غير مكتمل» reads from.
     */
    public function resumable(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('integrations.view'), 403);

        $connections = ProviderConnection::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->tenantId())
            ->where('status', 'connected')
            ->get()
            ->map(fn (ProviderConnection $c): array => [
                'connection' => [
                    'id' => (string) $c->getKey(),
                    'provider' => $c->provider,
                    'label' => ProviderCatalogue::get($c->provider)->label,
                    'label_ar' => ProviderCatalogue::get($c->provider)->labelAr,
                    'client_workspace_id' => $c->client_workspace_id,
                ],
                ...$this->state->for($c),
            ])
            ->values();

        return ApiResponse::success([
            'connections' => $connections,
            'resumable' => $connections->where('resumable', true)->values(),
        ], 'Connection states retrieved.');
    }

    /**
     * GET /integrations/connections/{connection}/hierarchy — the parents this authorisation reaches.
     *
     * For a provider with no parent level this returns an empty list and `has_parent: false`, and the
     * wizard collapses the step rather than showing an invented one (ORCH-100 §4).
     */
    public function hierarchy(Request $request, string $connectionId): JsonResponse
    {
        abort_unless($request->user()->hasPermission('integrations.view'), 403);

        $connection = $this->connectionOr404($connectionId);
        $definition = ProviderCatalogue::get($connection->provider);

        $accounts = ExternalAccount::withoutGlobalScopes()
            ->where('provider_connection_id', $connection->getKey())
            ->where('tenant_id', $this->tenant->tenantId())
            ->where('account_type', 'ad_account');

        /*
         * Aggregated on the query builder rather than through the model, because the rows are counts
         * and not accounts — hydrating 309 `ExternalAccount` objects to group them in PHP is the
         * shape this endpoint exists to avoid.
         */
        $parents = (clone $accounts)
            ->toBase()
            ->selectRaw('parent_external_id, MAX(parent_name) AS parent_name, COUNT(*) AS account_count')
            ->whereNotNull('parent_external_id')
            ->groupBy('parent_external_id')
            ->orderByRaw('MAX(parent_name) NULLS LAST')
            ->get()
            ->map(function (object $row): array {
                $id = (string) $row->parent_external_id;
                $name = $row->parent_name;

                return [
                    'external_id' => $id,
                    // The id is a fallback label, never the primary one — people choose by name.
                    'name' => is_string($name) && $name !== '' ? $name : $id,
                    'account_count' => (int) $row->account_count,
                ];
            })
            ->values();

        return ApiResponse::success([
            'connection' => [
                'id' => (string) $connection->getKey(),
                'provider' => $connection->provider,
                'label' => $definition->label,
                'label_ar' => $definition->labelAr,
                'status' => $connection->status,
                'client_workspace_id' => $connection->client_workspace_id,
            ],
            'has_parent' => ProviderHierarchy::hasParent($connection->provider),
            'parent_label' => ProviderHierarchy::parent($connection->provider),
            'parents' => $parents,
            // Inventory, stated as inventory. The wizard shows «309 available · 0 connected», which
            // is the sentence that was missing.
            'discovered_count' => (clone $accounts)->count(),
            'assigned_count' => $this->assignment->assignedCountFor((string) $this->tenant->tenantId()),
            // Where this connection has got to, so the wizard opens on the right step rather than
            // at the beginning (ORCH-100 §39).
            'wizard' => $this->state->for($connection),
        ], 'Connection hierarchy retrieved.');
    }

    /**
     * GET /integrations/connections/{connection}/accounts — one page of discovered accounts.
     *
     * Narrowed by `parent` where the provider has parents, and by `q` for a search over the name and
     * the external id, because with hundreds of accounts scrolling is not a way to find one.
     */
    public function accounts(Request $request, string $connectionId): JsonResponse
    {
        abort_unless($request->user()->hasPermission('integrations.view'), 403);

        $connection = $this->connectionOr404($connectionId);

        $validated = $request->validate([
            'parent' => ['sometimes', 'nullable', 'string', 'max:120'],
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = ExternalAccount::withoutGlobalScopes()
            ->where('provider_connection_id', $connection->getKey())
            ->where('tenant_id', $this->tenant->tenantId())
            ->where('account_type', 'ad_account')
            ->when(
                ($validated['parent'] ?? null) !== null,
                fn ($q) => $q->where('parent_external_id', $validated['parent']),
            )
            ->when(($validated['q'] ?? null) !== null, function ($q) use ($validated): void {
                $term = '%'.str_replace('%', '\%', (string) $validated['q']).'%';
                $q->where(fn ($w) => $w->where('name', 'ilike', $term)->orWhere('external_id', 'ilike', $term));
            })
            ->orderBy('name')
            ->orderBy('external_id');

        $page = $query->paginate((int) ($validated['per_page'] ?? 25));

        // One query for the whole page rather than one per row: with 100 accounts on screen the
        // difference between these two is a hundred round trips.
        $assigned = ProjectIntegrationBinding::withoutGlobalScopes()
            ->whereIn('external_account_id', collect($page->items())->pluck('id'))
            ->where('is_active', true)
            ->pluck('project_id', 'external_account_id');

        return ApiResponse::success([
            'accounts' => collect($page->items())->map(fn (ExternalAccount $a): array => [
                'id' => (string) $a->id,
                'external_id' => (string) $a->external_id,
                'name' => (string) $a->name,
                'parent_external_id' => $a->parent_external_id,
                'parent_name' => $a->parent_name,
                'currency' => $a->currency,
                'timezone' => $a->timezone,
                'status' => $a->status,
                'assigned_project_id' => $assigned[$a->id] ?? null,
                'assigned' => isset($assigned[$a->id]),
                // Never synced is a real state and reads as one — not as a zero.
                'last_synced_at' => $a->last_synced_at?->toIso8601String(),
                'access_lost_at' => $a->access_lost_at?->toIso8601String(),
            ])->values(),
            'meta' => [
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
        ], 'Discovered accounts retrieved.');
    }

    /**
     * GET /integrations/plan-usage — what the review step tells somebody before they commit.
     *
     * «Connected Ad Accounts after confirmation: 4 / 5» has to be answerable BEFORE a binding
     * exists, or the customer discovers the cap by hitting it.
     */
    public function planUsage(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('integrations.view'), 403);

        $tenant = Tenant::withoutGlobalScopes()->findOrFail($this->tenant->tenantId());

        return ApiResponse::success(
            $this->subscriptions->usageSummary($tenant, ['ad_accounts', 'projects', 'clients', 'team_members']),
            'Plan usage retrieved.',
        );
    }

    /** A connection this tenant owns, or a 404 that says nothing about whose it is. */
    private function connectionOr404(string $connectionId): ProviderConnection
    {
        $connection = ProviderConnection::withoutGlobalScopes()
            ->where('id', $connectionId)
            ->where('tenant_id', $this->tenant->tenantId())
            ->first();

        abort_if($connection === null, 404, 'Connection not found.');

        return $connection;
    }
}
