<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Commerce\Services\StoreSyncQueue;
use App\Domains\Integrations\Actions\LinkConfirmedAccount;
use App\Domains\Integrations\Exceptions\AccountAssignedElsewhere;
use App\Domains\Integrations\Measurement\Jobs\SyncMeasurementPropertyJob;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\PendingProjectLink;
use App\Domains\Integrations\Services\SourceLinkDirectory;
use App\Domains\Integrations\Support\ProviderErrorText;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Subscriptions\Exceptions\PlanLimitReached;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * INTEG-PROJECT-LINK-STORES-GA4-001 (Owner 2026-11-06) — the step after the platform's own page, for ONE
 * project and ITS OWN connection, when the source is a store or a GA4 property:
 *
 *   GET  /api/v1/projects/{project}/integrations/link/{connection}/sources?q=&page=
 *   POST /api/v1/projects/{project}/integrations/link/{connection}/sources   { external_id }
 *
 * The first asks the platform and stores nothing. The second stores exactly the confirmed store or property
 * and binds it to this project — `ecommerce` for a store, `analytics` for a property — through the same
 * {@see LinkConfirmedAccount} an ad account ends its journey in, so the tenant and client-workspace fences,
 * the project fence, the one-project-per-real-source refusal and the «row keeps its id» re-link are not
 * restated here.
 *
 * ## Whose connection
 *
 * Only the connection this project's own sign-in opened is served. A connection of another tenant, of
 * another project, a workspace-wide one, a removed one or an advertising one is a 404 — indistinguishable
 * from one that does not exist.
 *
 * ## What the reader is told about other projects
 *
 * `linked: elsewhere` on a row, and a 409 on confirm. Never which project.
 */
final class ProjectLinkSourceController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ProjectContext $project,
        private readonly SourceLinkDirectory $directory,
    ) {}

    public function index(Request $request, string $project, string $connection): JsonResponse
    {
        abort_unless($request->user()->hasPermission('integrations.connect'), 403);
        [$model, $shape] = $this->connectionOfThisProject($connection);

        $validated = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
        ]);

        try {
            $page = $this->directory->page($model, $validated['q'] ?? null, (int) ($validated['page'] ?? 1));
        } catch (Throwable $e) {
            return $this->refused($e);
        }

        $taken = $this->linkStates((string) $model->tenant_id, (string) $model->provider, $shape['account_type'], array_column($page['items'], 'external_id'));

        return ApiResponse::success([
            'kind' => $shape['kind'],
            'sources' => array_map(static fn (array $s): array => [
                'external_id' => $s['external_id'],
                'name' => $s['name'],
                // A store's domain, a property's Analytics account: the second line that tells two names apart.
                'detail' => $s['detail'] ?? null,
                'currency' => $s['currency'],
                // `here` — already linked to THIS project; `elsewhere` — feeds another project (never named here).
                'linked' => $taken[$s['external_id']] ?? null,
            ], $page['items']),
            'total' => $page['total'],
            'page' => $page['page'],
            'per_page' => $page['per_page'],
        ], __('api.ok'));
    }

    public function confirm(Request $request, LinkConfirmedAccount $link, AuditLogger $audit, string $project, string $connection): JsonResponse
    {
        abort_unless($request->user()->hasPermission('integrations.connect'), 403);
        [$model, $shape] = $this->connectionOfThisProject($connection);
        abort_unless($model->status === 'connected', 422, 'This connection is not authorised.');

        $validated = $request->validate(['external_id' => ['required', 'string', 'max:191']]);

        try {
            $listed = $this->directory->find($model, $validated['external_id']);
        } catch (Throwable $e) {
            return $this->refused($e);
        }
        abort_if($listed === null, 422, 'The platform did not list this source for this sign-in.');

        $projectModel = Project::findOrFail((string) $this->project->projectId());

        try {
            $result = $link->execute($model, $projectModel, $listed, $shape['account_type'], $shape['purpose']);
        } catch (AccountAssignedElsewhere $e) {
            return ApiResponse::error($e->getMessage(), meta: $e->meta(), status: 409);
        } catch (PlanLimitReached $e) {
            return ApiResponse::error($e->getMessage(), meta: $e->meta(), status: 422);
        }

        // The journey is finished: nothing is left waiting to be resumed, and the listing is not kept.
        PendingProjectLink::forget((string) $model->getKey());
        $this->directory->forget($model);

        /*
         * The first read, once the binding is committed — the same one the older assignment queued. A source this
         * project already held (a second confirm) is not a reason to read it again.
         */
        if ($result['created'] || $result['relinked']) {
            $shape['kind'] === 'commerce'
                ? app(StoreSyncQueue::class)->queue($result['account'], StoreSyncQueue::BACKFILL_DAYS, 'assignment')
                : SyncMeasurementPropertyJob::dispatch((string) $result['account']->getKey(), (int) config('integrations.first_sync.backfill_days', 30));
        }

        $audit->log(
            action: $result['relinked'] ? 'integration.source.relinked_to_project_connection' : 'integration.source.confirmed',
            entityType: Project::class,
            entityId: (string) $projectModel->getKey(),
            after: [
                'provider' => (string) $model->provider,
                'connection' => (string) $model->getKey(),
                'kind' => $shape['kind'],
                'accounts' => [(string) $result['account']->getKey()],
            ],
        );

        return ApiResponse::success([
            'account_id' => (string) $result['account']->getKey(),
            'relinked' => $result['relinked'],
        ], 'Source linked.', status: $result['created'] ? 201 : 200);
    }

    /** @return array{0: ProviderConnection, 1: array{kind:string, account_type:string, purpose:string}} */
    private function connectionOfThisProject(string $connection): array
    {
        $model = ProviderConnection::withoutGlobalScopes()
            ->where('tenant_id', (string) $this->tenant->tenantId())
            ->where('id', $connection)
            ->where('project_id', (string) $this->project->projectId())
            ->whereNull('removed_at')
            ->first();
        $shape = $model === null ? null : SourceLinkDirectory::shapeOf((string) $model->provider);
        abort_if($model === null || $shape === null, 404, 'Connection not found.');

        return [$model, $shape];
    }

    /**
     * Which of the listed sources are already linked — to this project (`here`) or to another (`elsewhere`),
     * by the REAL store or property id across every connection of the tenant, which is how the engine refuses
     * a second project.
     *
     * @param  list<string>  $externalIds
     * @return array<string,string>
     */
    private function linkStates(string $tenantId, string $provider, string $accountType, array $externalIds): array
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
            ->where('external_accounts.account_type', $accountType)
            ->where('external_accounts.status', '!=', 'superseded')
            ->whereIn('external_accounts.external_id', $externalIds)
            ->get(['external_accounts.external_id as external_id', 'project_integration_bindings.project_id as project_id'])
            ->each(function (object $row) use (&$states, $projectId): void {
                $mine = (string) $row->project_id === $projectId;
                $states[(string) $row->external_id] = $mine || ($states[(string) $row->external_id] ?? null) === 'here' ? 'here' : 'elsewhere';
            });

        return $states;
    }

    private function refused(Throwable $e): JsonResponse
    {
        // A refusal of ours (a fence, a validation) is not the platform's and is not dressed up as one.
        if ($e instanceof HttpExceptionInterface) {
            throw $e;
        }

        return ApiResponse::error(
            'The platform refused the request.',
            errors: ['provider' => [ProviderErrorText::forDisplay($e->getMessage())]],
            meta: ['status' => 'provider_refused'],
            status: 502,
        );
    }
}
