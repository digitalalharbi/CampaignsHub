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
    /** @var array<string, list<string>|null> one answer per reader for the life of this instance */
    private array $memo = [];

    /** @var array<string, list<string>> */
    private array $keys = [];

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
        $key = 'ceiling:'.$this->context->membership()?->getKey();

        if (! array_key_exists($key, $this->memo)) {
            $this->memo[$key] = $this->computeCeiling();
        }

        return $this->memo[$key];
    }

    /** @return list<string>|null */
    private function computeCeiling(): ?array
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

        if ($this->holds($user, 'projects.view.all')) {
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

    /**
     * The projects whose DATA this reader may be answered about without naming one, or NULL for «all».
     *
     * Same as {@see self::idsFor()} except for `settings.manage`: `ProjectAbilities` makes a tenant
     * administrator the owner of every project in the tenant, so a list that asks «which projects» must
     * agree with it — only the membership ceiling narrows them.
     *
     * @return list<string>|null
     */
    public function dataIdsFor(User $user): ?array
    {
        $key = 'data:'.$user->getKey().':'.$this->context->membership()?->getKey();

        if (! array_key_exists($key, $this->memo)) {
            $this->memo[$key] = $this->holds($user, 'settings.manage') ? $this->ceilingProjectIds() : $this->idsFor($user);
        }

        return $this->memo[$key];
    }

    /**
     * `User::hasPermission()` reads the roles from the database on every call; one reader asks this three
     * times per list, so the keys are read once per instance.
     */
    private function holds(User $user, string $permission): bool
    {
        if ($user->is_platform_admin) {
            return true;
        }

        $key = 'keys:'.$user->getKey().':'.$this->context->tenantId();
        $this->keys[$key] ??= $user->permissionKeys()->all();

        return in_array($permission, $this->keys[$key], true);
    }

    /**
     * The project reach that narrows the CLIENT-LEVEL surfaces (tasks, conversations, delivery packs, the client command
     * centre) for a reader whose membership is CONFINED — it names clients or projects — or NULL for «no narrowing».
     *
     * Those surfaces carry no project in the URL and answer to the client ceiling, so a member confined to one project of a
     * client read the client's other projects through them (PROJECTS-RBAC-CLOSURE-001). A reader with no ceiling is not
     * confined: the agency-wide holder of `clients.view_all`, and a client-roster member whose project allow-list is the
     * authority (`ClientAccess::projectRestriction()`), keep the answer the client layer already gave them.
     *
     * Inside a confinement the answer is fail-closed: a removed or expired membership leaves an EMPTY list, not «all».
     *
     * @return list<string>|null
     */
    public function confinedIdsFor(User $user): ?array
    {
        return $this->ceilingProjectIds() === null ? null : $this->dataIdsFor($user);
    }

    /**
     * Narrow a list of project ids (a client's projects, say) to the ones a confined reader reaches.
     *
     * @param  list<string>  $projectIds
     * @return list<string>
     */
    public function narrow(User $user, array $projectIds): array
    {
        $reach = $this->confinedIdsFor($user);

        return $reach === null ? $projectIds : array_values(array_intersect($projectIds, $reach));
    }

    /**
     * Whether a row that MAY belong to a project is inside a confined reader's reach.
     *
     * A row with no project says nothing about projects, so this answers true for it: the caller's own rule
     * (client ceiling, authorship) is what decides such a row, exactly as before.
     */
    public function canReachRow(User $user, ?string $projectId): bool
    {
        if ($projectId === null) {
            return true;
        }

        $reach = $this->confinedIdsFor($user);

        return $reach === null || in_array($projectId, $reach, true);
    }

    /**
     * The list twin of {@see self::canReachRow()}: project-bound rows only inside a confined reader's reach, project-less
     * rows untouched.
     *
     * @template TQuery of \Illuminate\Database\Eloquent\Builder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public function constrainRows($query, User $user, string $column = 'project_id')
    {
        $reach = $this->confinedIdsFor($user);

        if ($reach === null) {
            return $query;
        }

        return $query->where(static function ($q) use ($column, $reach): void {
            $q->whereNull($column)->orWhereIn($column, $reach);
        });
    }

    public function canReach(User $user, string $projectId): bool
    {
        $ids = $this->idsFor($user);

        return $ids === null || in_array($projectId, $ids, true);
    }
}
