<?php

declare(strict_types=1);

namespace App\Domains\ClientWorkspaces\Enums;

use App\Domains\Projects\Lifecycle\LiveProjects;
use App\Domains\Projects\Lifecycle\ProjectStateMachine;

/**
 * Lifecycle status of a client relationship (`client_workspaces.client_status`).
 *
 * PROJECT-LIFECYCLE-001 rule 4: a client's state NEVER affects computing its data or spend — aggregation reads
 * project state only ({@see LiveProjects}). Written only by
 * {@see ProjectStateMachine::transitionClient()}; allowed moves in
 * {@see self::allowedTargets()}.
 */
enum ClientStatus: string
{
    case Prospect = 'prospect';
    case Onboarding = 'onboarding';
    case Active = 'active';
    case NeedsAttention = 'needs_attention';
    case Paused = 'paused';
    case Completed = 'completed';
    case Archived = 'archived';
    case Deleted = 'deleted';

    /**
     * The values a person may pick on the classification form / taxonomy. `deleted` is reached only through the
     * delete flow, so it is not offered here ({@see self::all()} lists every state).
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_values(array_map(fn (self $c) => $c->value, array_filter(self::cases(), fn (self $c) => $c !== self::Deleted)));
    }

    /** @return list<string> every state, including `deleted` */
    public static function all(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    /**
     * The client allowed-transitions table. The six working states move freely between each other (they describe the
     * relationship, not the data); any of them may be archived or deleted; archived returns to a working state or is
     * deleted; deleted is only restored (to the state recorded before deletion, checked by the machine).
     *
     * @return list<self>
     */
    public function allowedTargets(): array
    {
        $working = [self::Prospect, self::Onboarding, self::Active, self::NeedsAttention, self::Paused, self::Completed];

        return match ($this) {
            self::Archived => [...$working, self::Deleted],
            self::Deleted => [...$working, self::Archived],
            default => [...array_values(array_filter($working, fn (self $c) => $c !== $this)), self::Archived, self::Deleted],
        };
    }

    public function canBecome(self $to): bool
    {
        return in_array($to, $this->allowedTargets(), true);
    }

    public function labelKey(): string
    {
        return 'clients.lifecycle.status.'.$this->value;
    }
}
