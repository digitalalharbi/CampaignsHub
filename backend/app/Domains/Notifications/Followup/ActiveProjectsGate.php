<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Followup;

use App\Domains\Notifications\Internal\ActiveProjects;

/** One seam for «may a task exist for this project» — the active-projects adapter today, N1's live scope after the merge. */
final class ActiveProjectsGate
{
    public static function allows(string $tenantId, string $projectId): bool
    {
        return ActiveProjects::isLive($tenantId, $projectId);
    }
}
