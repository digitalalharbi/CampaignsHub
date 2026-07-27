<?php

declare(strict_types=1);

namespace App\Domains\ClientWorkspaces\Enums;

/** Operational role a team member holds ON a specific client (distinct from the legacy portal client_role). */
enum ClientAccessRole: string
{
    case ClientOwner = 'client_owner';
    case MediaBuyer = 'media_buyer';
    case Analyst = 'analyst';
    case Reporter = 'reporter';
    case Viewer = 'viewer';
    case Custom = 'custom';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
