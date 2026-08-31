<?php

declare(strict_types=1);

namespace App\Domains\Projects\Access;

use App\Domains\Projects\Models\ProjectMembership;
use App\Models\User;

/**
 * TEAM-PROJECT-RBAC-001 — the one place that answers «may this person do this, in this project».
 *
 * ## What it replaces
 *
 * Nothing. That is the defect: `project_memberships.role` and `.permissions` have existed since the
 * table was created and no code has ever read either. A project member's access was whatever their
 * TENANT role granted, on every project they could reach — so the invitation form offered «Media
 * buyer» and «Client viewer», stored the choice, and changed nothing about what the person could do.
 *
 * ## Fail closed, and say nothing about why
 *
 * Every path that is not an explicit grant returns false: no membership, an inactive one, an expired
 * one, an unknown capability, a capability spelled slightly wrong. The failure modes of an
 * authorisation system are asymmetric — a wrongly-refused employee complains, a wrongly-granted one
 * does not — so the default is refusal everywhere and the grants are enumerated.
 *
 * ## Two bypasses, both narrow and both deliberate
 *
 * A platform administrator bypasses this, as they bypass the tenant permissions: the operator of the
 * product can already read the database. A tenant user holding `projects.view.all` — the agency-wide
 * role — is a MEMBER of every project in their tenant for the purpose of reading, and gets the
 * viewer preset; they do not thereby get lead identities, which is exactly the grant that must be
 * made per project by somebody who knows the client.
 */
final class ProjectAbilities
{
    /**
     * One answer per (reader, project) for the life of the request.
     *
     * Without this, a paginated list asks the same question once per row: `LeadResource` consults
     * this for every lead, and sixteen leads became forty-nine queries — caught by the N+1 guard the
     * dedup work left behind, which is exactly what that guard is for. The set cannot change during
     * a request, so the second answer is the first.
     *
     * @var array<string, list<string>>
     */
    private array $memo = [];

    /**
     * What a TENANT permission means inside a project, for somebody who is not a member of one.
     *
     * Agency staff are not members of their clients' projects — they work across all of them, and
     * their access has always come from the tenant role. Removing that would be a regression dressed
     * as a security fix: the operator who set a spend limit yesterday would be refused today.
     *
     * @var array<string, list<string>>
     */
    private const FROM_TENANT = [
        'analytics.view' => [ProjectCapability::DASHBOARD_VIEW, ProjectCapability::ANALYTICS_VIEW, ProjectCapability::BUDGET_VIEW],
        'campaigns.view' => [ProjectCapability::CAMPAIGNS_VIEW],
        'campaigns.update' => [ProjectCapability::CAMPAIGNS_MANAGE],
        'campaigns.budget.change' => [ProjectCapability::BUDGET_MANAGE],
        'budget.view' => [ProjectCapability::BUDGET_VIEW],
        'budget.manage' => [ProjectCapability::BUDGET_VIEW, ProjectCapability::BUDGET_MANAGE],
        'leads.view' => [ProjectCapability::LEADS_VIEW],
        'leads.update' => [ProjectCapability::LEADS_UPDATE],
        /* Named separately at the tenant layer too — see the note in `PermissionSeeder`. */
        'leads.pii.view' => [ProjectCapability::LEADS_PII_VIEW],
        'leads.assign' => [ProjectCapability::LEADS_ASSIGN],
        'leads.export' => [ProjectCapability::LEADS_EXPORT],
        'reports.view' => [ProjectCapability::REPORTS_VIEW],
        'reports.create' => [ProjectCapability::REPORTS_MANAGE],
        'tasks.view' => [ProjectCapability::TASKS_VIEW],
        'tasks.update' => [ProjectCapability::TASKS_MANAGE],
        'users.invite' => [ProjectCapability::TEAM_MANAGE],
        'integrations.connect' => [ProjectCapability::INTEGRATIONS_MANAGE],
    ];

