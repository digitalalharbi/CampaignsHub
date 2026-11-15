<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Enums;

/** What the immutable subscription log records (spec §4, §9). */
enum SubscriptionLogAction: string
{
    case Created = 'created';
    case Migrated = 'migrated';
    case ConfirmationRequested = 'confirmation_requested';
    case Confirmed = 'confirmed';
    case ApprovedInternally = 'approved_internally';
    case Unsubscribed = 'unsubscribed';
    case Reduced = 'reduced';
    case Paused = 'paused';
    case Resumed = 'resumed';
    case Cancelled = 'cancelled';
    case NoConfirmation7d = 'no_confirmation_7d';
}
