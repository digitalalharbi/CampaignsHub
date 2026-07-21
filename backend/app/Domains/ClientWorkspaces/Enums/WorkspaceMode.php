<?php

declare(strict_types=1);

namespace App\Domains\ClientWorkspaces\Enums;

/**
 * How a client workspace is operated. Configurable per workspace — no code changes to switch.
 * - Managed: the agency runs everything; the client views and approves.
 * - Collaborative: agency and client work together, gated by permissions.
 * - SelfService: the client runs their own projects/sources/team under their subscription.
 */
enum WorkspaceMode: string
{
    case Managed = 'managed';
    case Collaborative = 'collaborative';
    case SelfService = 'self_service';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $m) => $m->value, self::cases());
    }
}
