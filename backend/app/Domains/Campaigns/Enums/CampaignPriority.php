<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Enums;

/**
 * Internal priority of a unified campaign for team triage. Persisted, editable, filterable, audited.
 */
enum CampaignPriority: string
{
    case Critical = 'critical';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c) => $c->value, self::cases());
    }
}
