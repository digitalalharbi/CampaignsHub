<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Integrations\Actions\LinkConfirmedAccount;
use App\Domains\Integrations\Exceptions\AccountAssignedElsewhere;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\PendingProjectLink;
use App\Domains\Integrations\Services\LinkDirectory;
use App\Domains\Integrations\Support\ProviderErrorText;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Subscriptions\Exceptions\PlanLimitReached;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * INTEG-ON-DEMAND-DISCOVERY-001 (Owner 2026-11-06) — the three steps after the platform sign-in, for ONE
 * project and ITS OWN connection: organisations → the chosen organisation's accounts → confirm one.
 *
 *   GET  /api/v1/projects/{project}/integrations/link/{connection}/organisations
 *   GET  /api/v1/projects/{project}/integrations/link/{connection}/accounts?organisation=&q=&page=
 *   POST /api/v1/projects/{project}/integrations/link/{connection}/accounts   { external_id, organisation }
 *
 * The first two ask the provider and store nothing. The third stores exactly the confirmed account and
 * binds it to this project.
 *
 * ## Whose connection
 *
 * Only the connection this project's own sign-in opened is served. A connection of another tenant, of
 * another project, a workspace-wide one, or a removed one is a 404 — indistinguishable from one that does
 * not exist — so nothing here can be used to look into, or bind from, somebody else's authorisation.
 */
final class ProjectLinkDirectoryController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ProjectContext $project,
        private readonly LinkDirectory $directory,
    ) {}

    public function organisations(Request $request, string $project, string $connection): JsonResponse
    {
        abort_unless($request->user()->hasPermission('integrations.connect'), 403);
        $model = $this->connectionOfThisProject($connection);

        try {
            $organisations = $this->directory->organisations($model);
        } catch (Throwable $e) {
            return $this->refused($e);
        }

        return ApiResponse::success([
            'organisations' => array_map(static fn (array $o): array => ['id' => $o['external_id'], 'name' => $o['name']], $organisations),
        ], __('api.ok'));
    }

    public function accounts(Request $request, string $project, string $connection): JsonResponse
    {
        abort_unless($request->user()->hasPermission('integrations.connect'), 403);
        $model = $this->connectionOfThisProject($connection);

        $validated = $request->validate([
            'organisation' => ['sometimes', 'nullable', 'string', 'max:191'],
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
        ]);

        try {
            $page = $this->directory->accounts($model, $validated['organisation'] ?? null, $validated['q'] ?? null, (int) ($validated['page'] ?? 1));
        } catch (Throwable $e) {
            return $this->refused($e);
        }

        $taken = $this->linkStates((string) $model->tenant_id, (string) $model->provider, array_column($page['items'], 'external_id'));

        return ApiResponse::success([
            'accounts' => array_map(fn (array $a): array => [
                'external_id' => $a['external_id'],
                'name' => $a['name'],
                'currency' => $a['currency'],
                'status' => $a['status'],
                // `here` — already linked to THIS project; `elsewhere` — feeds another project (never named here).
                'linked' => $taken[$a['external_id']] ?? null,
            ], $page['items']),
            'total' => $page['total'],
            'page' => $page['page'],
            'per_page' => $page['per_page'],
        ], __('api.ok'));
    }

    public function confirm(Request $request, LinkConfirmedAccount $link, AuditLogger $audit, string $project, string $connection): JsonResponse
    {
        abort_unless($request->user()->hasPermission('integrations.connect'), 403);
        $model = $this->connectionOfThisProject($connection);
        abort_unless($model->status === 'connected', 422, 'This connection is not authorised.');

        $validated = $request->validate([
            'external_id' => ['required', 'string', 'max:191'],
            'organisation' => ['sometimes', 'nullable', 'string', 'max:191'],
        ]);

        try {
            $listed = $this->directory->find($model, $validated['organisation'] ?? null, $validated['external_id']);
        } catch (Throwable $e) {
            return $this->refused($e);
        }
        abort_if($listed === null, 422, 'The platform did not list this account for this sign-in and organisation.');

        $projectModel = Project::findOrFail((string) $this->project->projectId());

        try {
            $result = $link->execute($model, $projectModel, $listed);
        } catch (AccountAssignedElsewhere $e) {
            return ApiResponse::error($e->getMessage(), meta: $e->meta(), status: 409);
        } catch (PlanLimitReached $e) {
            return ApiResponse::error($e->getMessage(), meta: $e->meta(), status: 422);
        }

        // The journey is finished: nothing is left waiting to be resumed, and the listing is not kept.
        PendingProjectLink::forget((string) $model->getKey());
        $this->directory->forget($model);

        $audit->log(
            action: $result['relinked'] ? 'integration.account.relinked_to_project_connection' : 'integration.selection.confirmed',
            entityType: Project::class,
            entityId: (string) $projectModel->getKey(),
            after: [
                'provider' => (string) $model->provider,
                'connection' => (string) $model->getKey(),
                'accounts' => [(string) $result['account']->getKey()],
            ],
        );

        return ApiResponse::success([
            'account_id' => (string) $result['account']->getKey(),
            'relinked' => $result['relinked'],
        ], 'Account linked.', status: $result['created'] ? 201 : 200);
    }

    private function connectionOfThisProject(string $connection): ProviderConnection
    {
        $model = ProviderConnection::withoutGlobalScopes()
            ->where('tenant_id', (string) $this->tenant->tenantId())
            ->where('id', $connection)
            ->where('project_id', (string) $this->project->projectId())
            ->whereNull('removed_at')
            ->first();
        abort_if($model === null, 404, 'Connection not found.');

        return $model;
    }

    /**
     * Which of the listed accounts are already linked — to this project (`here`) or to another (`elsewhere`),
     * by the REAL account id across every connection of the tenant, which is how the engine refuses a second project.
     *
     * @param  list<string>  $externalIds
     * @return array<string,string>
     */
    private function linkStates(string $tenantId, string $provider, array $externalIds): array
    {
        if ($externalIds === []) {
            return [];
        }

        $projectId = (string) $this->project->projectId();
        $states = [];

        ProjectIntegrationBinding::withoutGlobalScopes()
            ->toBase()
            ->join('external_accounts', 'external_accounts.id', '=', 'project_integration_bindings.external_account_id')
            ->where('project_integration_bindings.is_active', true)
            ->where('external_accounts.tenant_id', $tenantId)
            ->where('external_accounts.provider', $provider)
            ->where('external_accounts.account_type', 'ad_account')
            ->where('external_accounts.status', '!=', 'superseded')
            ->whereIn('external_accounts.external_id', $externalIds)
            ->get(['external_accounts.external_id as external_id', 'project_integration_bindings.project_id as project_id'])
            ->each(function (object $row) use (&$states, $projectId): void {
                $mine = (string) $row->project_id === $projectId;
                // `here` wins: the same project may hold it through an older connection, which is a re-link, not a refusal.
                $states[(string) $row->external_id] = $mine || ($states[(string) $row->external_id] ?? null) === 'here' ? 'here' : 'elsewhere';
            });

        return $states;
    }

    private function refused(Throwable $e): JsonResponse
    {
        return ApiResponse::error(
            'The platform refused the request.',
            errors: ['provider' => [ProviderErrorText::forDisplay($e->getMessage())]],
            meta: ['status' => 'provider_refused'],
            status: 502,
        );
    }
}
