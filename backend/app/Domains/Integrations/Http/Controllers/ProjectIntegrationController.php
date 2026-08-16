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
use App\Domains\Integrations\Services\AccountAssignment;
use App\Domains\Projects\Concerns\ProjectScope;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Subscriptions\Exceptions\PlanLimitReached;
use App\Domains\Subscriptions\Services\SubscriptionService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Per-project integrations. All queries are project-scoped by ProjectContext (set by ResolveProject),
 * so a project only ever sees its own bindings — switching projects changes the result set.
 */
final class ProjectIntegrationController extends Controller
{
    public function __construct(
        private readonly ProjectContext $project,
        private readonly AccountAssignment $assignment,
        private readonly SubscriptionService $subscriptions,
    ) {}

    /**
     * ORCH-100 §J — refuse when the plan's Connected Ad Accounts cap is already met.
     *
     * Called INSIDE the quota transaction, after the tenant row is locked, so the count it reads
     * cannot move underneath it. `withinLimit` is the same entitlement path the project, client and
     * team caps already use — this adds a metric to it rather than a second opinion about limits.
     */
    private function guardQuota(string $tenantId): void
    {
        $tenant = Tenant::withoutGlobalScopes()->findOrFail($tenantId);

        if ($this->subscriptions->withinLimit($tenant, 'ad_accounts')) {
            return;
        }

        $used = $this->subscriptions->usage($tenant, 'ad_accounts');
        $limit = $this->subscriptions->effectiveLimit($tenant, 'ad_accounts');

        throw new PlanLimitReached(
            'ad_accounts',
            $used,
            $limit,
            $limit === null
                ? 'Your plan does not allow another connected ad account.'
                : "Your plan allows {$limit} connected ad account(s) and {$used} are already connected.",
        );
    }

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
            'external_account_id' => ['required', 'uuid', Rule::exists('external_accounts', 'id')->where('tenant_id', app(TenantContext::class)->tenantId())],
            'purpose' => ['required', Rule::in(['advertising', 'analytics', 'tag_management', 'ecommerce', 'tracking', 'conversion_api', 'reporting'])],
            'is_primary' => ['sometimes', 'boolean'],
        ]);

        /** @var ExternalAccount $account */
        $account = ExternalAccount::findOrFail($validated['external_account_id']);

        $project = Project::findOrFail($this->project->projectId());

        /*
         * ORCH-100 §F — the client workspace fence.
         *
         * The tenant check above (in the validation rule) stops another COMPANY's account. It does
         * nothing about another CLIENT of the same agency, because both are the same tenant — and
         * mixing one client's advertising account into another client's project is the failure an
         * agency would never be able to explain.
         */
        abort_unless(
            $this->assignment->mayAssign($account, $project),
            403,
            'This account belongs to a different client workspace and cannot be assigned to this project.',
        );

        /*
         * ORCH-100 §I — no silent sharing.
         *
         * This used to allow the same account to feed a second project on `confirm=true`. There is
         * no requirement in this product for one advertising account to report into two projects,
         * and the failure mode if it is wrong is a client seeing another client's spend — so the
         * safer reading wins: one active assignment per account, and detaching is how you move it.
         *
         * The refusal names where it already lives, so the operator can act instead of guessing.
         */
        $activeElsewhere = ProjectIntegrationBinding::withoutGlobalScope(ProjectScope::class)
            ->where('external_account_id', $account->id)
            ->where('is_active', true)
            ->where('project_id', '!=', $project->id)
            ->first();

        if ($activeElsewhere !== null) {
            return ApiResponse::error(
                'This account is already connected to another project. Detach it there first.',
                meta: ['assigned_project_id' => (string) $activeElsewhere->project_id],
                status: 409,
            );
        }

        /*
         * ORCH-100 §J/§Y — the quota, counted and enforced inside one transaction.
         *
         * A check-then-insert is racy by construction: two confirmations for the last remaining slot
         * both read «one left» and both write. The row lock serialises them on the tenant, the count
         * is taken inside the lock, and the partial unique index on active bindings is the backstop
         * if anything ever reaches an insert twice.
         */
        try {
            $binding = DB::transaction(function () use ($account, $project, $validated) {
                DB::table('tenants')->where('id', $account->tenant_id)->lockForUpdate()->first();

                $existing = ProjectIntegrationBinding::withoutGlobalScope(ProjectScope::class)
                    ->where('external_account_id', $account->id)
                    ->where('project_id', $project->id)
                    ->where('is_active', true)
                    ->first();

                // Confirming twice is the same decision, not a second one — and must not cost a slot.
                if ($existing !== null) {
                    return $existing;
                }

                $this->guardQuota($account->tenant_id);

                return ProjectIntegrationBinding::create([
                    // project_id auto-filled from ProjectContext by BelongsToProject.
                    'external_account_id' => $account->id,
                    'client_workspace_id' => $project->client_workspace_id,
                    'provider' => $account->provider,
                    'purpose' => $validated['purpose'],
                    'is_primary' => (bool) ($validated['is_primary'] ?? false),
                    'is_active' => true,
                    'campaign_management_enabled' => $validated['purpose'] === 'advertising',
                    'tracking_enabled' => in_array($validated['purpose'], ['tracking', 'conversion_api'], true),
                ]);
            });
        } catch (PlanLimitReached $e) {
            return ApiResponse::error($e->getMessage(), meta: $e->meta(), status: 422);
        }

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
