<?php

declare(strict_types=1);

namespace App\Domains\Projects\Access;

use App\Domains\Projects\Models\ProjectMembership;
use App\Models\User;

/**
 * SEC-CLUSTER-2 — the projects a signed-in user may reach for a given project capability, for TENANT-level
 * surfaces that have no `{project}` in the URL.
 *
 * A project route is gated by `ResolveProject` (member, or `projects.view.all`) and `project.can:…`
 * (`ProjectAbilities`). A tenant-level list (the creative library, the integrations inventory) sits behind
 * neither, so it answered for every project of the tenant to anyone holding the tenant permission and a
 * client scope — including a member of ONE project, or a member whose membership denies the capability.
 * This asks the SAME `ProjectAbilities` the project routes ask, so the two cannot drift.
 *
 * Deny by default: no user, no membership and no `projects.view.all` is an empty set, and an empty set
 * constrains a query to nothing.
 */
final class ReachableProjects
{
    private const NO_PROJECT = '00000000-0000-0000-0000-000000000000';

    public function __construct(private readonly ProjectAbilities $abilities) {}

    /**
     * @return array{only: list<string>|null, except: list<string>} `only` null = every project of the tenant, less `except`
     */
    public function for(?User $user, string $capability): array
    {
        if ($user === null) {
            return ['only' => [], 'except' => []];
        }

        if ($user->is_platform_admin || $user->hasPermission('settings.manage')) {
            return ['only' => null, 'except' => []];
        }

        $member = ProjectMembership::query()
            ->where('user_id', $user->getKey())
            ->where('status', 'active')
            ->pluck('project_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->unique()
            ->values()
            ->all();

        $allowedAmongMembers = array_values(array_filter($member, fn (string $id): bool => $this->abilities->allows($user, $id, $capability)));

        if (! $user->hasPermission('projects.view.all')) {
            return ['only' => $allowedAmongMembers, 'except' => []];
        }

        // Everybody else's projects are answered by the tenant role alone (the same for all of them); a
        // member's own membership narrows it, so the projects a membership withholds it from are removed.
        if (! $this->abilities->allows($user, self::NO_PROJECT, $capability)) {
            return ['only' => $allowedAmongMembers, 'except' => []];
        }

        return ['only' => null, 'except' => array_values(array_diff($member, $allowedAmongMembers))];
    }

    /**
     * Narrow a query on a column holding a project id.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<*>|\Illuminate\Database\Query\Builder  $query
     */
    public function constrain(mixed $query, ?User $user, string $column, string $capability): void
    {
        $reach = $this->for($user, $capability);

        if ($reach['only'] !== null) {
            $query->whereIn($column, $reach['only'] === [] ? [self::NO_PROJECT] : $reach['only']);
        }

        if ($reach['except'] !== []) {
            $query->whereNotIn($column, $reach['except']);
        }
    }
}
