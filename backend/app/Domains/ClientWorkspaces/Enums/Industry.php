<?php

declare(strict_types=1);

namespace App\Domains\ClientWorkspaces\Enums;

/**
 * Business activity / vertical of a client. NOTE: "awareness" is a CAMPAIGN OBJECTIVE, never an industry —
 * it is deliberately absent here.
 */
enum Industry: string
{
    case ECommerce = 'e_commerce';
    case LeadGeneration = 'lead_generation';
    case MobileApp = 'mobile_app';
    case B2B = 'b2b';
    case RealEstate = 'real_estate';
    case Education = 'education';
    case Healthcare = 'healthcare';
    case Events = 'events';
    case LocalBusiness = 'local_business';
    case Government = 'government';
    case Custom = 'custom';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
