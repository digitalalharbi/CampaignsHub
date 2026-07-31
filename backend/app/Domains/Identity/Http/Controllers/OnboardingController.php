<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers;

use App\Domains\Accounts\Enums\AccountType;
use App\Domains\Accounts\Services\AccountEntitlements;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\MembershipContext;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Resumable onboarding wizard. Steps advance a single onboarding_step on the tenant, so a user can leave and
 * come back to exactly where they were. Company accounts (brand / self-serve) skip the multi-client steps —
 * their own workspace IS the client — and land on a simplified menu; personal accounts get the full flow.
 */
final class OnboardingController extends Controller
{
    /** service choice → enabled modules. */
    private const SERVICE_MODULES = [
        'paid_media' => ['paid_media'],
        'influencer_marketing' => ['influencer_marketing'],
        'combined' => ['paid_media', 'influencer_marketing'],
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly MembershipContext $memberships,
        private readonly AccountEntitlements $entitlements,
    ) {}

    /**
     * The portal these entitlements describe (REG-001) — read from the active membership, which is
     * where the answer lives. Onboarding runs after registration has already granted one, so this is
     * settled by the time the wizard asks.
     */
    private function portal(): ?Portal
    {
        return $this->memberships->membership()?->portal;
    }

    /** GET /onboarding/state */
    public function state(Request $request): JsonResponse
    {
        $tenant = $this->tenant();

        return ApiResponse::success([
            'email_verified' => $request->user()->email_verified_at !== null,
            'account' => $this->entitlements->toArray($tenant, $this->portal()),
            'account_types' => AccountType::values(),
            'services' => array_keys(self::SERVICE_MODULES),
        ], 'Onboarding state.');
    }

    /**
     * POST /onboarding/account-type
     *
     * Answering "what kind of account is this?" also settles WHICH PORTAL the workspace opens in,
     * so the membership moves with the answer (REG-001).
     *
     * Registration seeds a portal from whatever the visitor chose on the public site, and a visitor
     * who arrived without choosing gets the advertiser portal. If they then say "agency" here, the
     * tenant was reclassified and the membership was not — leaving an agency permanently inside the
     * advertiser portal, with a rail that had no Clients in it and endpoints that refused them.
     *
     * Only the FOUNDING membership of a workspace still in onboarding is moved. A workspace that is
     * already running has people in it whose portals were granted deliberately, and reclassifying
     * the company must not silently relocate them.
     */
    public function accountType(Request $request): JsonResponse
    {
        $this->requireVerified($request);
        $data = $request->validate(['account_type' => ['required', Rule::in(AccountType::values())]]);
        $tenant = $this->tenant();
        $tenant->forceFill([
            'account_type' => $data['account_type'],
            'onboarding_step' => $this->stepAfter('account_type'),
        ])->save();

        $this->realignFoundingPortal($request, $tenant);

        return $this->ok($tenant);
    }

    /** Point this workspace's sole membership at the portal its new account type implies. */
    private function realignFoundingPortal(Request $request, Tenant $tenant): void
    {
        if ($tenant->onboarding_completed_at !== null) {
            return;
        }

        $target = Portal::forAccountType($tenant->account_type);
        $memberships = Membership::query()->where('tenant_id', $tenant->id)->get();

        // More than one member means the workspace is no longer just its founder — leave them be.
        if ($memberships->count() !== 1) {
            return;
        }

        $membership = $memberships->first();
        if ($membership->portal === $target) {
            return;
        }

        $membership->forceFill(['portal' => $target->value])->save();
        // The request is mid-flight with the old portal bound; re-bind so the payload this call
        // returns describes where the user actually is now rather than where they just were.
        $this->memberships->set($membership->refresh());
    }

    /** POST /onboarding/service */
    public function service(Request $request): JsonResponse
    {
        $this->requireVerified($request);
        $data = $request->validate(['service' => ['required', Rule::in(array_keys(self::SERVICE_MODULES))]]);
        $tenant = $this->tenant();
        $tenant->forceFill([
            'enabled_modules' => self::SERVICE_MODULES[$data['service']],
            'onboarding_step' => $this->stepAfter('service'),
        ])->save();

        return $this->ok($tenant);
    }

