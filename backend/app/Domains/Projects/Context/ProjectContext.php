<?php

declare(strict_types=1);

namespace App\Domains\Projects\Context;

/**
 * Request-scoped holder for the "current project". Set only by ResolveProject from a verified route
 * param — never from untrusted body input. When set, project-scoped models restrict to it.
 */
final class ProjectContext
{
    private ?string $projectId = null;

    public function setProjectId(?string $projectId): void
    {
        $this->projectId = $projectId;
    }

    public function projectId(): ?string
    {
        return $this->projectId;
    }

    public function hasProject(): bool
    {
        return $this->projectId !== null;
    }

    public function forget(): void
    {
        $this->projectId = null;
    }
}
