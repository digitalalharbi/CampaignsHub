<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Enums;

/** Internal priority (spec §3). Only `Critical` bypasses quiet hours and holidays, and only for internal deliveries. */
enum Priority: string
{
    case Info = 'info';
    case Important = 'important';
    case Critical = 'critical';
}
