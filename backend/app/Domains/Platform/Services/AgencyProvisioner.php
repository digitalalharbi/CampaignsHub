<?php

declare(strict_types=1);

namespace App\Domains\Platform\Services;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Accounts\Enums\AccountState;
use App\Domains\Accounts\Services\TransitionAccountState;
use App\Domains\Identity\Services\InvitationService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Models\Workspace;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AGENCY-PROVISION-001 — a new agency tenant, handed to its owner through a verified invitation.
 *
 * ## What it is, and what it refuses to be
 *
 * An agency on CampaignsHub IS a tenant: its clients, projects, ad accounts, integrations, stores,
 * creatives, reports, rules, users, finances and audit trail are all tenant-scoped rows, and the
 * tenant scope is what keeps one agency out of another. So adding an agency is adding a tenant —
 * not a client, not a renamed account, and not a second copy of any module.
 *
 * It creates the tenant, its default workspace and its owner role, and nothing else: no client, no
 * project, no integration, no sample data. A new agency starts empty and connects its own accounts.
 *
 * ## Access is never assumed
 *
 * The owner address is NOT given a membership here. An expiring, hashed-token invitation is issued
 * for it, and access is granted only when the person who controls that address accepts — by setting
 * a password through the emailed link, or by claiming it while signed in as that verified address.
 * Knowing or guessing an address grants nothing.
 *
 * ## Idempotent
 *
 * Running it again for the same agency finds the existing tenant (by slug) and changes nothing about
 * it; it re-issues an invitation only while the owner still has no access and none is pending.
 */
final class AgencyProvisioner
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TransitionAccountState $transitions,
        private readonly InvitationService $invitations,
    ) {}

    /**
     * @return array{tenant: Tenant, created: bool, invitation: array{id: string, delivery_status: string, dev_link: ?string}|null, owner_has_access: bool}
     */
    /**
     * @param  list<string>|null  $modules  null keeps the agency default (paid_media + influencer_marketing)
     */
    public function provision(
        string $name,
        string $ownerEmail,
        ?User $operator = null,
        ?string $workspaceName = null,
        ?string $plan = null,
        ?array $modules = null,
        bool $withLink = false,
    ): array {
        $name = trim($name);
        $ownerEmail = Str::lower(trim($ownerEmail));
        if ($name === '' || filter_var($ownerEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('An agency needs a name and a valid owner email.');
        }

        $previous = $this->context->tenantId();

        try {
            [$tenant, $created] = DB::transaction(function () use ($name, $workspaceName, $plan, $modules): array {
                $slug = Str::slug($name) ?: 'agency';
                $existing = Tenant::withTrashed()->where('slug', $slug)->first();
                if ($existing !== null) {
                    if ($existing->trashed() || $existing->account_type !== 'agency') {
                        throw new \RuntimeException("The slug «{$slug}» is taken by a tenant that is not a live agency; choose another name.");
                    }

                    return [$existing, false];
                }

                $tenant = Tenant::create([
                    'name' => $name,
                    'slug' => $slug,
                    'status' => 'inactive',
                    'account_type' => 'agency',
                    'enabled_modules' => $modules ?? ['paid_media', 'influencer_marketing'],
                    'subscription_plan' => $plan ?? 'trial',
                    'onboarding_step' => 'done',
                    'onboarding_completed_at' => now(),
                ]);
                $this->context->setTenantId((string) $tenant->id);

                Workspace::create(['tenant_id' => $tenant->id, 'name' => trim((string) $workspaceName) !== '' ? trim((string) $workspaceName) : 'Default Workspace', 'slug' => 'default']);
                Role::firstOrCreate(
                    ['tenant_id' => $tenant->id, 'slug' => 'tenant-owner'],
                    ['name' => 'Tenant Owner', 'is_system' => true],
                )->givePermissionTo(...Permission::pluck('key')->all());

                $this->transitions->provision($tenant, AccountState::Active, 'Provisioned by the platform operator as a new agency.');

                return [$tenant->refresh(), true];
            });

            $this->context->setTenantId((string) $tenant->id);
            $hasAccess = $this->ownerHasAccess($tenant, $ownerEmail);
            $invitation = null;
            if (! $hasAccess && ! $this->invitationPending($tenant, $ownerEmail)) {
                // An expired, never-accepted invitation would otherwise read as «already pending».
                DB::table('workspace_invitations')->where('tenant_id', $tenant->id)->where('email', $ownerEmail)->whereNull('accepted_at')->delete();
                $invitation = $this->invitations->invite($tenant, $ownerEmail, 'tenant-owner', null, $operator, allowExisting: true, withLink: $withLink);
            }

            return ['tenant' => $tenant, 'created' => $created, 'invitation' => $invitation, 'owner_has_access' => $hasAccess];
        } finally {
            $this->context->setTenantId($previous);
        }
    }

    private function ownerHasAccess(Tenant $tenant, string $email): bool
    {
        $user = User::where('email', $email)->first();

        return $user !== null && Membership::query()->forUser($user->id)->where('tenant_id', $tenant->id)->exists();
    }

    private function invitationPending(Tenant $tenant, string $email): bool
    {
        return DB::table('workspace_invitations')->where('tenant_id', $tenant->id)->where('email', $email)
            ->whereNull('accepted_at')->where('expires_at', '>', now())->exists();
    }

    /**
     * SUPERADMIN-WORKSPACE-001 — a fresh one-time link for a tenant's owner when the first never arrived or expired.
     *
     * Retires every unaccepted invitation for that address in this tenant first, so exactly one link is live; an owner who already
     * has access is not re-invited.
     *
     * @return array{owner_has_access: bool, invitation: ?array<string, mixed>}
     */
    public function resendOwnerInvitation(Tenant $tenant, string $ownerEmail, ?User $operator = null): array
    {
        $ownerEmail = Str::lower(trim($ownerEmail));
        $previous = $this->context->tenantId();

        try {
            $this->context->setTenantId((string) $tenant->id);

            if ($this->ownerHasAccess($tenant, $ownerEmail)) {
                return ['owner_has_access' => true, 'invitation' => null];
            }

            DB::table('workspace_invitations')->where('tenant_id', $tenant->id)->where('email', $ownerEmail)->whereNull('accepted_at')->delete();

            return [
                'owner_has_access' => false,
                'invitation' => $this->invitations->invite($tenant, $ownerEmail, 'tenant-owner', null, $operator, allowExisting: true, withLink: true),
            ];
        } finally {
            $this->context->setTenantId($previous);
        }
    }
}
