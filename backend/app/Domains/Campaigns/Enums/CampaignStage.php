<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Enums;

/**
 * Operational lifecycle stage of a unified campaign — an internal (team-facing) classification,
 * distinct from delivery {@see CampaignStatus}. Persisted, editable, filterable and audited.
 */
enum CampaignStage: string
{
    case Planning = 'planning';
    case Setup = 'setup';
    case Learning = 'learning';
    case Scaling = 'scaling';
    case Optimization = 'optimization';
    case Stable = 'stable';
    case Declining = 'declining';
    case Completed = 'completed';
    case Archived = 'archived';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c) => $c->value, self::cases());
    }
}
