<?php

declare(strict_types=1);

namespace App\Domains\Notifications\External\Content;

use App\Domains\Notifications\Engine\Contracts\EntitlementGate;
use App\Domains\Notifications\Engine\Enums\EntitlementKey;
use Illuminate\Support\Facades\DB;

/**
 * The platform footprint of an external message (spec §12): the subject suffix « · CampaignsHub», the sender name
 * «… عبر CampaignsHub», and the footer (small mark, the fixed sentence, the public-site link with UTM, the admin-editable
 * invitation line). Not removable by a workspace: only the plan key `branding.remove_platform_footprint` (OFF for every
 * plan) removes it, and only the admin can grant that. Clicks go through `/n/f/{token}` and are counted per message type
 * and workspace — nothing about the reader.
 */
final class Footprint
{
    public const DEFAULT_SITE = 'https://campaignshub.io';

    public static function removed(string $tenantId): bool
    {
        return app(EntitlementGate::class)->allows($tenantId, EntitlementKey::RemovePlatformFootprint);
    }

    /** @return array{invitation_ar: string|null, invitation_en: string|null, site_url: string} the admin's settings */
    public static function settings(): array
    {
        $raw = DB::table('platform_settings')->value('notifications_footprint');
        $s = is_string($raw) ? (array) json_decode($raw, true) : (array) ($raw ?? []);
        $site = isset($s['site_url']) && is_string($s['site_url']) && str_starts_with($s['site_url'], 'https://') ? $s['site_url'] : self::DEFAULT_SITE;

        // The admin page (builder R) edits the invitation line as the platform text `footprint.invitation`; a saved text wins.
        return [
            'invitation_ar' => PlatformTexts::localised('footprint.invitation', 'ar') ?? (isset($s['invitation_ar']) && is_string($s['invitation_ar']) && $s['invitation_ar'] !== '' ? $s['invitation_ar'] : null),
            'invitation_en' => PlatformTexts::localised('footprint.invitation', 'en') ?? (isset($s['invitation_en']) && is_string($s['invitation_en']) && $s['invitation_en'] !== '' ? $s['invitation_en'] : null),
            'site_url' => rtrim($site, '/'),
        ];
    }

    public static function siteUrl(string $type): string
    {
        return self::settings()['site_url'].'/?'.http_build_query(['utm_source' => 'email', 'utm_medium' => 'notification', 'utm_campaign' => $type]);
    }

    public static function clickUrl(string $tenantId, string $type, string $reference): string
    {
        $body = rtrim(strtr(base64_encode((string) json_encode(['t' => $tenantId, 'm' => $type, 'ref' => $reference])), '+/', '-_'), '=');

        return rtrim((string) config('app.url'), '/').'/n/f/'.$body.'.'.self::sign($body);
    }

    /** @return array<string, string>|null */
    public static function verifyClick(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2 || ! hash_equals(self::sign($parts[0]), $parts[1])) {
            return null;
        }
        $claims = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/'), true), true);

        return is_array($claims) ? array_map('strval', $claims) : null;
    }

    /** Admin statistics: clicks per message type over a window. @return array<string, int> */
    public static function clicksByType(?string $since = null): array
    {
        return DB::table('notification_footprint_clicks')->when($since !== null, fn ($q) => $q->where('clicked_at', '>=', $since))
            ->groupBy('message_type')->selectRaw('message_type, count(*) as clicks')->pluck('clicks', 'message_type')
            ->map(static fn ($v): int => (int) $v)->all();
    }

    private static function sign(string $body): string
    {
        return substr(hash_hmac('sha256', 'notif-footprint|'.$body, (string) config('app.key')), 0, 32);
    }
}
