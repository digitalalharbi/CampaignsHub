<?php

declare(strict_types=1);

namespace App\Domains\Identity\Services;

use App\Domains\Access\Models\Role;
use App\Domains\Projects\Models\ProjectMembership;
use App\Domains\Requests\Services\ContactVerificationService;
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
    public function invite(Tenant $tenant, string $email, string $roleSlug, ?array $projectIds, User $invitedBy): array
    {
        $email = Str::lower(trim($email));

        // Cannot invite an email that already belongs to an account.
        if (User::where('email', $email)->exists()) {
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
            'delivery_status' => 'awaiting_provider_credentials', // no mailer → never "sent"
            'invited_by' => $invitedBy->id,
            'expires_at' => Carbon::now()->addHours(self::TTL_HOURS),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        return [
            'id' => $id,
            'delivery_status' => 'awaiting_provider_credentials',
            'dev_link' => ContactVerificationService::exposeDevSecrets() ? "/invite/accept?token={$token}" : null,
        ];
    }

    /** @return array<string,mixed> public preview of an invite (workspace + email) by token. */
    public function preview(string $token): array
    {
        $inv = $this->resolve($token);

        return [
            'email' => $inv->email,
            'workspace_name' => Tenant::whereKey($inv->tenant_id)->value('name'),
            'role_slug' => $inv->role_slug,
        ];
    }

    /** Accept an invite: create the user in the inviting tenant, assign role + project access. */
    public function accept(string $token, string $name, string $password): User
    {
        $inv = $this->resolve($token);

        return DB::transaction(function () use ($inv, $name, $password): User {
            // Re-check inside the transaction (race): the email must still be free.
            if (User::where('email', $inv->email)->exists()) {
                throw ValidationException::withMessages(['email' => 'A user with this email already exists.']);
            }

            $user = User::create([
                'tenant_id' => $inv->tenant_id, // joins the EXISTING workspace — no new tenant
                'name' => $name,
                'email' => $inv->email,
                'password' => Hash::make($password),
                'is_platform_admin' => false,
            ]);
            // email_verified_at is non-mass-assignable — set it explicitly (accepting the link proves ownership).
            $user->forceFill(['email_verified_at' => Carbon::now()])->save();

            $role = Role::where('slug', $inv->role_slug)
                ->where(fn ($q) => $q->where('tenant_id', $inv->tenant_id)->orWhereNull('tenant_id'))->firstOrFail();
            $user->assignRole($role);

            $projectIds = $inv->project_ids ? json_decode($inv->project_ids, true) : [];
            foreach ($projectIds as $projectId) {
                ProjectMembership::create([
                    'tenant_id' => $inv->tenant_id, 'project_id' => $projectId, 'user_id' => $user->id,
                    'role' => 'member', 'status' => 'active',
                ]);
            }

            DB::table('workspace_invitations')->where('id', $inv->id)
                ->update(['accepted_at' => Carbon::now(), 'accepted_user_id' => $user->id, 'updated_at' => Carbon::now()]);

            return $user;
        });
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
