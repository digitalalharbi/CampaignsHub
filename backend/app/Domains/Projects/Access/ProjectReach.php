<?php

declare(strict_types=1);

namespace App\Domains\Projects\Access;

use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectMembership;
use App\Domains\Tenancy\Context\MembershipContext;
use App\Models\User;

/**
 * SEC-1 — which projects may THIS request touch: the one answer behind `ResolveProject`, the project
 * listing, the portfolio overview, the notification bell and every CRM endpoint that takes a project id.
 *
 * Two layers, and the NARROWER wins:
 *
 * 1. **The membership ceiling.** A membership that names clients or projects (`membership_scopes`) is a
 *    hard ceiling no role can widen — `ClientScopeResolver` documents it (REG-001) and
 *    `Membership::scopes` repeats it. `projects.view.all` and `settings.manage` are permissions about
 *    WHAT may be done; they never decide WHICH clients. Before this class, `ResolveProject` read the
 *    permission and never the ceiling, so an account manager scoped to client A who held
 *    `projects.view.all` could bind any project of client B.
 * 2. **The reader's own reach inside that ceiling.** `projects.view.all` is the positive grant for
 *    «every project in the tenant»; everybody else reaches the projects they hold a CURRENT membership
 *    of (status active, `expires_at` unset or in the future — the same test `ProjectAbilities` applies).
 *
 * `null` always means «no narrowing», never «nothing»; an empty list means nothing.
 */
final class ProjectReach
{
    public function __construct(private readonly MembershipContext $context) {}

    /**
     * Whether a project sits inside the acting membership's named client / project ceiling.
     * True when the membership names nothing (no ceiling) or there is no membership (jobs, CLI).
     */
    public function withinCeiling(string $projectId): bool
    {
        $projects = $this->context->projectScopeIds();
        $clients = $this->context->clientScopeIds();

        if ($projects === [] && $clients === []) {
            return true;
        }

        if ($projects !== [] && ! in_array($projectId, $projects, true)) {
            return false;
        }

        if ($clients === []) {
            return true;
        }

        $client = Project::withoutGlobalScopes()
            ->where('id', $projectId)
            ->where('tenant_id', $this->context->tenantId())
            ->value('client_workspace_id');

        return $client !== null && in_array((string) $client, $clients, true);
    }

    /**
     * The ceiling as project ids, or null when the membership names nothing.
     *
     * @return list<string>|null
     */
    public function ceilingProjectIds(): ?array
    {
        $projects = $this->context->projectScopeIds();
        $clients = $this->context->clientScopeIds();

        if ($projects === [] && $clients === []) {
            return null;
        }

        $ids = $projects === [] ? null : $projects;

        if ($clients !== []) {
            $inClients = Project::withoutGlobalScopes()
                ->where('tenant_id', $this->context->tenantId())
                ->whereIn('client_workspace_id', $clients)
                ->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->all();

            $ids = $ids === null ? $inClients : array_values(array_intersect($ids, $inClients));
        }

        return array_values($ids ?? []);
    }

    /**
     * The projects this reader may reach, or NULL for «every project in the tenant».
     *
     * @return list<string>|null
     */
    public function idsFor(User $user): ?array
    {
        $ceiling = $this->ceilingProjectIds();

        if ($user->hasPermission('projects.view.all')) {
            return $ceiling;
        }

        $own = ProjectMembership::query()
            ->current()
            ->where('user_id', $user->getKey())
            ->pluck('project_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        return $ceiling === null ? $own : array_values(array_intersect($own, $ceiling));
    }

    public function canReach(User $user, string $projectId): bool
    {
        $ids = $this->idsFor($user);

        return $ids === null || in_array($projectId, $ids, true);
    }
}
