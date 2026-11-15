<?php

declare(strict_types=1);

namespace App\Domains\Automation\Support;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Integrations\Support\PlatformCreatedAt;
use Illuminate\Support\Carbon;

/**
 * CONS-I-AGE — a campaign's CREATION date on the platform, the basis of «عمر الحملة بالأيام».
 *
 * `created_at` on our row is the first sync, so a campaign made in January and linked this week read as 3.5 days.
 * The date is `platform_created_at`; a row synced before that column existed falls back to the creation stamp
 * the platform itself sent inside `raw` (Meta `created_time`, TikTok `create_time`, Snapchat / X `created_at`,
 * LinkedIn `changeAuditStamps.created.time`). With none of them the age is NOT known — null, never the sync date.
 */
final class CampaignAge
{
    public static function createdAt(?ExternalCampaign $campaign): ?Carbon
    {
        if ($campaign === null) {
            return null;
        }
        if ($campaign->platform_created_at !== null) {
            return Carbon::instance($campaign->platform_created_at);
        }

        $raw = (array) ($campaign->raw ?? []);
        $platform = (array) ($raw['raw'] ?? []);
        $candidates = [
            $raw['platform_created_at'] ?? null,
            $platform['created_time'] ?? null,
            $platform['create_time'] ?? null,
            $platform['created_at'] ?? null,
            $platform['changeAuditStamps']['created']['time'] ?? null,
        ];
        foreach ($candidates as $c) {
            $iso = PlatformCreatedAt::from($c);
            if ($iso !== null) {
                return Carbon::parse($iso);
            }
        }

        return null;
    }
}
