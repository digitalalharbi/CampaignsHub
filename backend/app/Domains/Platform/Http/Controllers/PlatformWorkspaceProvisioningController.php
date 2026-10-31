<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Platform\Services\AgencyProvisioner;
use App\Domains\Subscriptions\Models\SubscriptionPlan;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * SUPERADMIN-WORKSPACE-001 — create a workspace and hand it to its owner, from the platform console.
 *
 * The console face of `agency:provision`: the same idempotent provisioner (one tenant per slug, a default workspace, the owner role,
 * an Active account), now with the workspace name, plan and modules the operator chose. The owner is invited, never created with a
 * password: they set their own through a single-use, 72-hour link. That link is returned to the operator here — and only here — so a
 * workspace can still be handed over when mail does not arrive; it is never logged or audited.
 */
final class PlatformWorkspaceProvisioningController extends Controller
{
    private const MODULES = ['paid_media', 'influencer_marketing'];

    public function __construct(
        private readonly AgencyProvisioner $provisioner,
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenants,
    ) {}

    /** POST /api/v1/admin/tenants */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_name' => ['required', 'string', 'min:2', 'max:120'],
            'workspace_name' => ['nullable', 'string', 'max:120'],
            'owner_email' => ['required', 'email:rfc', 'max:190'],
            'plan' => ['nullable', 'string', 'max:64'],
            'modules' => ['nullable', 'array', 'min:1'],
            'modules.*' => ['string', Rule::in(self::MODULES)],
        ]);

        $this->tenants->enterPlatformScope();

        if (($data['plan'] ?? null) !== null && ! SubscriptionPlan::query()->where('code', $data['plan'])->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['plan' => 'Unknown or inactive plan.']);
        }

        try {
            $result = $this->provisioner->provision(
                $data['company_name'],
                $data['owner_email'],
                $request->user(),
                workspaceName: $data['workspace_name'] ?? null,
                plan: $data['plan'] ?? null,
                modules: isset($data['modules']) ? array_values(array_unique($data['modules'])) : null,
                withLink: true,
            );
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['company_name' => $e->getMessage()]);
        }

        /** @var Tenant $tenant */
        $tenant = $result['tenant'];

        $this->audit->log(
            action: $result['created'] ? 'platform.tenant.provisioned' : 'platform.tenant.provision_repeated',
            entityType: Tenant::class,
            entityId: (string) $tenant->id,
            // The address and choices; the accept link is a credential and is never written anywhere.
            after: [
                'owner_email' => strtolower(trim($data['owner_email'])),
                'plan' => $tenant->subscription_plan,
                'modules' => $tenant->enabled_modules,
                'invited' => $result['invitation'] !== null,
            ],
            userId: $request->user()?->id,
            tenantId: (string) $tenant->id,
        );

        return ApiResponse::success([
            'tenant' => ['id' => (string) $tenant->id, 'name' => $tenant->name, 'slug' => $tenant->slug, 'plan' => $tenant->subscription_plan, 'modules' => $tenant->enabled_modules],
            'created' => $result['created'],
            'owner_has_access' => $result['owner_has_access'],
            'invitation' => $this->invitation($result['invitation']),
        ], $result['created'] ? 'Workspace created.' : 'This workspace already existed; nothing was duplicated.');
    }

    /** POST /api/v1/admin/tenants/{tenant}/owner-invitation — a fresh one-time link; the old one stops working. */
    public function resendOwner(Request $request, string $tenant): JsonResponse
    {
        $data = $request->validate(['owner_email' => ['required', 'email:rfc', 'max:190']]);
        $this->tenants->enterPlatformScope();
        $model = Tenant::query()->findOrFail($tenant);

        $result = $this->provisioner->resendOwnerInvitation($model, $data['owner_email'], $request->user());

        $this->audit->log(
            action: 'platform.tenant.owner_invitation_reissued',
            entityType: Tenant::class,
            entityId: (string) $model->id,
            after: ['owner_email' => strtolower(trim($data['owner_email'])), 'owner_has_access' => $result['owner_has_access']],
            userId: $request->user()?->id,
            tenantId: (string) $model->id,
        );

        return ApiResponse::success([
            'owner_has_access' => $result['owner_has_access'],
            'invitation' => $this->invitation($result['invitation']),
        ], $result['owner_has_access'] ? 'The owner already has access.' : 'A new invitation was issued.');
    }

    /**
     * @param  array<string, mixed>|null  $invitation
     * @return array<string, mixed>|null
     */
    private function invitation(?array $invitation): ?array
    {
        return $invitation === null ? null : [
            'delivery_status' => $invitation['delivery_status'] ?? null,
            'accept_link' => $invitation['accept_link'] ?? null,
            'expires_in_hours' => $invitation['expires_in_hours'] ?? null,
        ];
    }
}
