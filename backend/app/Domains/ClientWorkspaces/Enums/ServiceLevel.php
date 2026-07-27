<?php

declare(strict_types=1);

namespace App\Domains\ClientWorkspaces\Enums;

/** Engagement / service level the agency provides for a client. */
enum ServiceLevel: string
{
    case ManagedService = 'managed_service';
    case Consulting = 'consulting';
    case ReportingOnly = 'reporting_only';
    case AnalyticsOnly = 'analytics_only';
    case SelfService = 'self_service';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
