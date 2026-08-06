<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Services;

use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;

/**
 * What a creative may safely show, and what it must admit it cannot (§15.1, §15.15).
 *
 * ## The rule about asset links
 *
 * A platform's asset and preview URLs are not ours to hand out. Three separate hazards:
 *
 *   1. **Tokens.** Several providers sign asset links with a query parameter derived from the access
 *      token. Passing one to a browser — worse, into a client's shared report — leaks a credential
 *      into a URL bar, a proxy log and a referrer header. Any link carrying an obvious credential
 *      parameter is withheld outright.
 *   2. **Expiry.** These links die, often within hours. Rendering an expired one produces a broken
 *      frame that reads as a broken product; knowing it expired lets the page say «needs a refresh»
 *      and lets the sync know what to go back for.
 *   3. **Absence.** Some platforms simply do not expose the asset. The honest answer is a placeholder
 *      that says so — never a fabricated thumbnail, and never a generic image that implies we have
 *      the creative when we do not.
 *
 * `state` carries which of those applies, so the UI renders a real preview, an «expired» notice or an
 * honest placeholder rather than guessing from a null.
 */
final class CreativePresenter
{
    /**
     * Query parameters that mean the URL is carrying a credential.
     *
     * Matched case-insensitively as whole parameter names. Deliberately broad: a false positive costs
     * one preview, a false negative publishes a token.
     */
    private const CREDENTIAL_PARAMS = [
        'access_token', 'accesstoken', 'token', 'auth', 'authorization', 'signature', 'sig',
        'apikey', 'api_key', 'key', 'secret', 'bearer', 'oauth_token',
    ];

    /** @return array<string, mixed> the card shape used by the library, the dashboard and reports */
    public function card(ExternalCreative $creative, ?UnifiedCampaign $campaign): array
    {
        $preview = $this->preview($creative);

        return [
            'id' => (string) $creative->getKey(),
            'name' => (string) ($creative->client_display_name ?: $creative->name),
            'format' => $creative->format,
            'provider' => $creative->provider,
            'status' => $creative->status,
            'campaign_id' => $creative->campaign_id === null ? null : (string) $creative->campaign_id,
            'campaign_name' => $campaign?->name,
            'preview' => $preview,
            'aspect_ratio' => $creative->aspect_ratio,
            'duration_seconds' => $creative->duration_seconds,
            'grouped' => $creative->creative_group_id !== null,
            'is_demo' => (bool) $creative->is_demo,
            'freshness' => [
                'last_synced_at' => $creative->last_synced_at?->toIso8601String(),
                'source_updated_at' => $creative->source_updated_at?->toIso8601String(),
                'first_seen_at' => $creative->first_seen_at?->toIso8601String(),
                'last_active_at' => $creative->last_active_at?->toIso8601String(),
            ],
        ];
    }

    /** @return array<string, mixed> the card, plus everything only the detail view needs */
    public function detail(ExternalCreative $creative, ?UnifiedCampaign $campaign): array
    {
        return $this->card($creative, $campaign) + [
            'copy' => [
                'body' => $creative->body,
                'headline' => $creative->headline,
                'description' => $creative->description,
                'cta' => $creative->cta,
            ],
            'dimensions' => [
                'width' => $creative->width,
                'height' => $creative->height,
                'aspect_ratio' => $creative->aspect_ratio,
                'file_size' => $creative->file_size,
            ],
            /*
             * The click-through destination, shown as text and never auto-followed.
             *
             * It is the advertiser's own URL rather than the platform's, so it carries no credential
             * of ours — but it is still content from an external system, and a page that opened it
             * automatically would be following a link chosen by whoever wrote the ad.
             */
            'destination_url' => $creative->destination_url,
            'external_ids' => [
                'creative' => $creative->external_creative_id,
                'ad' => $creative->external_ad_id,
                'ad_set' => $creative->external_ad_set_id === null ? null : (string) $creative->external_ad_set_id,
                'campaign' => $creative->external_campaign_id === null ? null : (string) $creative->external_campaign_id,
            ],
        ];
    }

