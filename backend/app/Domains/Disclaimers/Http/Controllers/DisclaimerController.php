<?php

declare(strict_types=1);

namespace App\Domains\Disclaimers\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Disclaimers\Models\Disclaimer;
use App\Domains\Disclaimers\Services\DisclaimerResolver;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Central disclaimer / methodology copy. `resolve` serves the effective (merged) text to live surfaces
 * for the active project; the settings endpoints (settings.manage) read the system defaults + current
 * overrides and upsert a scoped override. Every edit is versioned and written to the audit log.
 */
final class DisclaimerController extends Controller
{
    public function __construct(private readonly DisclaimerResolver $resolver) {}

    /** Effective disclaimer for the active project (any project member). */
    public function resolve(Request $request, string $project): JsonResponse
    {
        $tenantId = (string) app(TenantContext::class)->tenantId();
        $projectId = app(ProjectContext::class)->projectId() ?? $project;
        $clientId = DB::table('projects')->where('id', $projectId)->value('client_workspace_id');

        return ApiResponse::success(
            $this->resolver->resolve($tenantId, $clientId, (string) $projectId),
            'Disclaimer resolved.',
        );
    }

    /** System defaults + all active overrides for the tenant (settings management view). */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('settings.manage'), 403);
        $tenantId = (string) app(TenantContext::class)->tenantId();

        $overrides = Disclaimer::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->get(['id', 'scope', 'scope_id', 'payload', 'version', 'is_active', 'effective_at', 'updated_at']);

        return ApiResponse::success([
            'defaults' => config('disclaimers'),
            'overrides' => $overrides,
        ], 'Disclaimer settings retrieved.');
    }

    /** Upsert a scoped override (organization/client/project). Versioned + audited. */
    public function update(Request $request, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()->hasPermission('settings.manage'), 403);
        $tenantId = (string) app(TenantContext::class)->tenantId();

        $data = $request->validate([
            'scope' => ['required', Rule::in(Disclaimer::SCOPES)],
            'scope_id' => ['nullable', 'uuid', 'required_unless:scope,organization'],
            'payload' => ['required', 'array'],
            'payload.locale_default' => ['nullable', 'in:ar,en'],
            'payload.enabled' => ['nullable', 'array'],
            'payload.sections' => ['nullable', 'array'],
            'is_active' => ['boolean'],
            'effective_at' => ['nullable', 'date'],
        ]);

        $scopeId = $data['scope'] === 'organization' ? null : $data['scope_id'];

        $existing = Disclaimer::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('scope', $data['scope'])
            ->where('scope_id', $scopeId)->first();

        $before = $existing?->only(['payload', 'is_active', 'effective_at', 'version']);

        $model = Disclaimer::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenantId, 'scope' => $data['scope'], 'scope_id' => $scopeId],
            [
                'payload' => $data['payload'],
                'is_active' => $data['is_active'] ?? true,
                'effective_at' => $data['effective_at'] ?? null,
                'version' => ($existing->version ?? 0) + 1,
                'updated_by' => $request->user()->id,
            ],
        );

        $audit->log(
            action: 'disclaimer.updated',
            entityType: 'disclaimer',
            entityId: (string) $model->id,
            before: $before,
            after: $model->only(['payload', 'is_active', 'effective_at', 'version']),
        );

        return ApiResponse::success($model->fresh(), 'Disclaimer override saved.');
    }

    /** Reset a scope to defaults by removing its override. Audited. */
    public function destroy(Request $request, string $scope, AuditLogger $audit, ?string $scopeId = null): JsonResponse
    {
        abort_unless($request->user()->hasPermission('settings.manage'), 403);
        abort_unless(in_array($scope, Disclaimer::SCOPES, true), 404);
        $tenantId = (string) app(TenantContext::class)->tenantId();

        $model = Disclaimer::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('scope', $scope)
            ->where('scope_id', $scope === 'organization' ? null : $scopeId)->first();
        abort_if($model === null, 404, 'No override for this scope.');

        $audit->log(
            action: 'disclaimer.reset',
            entityType: 'disclaimer',
            entityId: (string) $model->id,
            before: $model->only(['payload', 'version']),
        );
        $model->delete();

        return ApiResponse::success(null, 'Disclaimer override removed.');
    }
}