    /**
     * The capabilities this user holds in this project. Empty when they hold none.
     *
     * ## The membership decides, when there is one
     *
     * A project membership is a NARROWING, and it is authoritative: a media buyer invited to this
     * project gets the media buyer's capabilities here even if their tenant role is generous. That
     * is the whole point — «what may this person do on THIS client» is a question the tenant role
     * cannot answer, and a union with it would mean the project role could only ever add.
     *
     * Without a membership, the tenant role answers, as it always has. Agency staff are not members
     * of their clients' projects and never have been.
     *
     * @return list<string>
     */
    public function for(User $user, string $projectId): array
    {
        $key = $user->getKey().':'.$projectId;

        return $this->memo[$key] ??= $this->resolve($user, $projectId);
    }

    /** @return list<string> */
    private function resolve(User $user, string $projectId): array
    {
        if ($user->is_platform_admin) {
            return ProjectCapability::ALL;
        }

        /*
         * A tenant administrator is the owner of every project in it. `settings.manage` is the
         * permission that already means «this person configures the workspace»; somebody has to be
         * able to grant the rest, and it must not be possible to lock the owner out of their own
         * client by giving them a narrow membership.
         */
        if ($user->hasPermission('settings.manage')) {
            return ProjectCapability::ALL;
        }

        $membership = ProjectMembership::withoutGlobalScopes()
            ->where('project_id', $projectId)
            ->where('user_id', $user->id)
            ->first();

        if ($membership === null || ! $this->isCurrent($membership)) {
            return $this->fromTenant($user, $projectId);
        }

        $granted = ProjectRole::preset($membership->role);

        /*
         * Explicit extras from the membership row, filtered against the catalogue.
         *
         * A capability that is not a capability is dropped rather than granted: the column is JSON
         * written by an API, and «leads.*», «admin» or a typo must not become access. This is the
         * unknown case, and the unknown case is no.
         */
        foreach ((array) ($membership->permissions ?? []) as $extra) {
            if (is_string($extra) && ProjectCapability::exists($extra)) {
                $granted[] = $extra;
            }
        }

        return array_values(array_unique($granted));
    }

    /**
     * What somebody with no membership here may do, read off their tenant role.
     *
     * A project they cannot reach at all yields nothing: `projects.view.all` is what lets an agency
     * operator open any project in their own tenant, and without it a non-member is not entitled to
     * the project's existence, let alone its contents.
     *
     * @return list<string>
     */
    private function fromTenant(User $user, string $projectId): array
    {
        if (! $user->hasPermission('projects.view.all')) {
            return [];
        }

        $granted = [];

        foreach (self::FROM_TENANT as $permission => $capabilities) {
            if ($user->hasPermission($permission)) {
                $granted = [...$granted, ...$capabilities];
            }
        }

        /*
         * Reading the project at all is the floor for somebody the tenant lets in. Without it an
         * agency operator with a narrow role reaches a project and finds every screen refusing,
         * which reads as an outage rather than as a permission they were never given.
         */
        $granted[] = ProjectCapability::DASHBOARD_VIEW;

        return array_values(array_unique($granted));
    }

    public function allows(User $user, string $projectId, string $capability): bool
    {
        if (! ProjectCapability::exists($capability)) {
            return false;
        }

        return in_array($capability, $this->for($user, $projectId), true);
    }

    /**
     * A membership that has lapsed is not a membership.
     *
     * `status` covers a person who was removed; `expires_at` covers the contractor whose access was
     * granted for a campaign that ended. The second is the one that rots quietly — nobody revisits
     * an expiry date, which is precisely why it has to be enforced here rather than remembered.
     */
    private function isCurrent(ProjectMembership $membership): bool
    {
        if ((string) $membership->status !== 'active') {
            return false;
        }

        return $membership->expires_at === null || $membership->expires_at->isFuture();
    }
}
