<?php

declare(strict_types=1);

namespace App\Domains\ClientWorkspaces\Enums;

/** Lifecycle status of a client relationship. */
enum ClientStatus: string
{
    case Prospect = 'prospect';
    case Onboarding = 'onboarding';
    case Active = 'active';
    case NeedsAttention = 'needs_attention';
    case Paused = 'paused';
    case Completed = 'completed';
    case Archived = 'archived';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