    /** POST /onboarding/workspace */
    public function workspace(Request $request): JsonResponse
    {
        $this->requireVerified($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'currency' => ['nullable', 'string', 'size:3'],
            'timezone' => ['nullable', 'timezone'],
            'language' => ['nullable', 'in:ar,en'],
            'week_start' => ['nullable', 'in:sunday,monday'],
        ]);
        $tenant = $this->tenant();
        $settings = $tenant->settings ?? [];
        $settings['general'] = array_replace($settings['general'] ?? [], array_filter([
            'currency' => $data['currency'] ?? null,
            'timezone' => $data['timezone'] ?? null,
            'language' => $data['language'] ?? null,
            'week_start' => $data['week_start'] ?? null,
        ], fn ($v) => $v !== null));
        $tenant->forceFill([
            'name' => $data['name'],
            'settings' => $settings,
            /*
             * Whose work is this? The PORTAL answers it (REG-001).
             *
             * Only the agency portal manages other people's campaigns, so only it is asked for a
             * first CLIENT. This branched on `workspaceKind` before, and `personal` — the branch a
             * freelancer, an in-house team and every unset account type fell into — asked all of
             * them to name a client. They then arrived in the advertiser portal, which has no
             * clients section at all, having just been made to create one.
             */
            'onboarding_step' => $this->portal() === Portal::Agency ? 'first_client' : 'first_project',
        ])->save();

        return $this->ok($tenant);
    }

    /** POST /onboarding/first-client — personal accounts create their first client; company auto-uses itself. */
    public function firstClient(Request $request): JsonResponse
    {
        $this->requireVerified($request);
        $data = $request->validate(['name' => ['required', 'string', 'min:2', 'max:120']]);
        $tenant = $this->tenant();
        $this->makeClient($tenant, $data['name']);
        $tenant->forceFill(['onboarding_step' => 'first_project'])->save();

        return $this->ok($tenant);
    }

    /** POST /onboarding/first-project */
    public function firstProject(Request $request): JsonResponse
    {
        $this->requireVerified($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'client_id' => ['nullable', 'string'],
        ]);
        $tenant = $this->tenant();

        // Company accounts have no client picker — ensure one implicit client (their own brand) exists.
        $client = null;
        if ($data['client_id'] ?? null) {
            $client = ClientWorkspace::where('id', $data['client_id'])->first();
        }
        $client ??= ClientWorkspace::where('tenant_id', $tenant->id)->orderBy('created_at')->first()
            ?? $this->makeClient($tenant, $tenant->name);

        Project::create([
            'tenant_id' => $tenant->id,
            'client_workspace_id' => $client->id,
            'name' => $data['name'],
            'status' => 'setup',
        ]);
        $tenant->forceFill(['onboarding_step' => 'data_source'])->save();

        return $this->ok($tenant);
    }

    /** POST /onboarding/complete — data-source connect is skippable (providers awaiting credentials). */
    public function complete(Request $request): JsonResponse
    {
        $this->requireVerified($request);
        $tenant = $this->tenant();
        $tenant->forceFill(['onboarding_step' => 'done', 'onboarding_completed_at' => now(), 'status' => 'active'])->save();

        return $this->ok($tenant);
    }

    // ---- helpers ----

    private function tenant(): Tenant
    {
        return Tenant::findOrFail((string) $this->context->tenantId());
    }

    private function stepAfter(string $step): string
    {
        return match ($step) {
            'account_type' => 'service',
            'service' => 'workspace',
            default => 'workspace',
        };
    }

    private function requireVerified(Request $request): void
    {
        abort_if($request->user()->email_verified_at === null, 403, 'Please verify your email first.');
    }

    private function makeClient(Tenant $tenant, string $name): ClientWorkspace
    {
        return ClientWorkspace::create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'slug' => Str::slug($name.'-'.Str::random(4)),
            'mode' => 'managed',
            'status' => 'active',
            'client_status' => 'onboarding',
            'owner_id' => request()->user()->id,
        ]);
    }

    private function ok(Tenant $tenant): JsonResponse
    {
        return ApiResponse::success(['account' => $this->entitlements->toArray($tenant->refresh(), $this->portal())], 'Saved.');
    }
}
