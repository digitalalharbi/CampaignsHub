<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Enums;

/** External recipient / subscription state (spec §9). Nothing is sent unless `Active`. */
enum SubscriptionStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Paused = 'paused';
    case Cancelled = 'cancelled';
}
