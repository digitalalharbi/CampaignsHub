<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Enums;

/**
 * Internal performance classification of a unified campaign. Team-facing triage label, persisted
 * and audited. Deliberately NOT auto-computed in the UI — it is an editable, filterable field so
 * the team's judgement is recorded, not re-derived on every render.
 */
enum CampaignPerformanceLabel: string
{
    case TopPerforming = 'top_performing';
    case OnTrack = 'on_track';
    case NeedsOptimization = 'needs_optimization';
    case BudgetRisk = 'budget_risk';
    case NoResults = 'no_results';
    case TrackingIssue = 'tracking_issue';
    case StaleData = 'stale_data';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c) => $c->value, self::cases());
    }

    /** Labels that mean the campaign needs the team's attention (drives the Needs-Attention badge). */
    public static function needsAttention(): array
    {
        return [self::BudgetRisk->value, self::NoResults->value, self::TrackingIssue->value, self::StaleData->value];
    }
}
