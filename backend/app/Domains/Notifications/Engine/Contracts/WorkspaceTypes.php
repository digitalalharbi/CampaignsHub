<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Contracts;

use App\Domains\Notifications\Engine\Data\WorkspaceTypeProfile;

/** The workspace-type abstraction (spec §0). Code asks for a profile; it never branches on a concrete type. */
interface WorkspaceTypes
{
    /** The profile of a workspace; with `$userId`, the profile that user sees it through (a client-portal login, say). */
    public function profileFor(string $tenantId, ?int $userId = null): WorkspaceTypeProfile;

    public function profile(string $key): WorkspaceTypeProfile;

    /** @return list<string> */
    public function keys(): array;
}
