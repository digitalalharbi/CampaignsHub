<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Enums;

/**
 * Every feature key of spec §1. Defaults: every key ON for every plan and workspace type, except
 * `branding.remove_platform_footprint` which is OFF for all (config/notifications_v2.php → entitlements.defaults).
 */
enum EntitlementKey: string
{
    case Internal = 'notifications.internal';
    case Push = 'notifications.push';
    case Sound = 'notifications.sound';
    case External = 'notifications.external';
    case ExternalCharts = 'notifications.external.charts';
    case ExternalCcBcc = 'notifications.external.cc_bcc';
    case ExternalCustomSubject = 'notifications.external.custom_subject';
    case ExternalReviewMode = 'notifications.external.review_mode';
    case MorningBrief = 'followup.morning_brief';
    case Tasks = 'followup.tasks';
    case RemovePlatformFootprint = 'branding.remove_platform_footprint';
    // RELEASE-INTEGRATION — PROJECT-LIFECYCLE-001 §1: how long a deleted project stays in the trash (limit `trash_retention_days`).
    case TrashRetention = 'projects.trash_retention_days';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $k): string => $k->value, self::cases());
    }
}
