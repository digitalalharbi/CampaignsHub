<?php

declare(strict_types=1);

namespace App\Domains\ContentIntake\Enums;

/** The states a piece of new content moves through. Moves are made ONLY by IntakeTransitions. */
enum IntakeStatus: string
{
    case Draft = 'draft';
    case InReview = 'in_review';
    case NeedsChanges = 'needs_changes';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Live = 'live';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}
