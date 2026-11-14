<?php

declare(strict_types=1);

namespace App\Domains\Funding\Services;

use App\Domains\Projects\Access\ProjectAbilities;
use App\Domains\Projects\Access\ProjectCapability;
use App\Domains\Projects\Models\ProjectMembership;
use App\Models\User;

/**
 * FIN-LOOP-001 — one rule for reading and writing a project's budget: a project membership that
 * withholds money withholds the budget too. Readers reached through the client (roster, scope,
 * `clients.view_all`) hold no membership and are governed by their permission and `ClientAccess`.
 */
final class FundingReach
{
    public function __construct(private readonly ProjectAbilities $abilities) {}

    public function moneyWithheld(User $user, string $projectId): bool
    {
        return ProjectMembership::withoutGlobalScopes()->where('project_id', $projectId)->where('user_id', $user->id)->exists()
            && ! $this->abilities->allows($user, $projectId, ProjectCapability::MONEY_VIEW);
    }
}
