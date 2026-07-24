<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Campaigns\Actions\ImportExternalCampaigns;
use App\Domains\Integrations\Actions\EstablishSandboxConnection;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationSyncRun;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Sandbox\SandboxAdvertisingConnector;
use App\Domains\Projects\Concerns\ProjectScope;
use App\Domains\Projects\Context\ProjectContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Per-project integrations. All queries are project-scoped by ProjectContext (set by ResolveProject),
 * so a project only ever sees its own bindings — switching projects changes the result set.
 */
final class ProjectIntegrationController extends Controller
{
    public function __construct(private readonly ProjectContext $project) {}

    /** Bindings attached to the current project (project-scoped). */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('integrations.view'), 403);

        $bindings = ProjectIntegrationBinding::with('externalAccount.connection')->latest()->get()
            ->map(fn (ProjectIntegrationBinding $b) => $this->bindingArray($b));

        return ApiResponse::success($bindings, 'Project integrations retrieved.');
    }

    /** Establish a Sandbox connection and discover accounts (wizard step 1). */
    public function connect(Request $request, EstablishSandboxConnection $action): JsonResponse
    {
        abort_unless($request->user()->hasPermission('integrations.connect'), 403);

        $result = $action->execute(scope: $request->string('scope')->toString() ?: 'project_only');

        return ApiResponse::success([
            'connection' => ['id' => $result['connection']->id, 'name' => $result['connection']->connection_name, 'status' => $result['connection']->status],
            'accounts' => $result['accounts']->map(fn (ExternalAccount $a) => $this->accountArray($a))->values(),
        ], 'Sandbox connection established.', status: 201);
    }

    /** Bind a discovered external account to the current project (wizard step 2). */
    public function bind(Request $request, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()->hasPermission('integrations.connect'), 403);

        $validated = $request->validate([
            'external_account_id' => ['required', 'uuid', Rule::exists('external_accounts', 'id')->where('tenant_id', $request->user()->tenant_id)],
            'purpose' => ['required', Rule::in(['advertising', 'analytics', 'tag_management', 'ecommerce', 'tracking', 'conversion_api', 'reporting'])],
            'is_primary' => ['sometimes', 'boolean'],
        ]);

        /** @var ExternalAccount $account */
        $account = ExternalAccount::findOrFail($validated['external_account_id']);

        // Warn (409) if this account is already bound to a DIFFERENT project — sharing is allowed but
        // must be deliberate (confirm=true).
        $sharedElsewhere = ProjectIntegrationBinding::withoutGlobalScope(ProjectScope::class)
            ->where('external_account_id', $account->id)
            ->where('project_id', '!=', $this->project->projectId())
            ->exists();
        if ($sharedElsewhere && ! $request->boolean('confirm')) {
            return ApiResponse::error(
                'This account is already bound to another project. Re-send with confirm=true to share it.',
                meta: ['requires_confirmation' => true],
                status: 409,
            );
        }

        $binding = ProjectIntegrationBinding::create([
            // project_id auto-filled from ProjectContext by BelongsToProject.
            'external_account_id' => $account->id,
            'provider' => $account->provider,
            'purpose' => $validated['purpose'],
            'is_primary' => (bool) ($validated['is_primary'] ?? false),
            'campaign_management_enabled' => $validated['purpose'] === 'advertising',
            'tracking_enabled' => in_array($validated['purpose'], ['tracking', 'conversion_api'], true),
        ]);

        $audit->log(action: 'integration.bound', entityType: ProjectIntegrationBinding::class, entityId: (string) $binding->id, after: ['project' => $this->project->projectId(), 'account' => $account->external_id, 'purpose' => $binding->purpose]);

        return ApiResponse::success($this->bindingArray($binding->load('externalAccount.connection')), 'Account bound to project.', status: 201);
    }

    /**
     * Run a Sandbox sync for a binding; records an IntegrationSyncRun and imports the returned
     * campaigns into external_campaigns (idempotent upsert) — project-scoped.
     */
    public function sync(Request $request, ImportExternalCampaigns $import): JsonResponse
    {
        abort_unless($request->user()->hasPermission('integrations.view'), 403);

        $binding = (string) $request->route('binding');
        /** @var ProjectIntegrationBinding|null $model */
        $model = ProjectIntegrationBinding::with('externalAccount')->find($binding);
        abort_if($model === null, 404, 'Binding not found for this project.');

        $run = IntegrationSyncRun::create([
            'binding_id' => $model->id,
            'type' => 'campaigns',
            'status' => 'running',
            'started_at' => now(),
        ]);

        $result = (new SandboxAdvertisingConnector)->syncCampaigns($model->externalAccount->external_id);
        $imported = $import->execute($model->externalAccount, $result);

        $run->update([
            'status' => $result->success ? 'success' : 'failed',
            'records' => $result->count,
            'error' => $result->success ? null : $result->message,
            'finished_at' => now(),
        ]);
        $model->externalAccount->update(['last_synced_at' => now()]);

        return ApiResponse::success(
            ['sync_run_id' => $run->id, 'status' => $run->status, 'records' => $run->records, 'imported' => $imported],
            'Sync complete.',
        );
    }

    /** Detach a binding from the project. Does NOT revoke the underlying OAuth connection. */
    public function detach(Request $request, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()->hasPermission('integrations.connect'), 403);

        $binding = (string) $request->route('binding');
        /** @var ProjectIntegrationBinding|null $model */
        $model = ProjectIntegrationBinding::find($binding);
        abort_if($model === null, 404, 'Binding not found for this project.');

        $audit->log(action: 'integration.detached', entityType: ProjectIntegrationBinding::class, entityId: (string) $model->id, before: ['project' => $model->project_id, 'account' => $model->external_account_id]);
        $model->delete();

        return ApiResponse::success(null, 'Binding detached (connection kept).');
    }

    /** @return array<string,mixed> */
    private function bindingArray(ProjectIntegrationBinding $b): array
    {
        return [
            'id' => $b->id,
            'purpose' => $b->purpose,
            'provider' => $b->provider,
            'is_primary' => $b->is_primary,
            'is_active' => $b->is_active,
            'account' => $b->relationLoaded('externalAccount') && $b->externalAccount ? $this->accountArray($b->externalAccount) : null,
            'created_at' => optional($b->created_at)->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> */
    private function accountArray(ExternalAccount $a): array
    {
        return [
            'id' => $a->id,
            'account_type' => $a->account_type,
            'external_id' => $a->external_id,
            'name' => $a->name,
            'currency' => $a->currency,
            'timezone' => $a->timezone,
            'last_synced_at' => optional($a->last_synced_at)->toIso8601String(),
            'connection_status' => $a->relationLoaded('connection') && $a->connection ? $a->connection->status : null,
        ];
    }
}