    /**
     * What the browser may load for this creative, and why when it may not.
     *
     * @return array{
     *     state: string,
     *     kind: string,
     *     image_url: string|null,
     *     video_url: string|null,
     *     thumbnail_url: string|null,
     *     expires_at: string|null,
     *     note_ar: string|null,
     *     note_en: string|null
     * }
     */
    public function preview(ExternalCreative $creative): array
    {
        $kind = $this->kind($creative);

        $image = $this->safe($creative->asset_url) ?? $this->safe($creative->preview_url);
        $video = $this->safe($creative->video_url);
        $thumb = $this->safe($creative->thumbnail_url);

        $blocked = $this->wasWithheld($creative);

        return match (true) {
            $blocked => [
                'state' => 'withheld',
                'kind' => $kind,
                'image_url' => null, 'video_url' => null, 'thumbnail_url' => null,
                'expires_at' => null,
                'note_ar' => 'رابط المعاينة من المنصة يحمل بيانات اعتماد، فلا يُعرض.',
                'note_en' => 'The platform’s preview link carries a credential, so it is not shown.',
            ],
            $creative->assetExpired() => [
                'state' => 'expired',
                'kind' => $kind,
                'image_url' => null, 'video_url' => null,
                'thumbnail_url' => $thumb,
                'expires_at' => $creative->asset_expires_at?->toIso8601String(),
                'note_ar' => 'انتهت صلاحية رابط المنصة — يحتاج مزامنة جديدة.',
                'note_en' => 'The platform link has expired — it needs a fresh sync.',
            ],
            $image === null && $video === null && $thumb === null => [
                'state' => 'unavailable',
                'kind' => $kind,
                'image_url' => null, 'video_url' => null, 'thumbnail_url' => null,
                'expires_at' => null,
                'note_ar' => 'لا تتيح هذه المنصة أصل المحتوى.',
                'note_en' => 'This platform does not expose the creative’s asset.',
            ],
            default => [
                'state' => 'available',
                'kind' => $kind,
                'image_url' => $image,
                'video_url' => $video,
                'thumbnail_url' => $thumb,
                'expires_at' => $creative->asset_expires_at?->toIso8601String(),
                'note_ar' => null,
                'note_en' => null,
            ],
        };
    }

    private function kind(ExternalCreative $creative): string
    {
        $format = strtolower((string) $creative->format);

        return match (true) {
            str_contains($format, 'video') => 'video',
            str_contains($format, 'carousel') => 'carousel',
            str_contains($format, 'image') => 'image',
            $creative->video_url !== null => 'video',
            $creative->asset_url !== null || $creative->thumbnail_url !== null => 'image',
            default => 'other',
        };
    }

    /** True when the row HAS a link but every one of them was withheld for carrying a credential. */
    private function wasWithheld(ExternalCreative $creative): bool
    {
        $links = array_filter([
            $creative->asset_url, $creative->preview_url, $creative->video_url, $creative->thumbnail_url,
        ]);

        if ($links === []) {
            return false;
        }

        foreach ($links as $link) {
            if ($this->safe($link) !== null) {
                return false;
            }
        }

        return true;
    }

    /**
     * The URL, or null when it must not reach a browser.
     *
     * Withheld rather than stripped: removing a signature parameter from a signed URL leaves a link
     * that 403s, and a broken image with no explanation is worse than an honest refusal.
     */
    private function safe(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        /*
         * An inline image is not a network request, and carries nothing to leak.
         *
         * `data:image/…` is how a self-contained asset travels — the demo library uses it so the
         * preview renders on a laptop with no credentials and no network. Restricted to `image/`
         * deliberately: an `<img>` will not execute a `data:text/html` payload, but nothing in this
         * product needs one, and the narrower rule is the one that stays true if the value is ever
         * rendered somewhere other than an `<img>`.
         */
        if (str_starts_with(strtolower($url), 'data:image/')) {
            return $url;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $query = (string) parse_url($url, PHP_URL_QUERY);
        if ($query !== '') {
            parse_str($query, $params);
            foreach (array_keys($params) as $name) {
                if (in_array(strtolower((string) $name), self::CREDENTIAL_PARAMS, true)) {
                    return null;
                }
            }
        }

        return $url;
    }
}
