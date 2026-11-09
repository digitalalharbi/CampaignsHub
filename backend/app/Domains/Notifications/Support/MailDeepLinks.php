<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Support;

/**
 * EMAIL-DIGESTS-OPERATIONAL-001 — where a button in an operational email lands INSIDE the system.
 *
 * A digest or an alert that says «go to the platform» sends a person out of the product to find the
 * thing the email was supposed to save them from looking for. Every button here is a page of this
 * application, carrying the project in the address (`?project=` is chosen on arrival by
 * `useProjectFromUrl`) and, where the router supports it, the tab or view that answers the sentence
 * above the button. One place, so a router change is one edit and a test can prove no link points
 * at an external host.
 */
final class MailDeepLinks
{
    /** The project's report page — the live report link is made from here. */
    public static function report(string $projectId): string
    {
        return MailLinks::to('/app/reports?project='.rawurlencode($projectId));
    }

    /** The project's analytics, on a tab (`performance`, `platforms`, `budget`, `creative`, `funnel`). */
    public static function analytics(string $projectId, string $tab = 'performance', ?int $days = null): string
    {
        return MailLinks::to('/app/analytics?project='.rawurlencode($projectId).'&tab='.rawurlencode($tab).($days !== null ? '&days='.$days : ''));
    }

    /** The platforms tab of the project's analytics — «open the platform inside the system». */
    public static function platforms(string $projectId, ?int $days = null): string
    {
        return self::analytics($projectId, 'platforms', $days);
    }

    /** The campaigns that need attention, for one project. */
    public static function campaignsNeedingAttention(string $projectId): string
    {
        return MailLinks::to('/app/campaigns?project='.rawurlencode($projectId).'&view=attention');
    }

    public static function campaign(string $projectId, string $campaignId): string
    {
        return MailLinks::to('/app/campaigns/'.rawurlencode($projectId).'/'.rawurlencode($campaignId));
    }

    public static function integrations(?string $projectId): string
    {
        return $projectId === null || $projectId === ''
            ? MailLinks::to('/app/integrations')
            : MailLinks::to('/app/projects/'.rawurlencode($projectId).'/integrations');
    }

    public static function alerts(?string $eventId = null): string
    {
        return MailLinks::to('/app/alerts'.($eventId !== null && $eventId !== '' ? '?event='.rawurlencode($eventId) : ''));
    }

    public static function dashboard(): string
    {
        return MailLinks::to('/app/dashboard');
    }
}
