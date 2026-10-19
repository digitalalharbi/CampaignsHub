<?php

declare(strict_types=1);

namespace App\Domains\Agents\Support;

/**
 * The CLOSED vocabulary of things an agent may ask for.
 *
 * There is deliberately no delete, no create-active and no bid, targeting or creative edit: an action
 * that is not a case here cannot be proposed, whatever a model says. New objects are created paused
 * only, and arrive with a later batch as their own case.
 */
enum ProposalAction: string
{
    case Pause = 'pause';
    case Resume = 'resume';
    case SetDailyBudget = 'set_daily_budget';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $a): string => $a->value, self::cases());
    }

    /** The tenant permission a person needs, on top of the project capability, to approve this action. */
    public function permission(): string
    {
        return match ($this) {
            self::Pause, self::Resume => 'campaigns.pause',
            self::SetDailyBudget => 'campaigns.budget.change',
        };
    }

    /** Whether the action can only lower or stop spend — the ones that are never capped. */
    public function lowersSpend(): bool
    {
        return $this === self::Pause;
    }
}
