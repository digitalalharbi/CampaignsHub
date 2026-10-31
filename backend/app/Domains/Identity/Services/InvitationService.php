<?php

declare(strict_types=1);

namespace App\Domains\Identity\Services;

use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Notifications\Mail\InvitationMail;
use App\Domains\Notifications\Services\MemberNotices;
use App\Domains\Notifications\Services\TransactionalMailer;
use App\Domains\Notifications\Support\MailLinks;
use App\Domains\Projects\Access\ProjectRole;
use App\Domains\Projects\Models\ProjectMembership;
use App\Domains\Requests\Services\ContactVerificationService;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Workspace invitations: invite a member to an EXISTING tenant with a role + optional project allowlist, via
 * a secure expiring token. Accepting creates the user INSIDE that tenant (never a new workspace, never a
 * cross-tenant join) and marks them verified (the emailed link proves email ownership). Honest delivery.
 */
final class InvitationService
{
    private const TTL_HOURS = 72;

    /**
     * @param  list<string>|null  $projectIds
     * @return array{id: string, delivery_status: string, dev_link: ?string}
     */
    /**
     * `$withLink` (SUPERADMIN-WORKSPACE-001) returns the one-time accept link to the PLATFORM OPERATOR who issued it, so a workspace can
     * be handed to its owner when mail does not arrive. Only the platform console asks for it; a tenant's own team invites never do.
     */
    public function invite(Tenant $tenant, string $email, string $roleSlug, ?array $projectIds, ?User $invitedBy, bool $allowExisting = false, bool $withLink = false): array
    {
        $email = Str::lower(trim($email));

        // Cannot invite an email that already belongs to an account — except when the platform itself is
        // handing a NEW agency to its owner (`allowExisting`), who may already have an account elsewhere
        // and then claims this one while signed in as that address (`acceptExisting`).
        if (! $allowExisting && User::where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'A user with this email already exists.']);
        }
        // The role must exist in THIS tenant (or be a system role).
        $role = Role::where('slug', $roleSlug)
            ->where(fn ($q) => $q->where('tenant_id', $tenant->id)->orWhereNull('tenant_id'))->first();
        if ($role === null) {
            throw ValidationException::withMessages(['role_slug' => 'Unknown role for this workspace.']);
        }
        // Project allowlist must reference this tenant's projects.
        if ($projectIds !== null && $projectIds !== []) {
            $valid = DB::table('projects')->where('tenant_id', $tenant->id)->whereIn('id', $projectIds)->count();
            if ($valid !== count(array_unique($projectIds))) {
                throw ValidationException::withMessages(['project_ids' => 'Projects must belong to this workspace.']);
            }
        }
        // One pending invite per email per tenant.
        $pending = DB::table('workspace_invitations')->where('tenant_id', $tenant->id)
            ->where('email', $email)->whereNull('accepted_at')->exists();
        if ($pending) {
            throw ValidationException::withMessages(['email' => 'An invitation is already pending for this email.']);
        }

        $token = Str::random(48);
        $id = (string) Str::ulid();
        DB::table('workspace_invitations')->insert([
            'id' => $id,
            'tenant_id' => $tenant->id,
            'email' => $email,
            'role_slug' => $roleSlug,
            'project_ids' => $projectIds !== null ? json_encode(array_values($projectIds)) : null,
            'token_hash' => hash('sha256', $token),
            // Claimed, then replaced by whatever the attempt below actually produced.
            'delivery_status' => 'pending',
            'invited_by' => $invitedBy?->id,
            'expires_at' => Carbon::now()->addHours(self::TTL_HOURS),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        /*
         * The message is composed and handed to the mailer — MAIL-009.
         *
         * This column used to be written as the literal `awaiting_provider_credentials`, and no mail
         * was ever built. The statement was true of an install with no mailer, and it was also
         * unconditional: with real SMTP configured it would have gone on saying the same thing while
         * no invitation ever arrived. The status is now the OUTCOME of an attempt — see
         * `TransactionalMailer` for the four states it can be.
         *
         * The dedup key is the invitation's own id, so a retried request cannot mail the same person
         * the same invitation twice.
         */
        $status = TransactionalMailer::asDeliveryStatus(app(TransactionalMailer::class)->send(
            recipient: $email,
            mail: new InvitationMail(
                workspace: (string) $tenant->name,
                roleSlug: $roleSlug,
                acceptUrl: MailLinks::to("/invite/accept?token={$token}"),
                lang: 'ar',
                invitedBy: $invitedBy === null ? (string) config('brand.name') : (string) $invitedBy->name,
                expiresInHours: self::TTL_HOURS,
            ),
            kind: 'invitation',
            template: 'mail.invitation',
            tenantId: (string) $tenant->id,
            dedupKey: "invitation:{$id}",
        ));

        DB::table('workspace_invitations')->where('id', $id)
            ->update(['delivery_status' => $status, 'updated_at' => Carbon::now()]);

        return [
            'id' => $id,
            'delivery_status' => $status,
            'dev_link' => ContactVerificationService::exposeDevSecrets() ? "/invite/accept?token={$token}" : null,
            'accept_link' => $withLink ? MailLinks::to("/invite/accept?token={$token}") : null,
            'expires_in_hours' => self::TTL_HOURS,
        ];
    }

    /**
     * An invitation into ONE client's portal (CP-USERS-001): the same secure, hashed, expiring token as a
     * workspace invitation, carrying the client, the role and an optional project ceiling. Accepting it
     * creates the account, proves the address, and grants a client-portal membership confined to that
     * client — and nothing else: no staff role, no tenant-wide access.
     *
     * @param  list<string>|null  $projectIds
     * @return array{id: string, delivery_status: string, dev_link: ?string}
     */
    public function inviteToClientPortal(ClientWorkspace $workspace, string $email, string $role, ?array $projectIds, User $invitedBy): array
    {
        $email = Str::lower(trim($email));
        $tenant = Tenant::findOrFail($workspace->tenant_id);

        $pending = DB::table('workspace_invitations')->where('tenant_id', $tenant->id)->where('email', $email)->whereNull('accepted_at')->exists();
        if ($pending) {
            throw ValidationException::withMessages(['email' => 'An invitation is already pending for this email.']);
        }

        $token = Str::random(48);
        $id = (string) Str::ulid();
        DB::table('workspace_invitations')->insert([
            'id' => $id,
            'tenant_id' => $tenant->id,
            'email' => $email,
            'role_slug' => $role,
            'portal' => Portal::ClientPortal->value,
            'client_workspace_id' => $workspace->getKey(),
            'project_ids' => $projectIds === null || $projectIds === [] ? null : json_encode(array_values($projectIds)),
            'token_hash' => hash('sha256', $token),
            'delivery_status' => 'pending',
            'invited_by' => $invitedBy->id,
            'expires_at' => Carbon::now()->addHours(self::TTL_HOURS),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $status = TransactionalMailer::asDeliveryStatus(app(TransactionalMailer::class)->send(
            recipient: $email,
            mail: new InvitationMail(
                workspace: (string) $workspace->name,
                roleSlug: $role,
                acceptUrl: MailLinks::to("/invite/accept?token={$token}"),
                lang: 'ar',
                invitedBy: (string) $invitedBy->name,
                expiresInHours: self::TTL_HOURS,
            ),
            kind: 'invitation',
            template: 'mail.invitation',
            tenantId: (string) $tenant->id,
            dedupKey: "invitation:{$id}",
        ));

        DB::table('workspace_invitations')->where('id', $id)->update(['delivery_status' => $status, 'updated_at' => Carbon::now()]);

        return ['id' => $id, 'delivery_status' => $status, 'dev_link' => ContactVerificationService::exposeDevSecrets() ? "/invite/accept?token={$token}" : null];
    }

    /** @return array<string,mixed> public preview of an invite (workspace + email) by token. */
    public function preview(string $token): array
    {
        $inv = $this->resolve($token);

        return [
            'email' => $inv->email,
            // A client-portal invitation is into the CLIENT's space, so that is the name the invitee sees.
            'workspace_name' => $inv->client_workspace_id !== null
                ? ClientWorkspace::withoutGlobalScopes()->whereKey($inv->client_workspace_id)->value('name')
                : Tenant::whereKey($inv->tenant_id)->value('name'),
            'role_slug' => $inv->role_slug,
            // True when the address already has an account: the invitee then signs in AS that address and
            // claims it, rather than choosing a new password.
            'existing_account' => User::where('email', $inv->email)->exists(),
        ];
    }

    /**
     * Claim an invitation while signed in as the invited address.
     *
     * The proof of ownership is the session itself: the signed-in account's email must equal the
     * invited one (compared case-insensitively) AND be verified. An account that merely knows the link
     * is not enough, and neither is an unverified account that typed the address at signup.
     */
    public function acceptExisting(string $token, User $user): Membership
    {
        $inv = $this->resolve($token);

        if (Str::lower((string) $user->email) !== Str::lower((string) $inv->email) || $user->email_verified_at === null) {
            throw ValidationException::withMessages(['token' => 'Sign in with the invited, verified email address to accept this invitation.']);
        }
        if ($inv->portal === Portal::ClientPortal->value) {
            throw ValidationException::withMessages(['token' => 'This invitation is accepted through its own link.']);
        }

        return DB::transaction(fn (): Membership => $this->grantWorkspaceAccess($inv, $user));
    }

    /** Accept an invite: create the user in the inviting tenant, assign role + project access. */
    public function accept(string $token, string $name, string $password): User
    {
        $inv = $this->resolve($token);

        $user = DB::transaction(function () use ($inv, $name, $password): User {
            // Re-check inside the transaction (race): the email must still be free.
            if (User::where('email', $inv->email)->exists()) {
                throw ValidationException::withMessages(['email' => 'A user with this email already exists.']);
            }

            $user = User::create([
                // joins the EXISTING workspace — no new tenant
                'name' => $name,
                'email' => $inv->email,
                'password' => Hash::make($password),
            ]);
            // email_verified_at is non-mass-assignable — set it explicitly (accepting the link proves ownership).
            $user->forceFill(['email_verified_at' => Carbon::now()])->save();

            // CP-USERS-001 — a client-portal invitation grants ONE client's portal and nothing else: no staff
            // role, no project memberships in the agency's workspace, no tenant-wide scope.
            if ($inv->portal === Portal::ClientPortal->value) {
                app(GrantMembership::class)->execute(new MembershipGrant(
                    user: $user,
                    tenant: Tenant::findOrFail($inv->tenant_id),
                    portal: Portal::ClientPortal,
                    role: $inv->role_slug,
                    clientScopeIds: [(string) $inv->client_workspace_id],
                    projectScopeIds: $inv->project_ids ? json_decode($inv->project_ids, true) : null,
                    grantedBy: User::find($inv->invited_by),
                ));

                DB::table('workspace_invitations')->where('id', $inv->id)
                    ->update(['accepted_at' => Carbon::now(), 'accepted_user_id' => $user->id, 'updated_at' => Carbon::now()]);

                return $user;
            }

            $this->grantWorkspaceAccess($inv, $user);

            return $user;
        });

        // EMAIL-TRANSACTIONAL-SET-001 — welcomed AFTER the grant is committed, so a message can never undo access.
        app(MemberNotices::class)->welcome($user, Tenant::findOrFail($inv->tenant_id), (string) (Role::where('slug', $inv->role_slug)->where(fn ($q) => $q->where('tenant_id', $inv->tenant_id)->orWhereNull('tenant_id'))->value('name') ?? $inv->role_slug));

        return $user;
    }

    /** The role, project memberships and workspace membership an invitation confers; marks it accepted. */
    private function grantWorkspaceAccess(object $inv, User $user): Membership
    {
        $role = Role::where('slug', $inv->role_slug)
            ->where(fn ($q) => $q->where('tenant_id', $inv->tenant_id)->orWhereNull('tenant_id'))->firstOrFail();
        $user->assignRole($role);

        $projectIds = $inv->project_ids ? json_decode($inv->project_ids, true) : [];
        foreach ($projectIds as $projectId) {
            /*
             * TEAM-PROJECT-RBAC-001 — a role the vocabulary knows, not a word meaning «somebody».
             *
             * This wrote `'member'`, which is in neither list: not among the nine roles an
             * operator can pick on the team page, and not among the `ProjectRole` presets. It
             * survived on `preset()`'s unknown-role fallback — a kindness meant for rows an older
             * release wrote — so every invitee landed on viewer capabilities by accident rather
             * than by decision, and the team page printed the literal word «member» beside their
             * name because no label exists for it either.
             *
             * `VIEWER` is what the fallback already resolved to, so no invitee's access changes;
             * what changes is that the stored value now names the decision, can be labelled, and
             * can be widened on the team page like any other. An invitation carries a TENANT role
             * and says nothing about a project, so the narrowest read is the only honest default.
             */
            ProjectMembership::create([
                'tenant_id' => $inv->tenant_id, 'project_id' => $projectId, 'user_id' => $user->id,
                'role' => ProjectRole::VIEWER, 'status' => 'active',
            ]);
        }

        // Accepting an invitation IS the grant of access, so it says so explicitly rather than
        // relying on `users.tenant_id` (ADR 0002). Without this the invitee had a row and a role
        // but no membership — and since membership is the only source of portal and tenant, they
        // signed in to nothing: no workspace, no portal, straight back to onboarding.
        //
        // The portal comes from the TENANT's account type, because an invitation is always into
        // an existing workspace whose kind is already settled. Scopes are deliberately absent:
        // this grant confers no client access of its own, and an agency's client is invited
        // through `MembershipGrant::forAgencyClient()` instead.
        $tenant = Tenant::findOrFail($inv->tenant_id);
        $membership = app(GrantMembership::class)->execute(new MembershipGrant(
            user: $user,
            tenant: $tenant,
            portal: Portal::forAccountType($tenant->account_type),
            role: $inv->role_slug,
            grantedBy: User::find($inv->invited_by),
        ));

        DB::table('workspace_invitations')->where('id', $inv->id)
            ->update(['accepted_at' => Carbon::now(), 'accepted_user_id' => $user->id, 'updated_at' => Carbon::now()]);

        return $membership;
    }

    private function resolve(string $token): object
    {
        $inv = DB::table('workspace_invitations')->where('token_hash', hash('sha256', $token))->first();
        if ($inv === null || $inv->accepted_at !== null) {
            throw ValidationException::withMessages(['token' => 'This invitation is invalid or already used.']);
        }
        if (Carbon::parse($inv->expires_at)->isPast()) {
            throw ValidationException::withMessages(['token' => 'This invitation has expired.']);
        }

        return $inv;
    }
}
