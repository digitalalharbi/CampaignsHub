<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Followup;

use App\Domains\Notifications\Internal\ActiveProjects;

/** One seam for «may a task exist for this project» — N1's live scope, through the ActiveProjects adapter. */
final class ActiveProjectsGate
{
    public static function allows(string $tenantId, string $projectId): bool
    {
        return ActiveProjects::isLive($tenantId, $projectId);
    }
}
