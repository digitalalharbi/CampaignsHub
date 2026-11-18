<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Enums;

/**
 * The status written to the immutable delivery log (spec §4). `Sent` is written only after the provider accepted the
 * message. `Deferred` is the one non-terminal status: a later row for the same delivery carries its outcome.
 */
enum DeliveryStatus: string
{
    case Sent = 'sent';
    case Failed = 'failed';
    case Deferred = 'deferred';
    case Skipped = 'skipped';
    case HeldForReview = 'held_for_review';
    case HeldForPrivacy = 'held_for_privacy';
    case BlockedByPlan = 'blocked_by_plan';

    public function isTerminal(): bool
    {
        return $this !== self::Deferred;
    }
}
