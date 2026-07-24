<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Enums;

/**
 * Business objective of a unified campaign. Platform-specific objectives are mapped onto these when
 * importing external campaigns; unknown ones fall back to {@see self::Other}.
 */
enum CampaignObjective: string
{
    case Awareness = 'awareness';
    case Traffic = 'traffic';
    case Engagement = 'engagement';
    case Leads = 'leads';
    case AppInstalls = 'app_installs';
    case Sales = 'sales';
    case Conversions = 'conversions';
    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c) => $c->value, self::cases());
    }
}
