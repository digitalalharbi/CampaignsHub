<?php

declare(strict_types=1);

namespace App\Domains\AI\Http\Controllers;

use App\Domains\AI\Enums\AIProvider;
use App\Domains\AI\Models\AIProviderCredential;
use App\Domains\AI\Resources\AICredentialResource;
use App\Domains\Audit\AuditLogger;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * BYOK key management. Keys are encrypted on write and NEVER returned — responses are masked.
 */
final class AICredentialController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('ai.view'), 403);

        return ApiResponse::success(
            AICredentialResource::collection(AIProviderCredential::query()->latest()->get()),
            'AI credentials retrieved (masked).',
        );
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()->hasPermission('ai.manage'), 403);

        $validated = $request->validate([
            'provider' => ['required', Rule::in(AIProvider::values())],
            'credential_scope' => ['required', Rule::in(['platform', 'tenant', 'client', 'project'])],
            'secret' => ['required', 'string', 'min:8'],
            'client_workspace_id' => ['nullable', 'uuid', Rule::exists('client_workspaces', 'id')->where('tenant_id', app(TenantContext::class)->tenantId())],
            'project_id' => ['nullable', 'uuid', Rule::exists('projects', 'id')->where('tenant_id', app(TenantContext::class)->tenantId())],
            'organization_id' => ['nullable', 'string', 'max:120'],
            'monthly_budget' => ['nullable', 'numeric', 'min:0'],
            'monthly_token_limit' => ['nullable', 'integer', 'min:0'],
            'allowed_models' => ['nullable', 'array'],
            'allowed_features' => ['nullable', 'array'],
        ]);

        $credential = new AIProviderCredential([
            'provider' => $validated['provider'],
            'credential_scope' => $validated['credential_scope'],
            'client_workspace_id' => $validated['client_workspace_id'] ?? null,
            'project_id' => $validated['project_id'] ?? null,
            'organization_id' => $validated['organization_id'] ?? null,
            'monthly_budget' => $validated['monthly_budget'] ?? null,
            'monthly_token_limit' => $validated['monthly_token_limit'] ?? null,
            'allowed_models' => $validated['allowed_models'] ?? null,
            'allowed_features' => $validated['allowed_features'] ?? null,
            'status' => 'active',
            'created_by' => Auth::id(),
        ]);
        $credential->setSecret($validated['secret']); // encrypts + keeps last_four only
        $credential->save();

        // Audit records metadata only — never the secret.
        $audit->log(
            action: 'ai.credential.created',
            entityType: AIProviderCredential::class,
            entityId: (string) $credential->id,
            after: ['provider' => $credential->provider, 'scope' => $credential->credential_scope],
        );

        return ApiResponse::success(new AICredentialResource($credential), 'AI credential stored.', status: 201);
    }

    /** Lightweight health check: for BYOK, verifies a key exists and is active (no external call). */
    public function health(Request $request, AIProviderCredential $credential): JsonResponse
    {
        abort_unless($request->user()->hasPermission('ai.view'), 403);

        $healthy = $credential->status === 'active' && $credential->revealSecret() !== '';
        $credential->update(['last_health_check_at' => now()]);

        return ApiResponse::success(
            ['healthy' => $healthy, 'provider' => $credential->provider, 'masked_key' => $credential->maskedKey()],
            $healthy ? 'Credential looks valid.' : 'Credential is inactive or missing.',
        );
    }
}
