<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Services;

use App\Domains\Campaigns\Models\ExternalAd;
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
     * Matched case-insensitively as WHOLE parameter names, never substrings — `Key-Pair-Id` must not
     * trip the `key` rule, and it is half of how a signed CDN URL is formed.
     *
     * Broad on credentials: a false negative publishes a token, which is unrecoverable. But see the
     * note below on signatures — being broad about the WRONG thing cost every preview in the
     * product, which is its own kind of failure.
     */
    private const CREDENTIAL_PARAMS = [
        'access_token', 'accesstoken', 'token', 'auth', 'authorization',
        'apikey', 'api_key', 'key', 'secret', 'bearer', 'oauth_token',
    ];

    /*
     * SNAP-SIGNED-MEDIA-001 — a CDN signature is not a credential, and treating it as one hid
     * every asset the platform actually supplied.
     *
     * `signature` and `sig` were on the list above. That is how essentially every CDN serves private
     * media — Snapchat's `download_link` included — so a media URL fetched perfectly and stored
     * correctly was then classified «withheld» and never rendered. The Content library said the
     * platform's link carried a credential when what it carried was a time-limited grant for one
     * object.
     *
     * The distinction is not stylistic. An `access_token` or `bearer` is a key to the ACCOUNT: leak
     * it and someone can read and change a customer's advertising. A CloudFront-style `Signature`,
     * with its `Expires` and `Key-Pair-Id`, authorises exactly one file for a short window and can
     * do nothing else, which is why the same URL is what the platform's own UI puts in an `<img>`.
     *
     * So the rule stays absolute where it matters — a token, key or secret in a query still withholds
     * the whole URL — and stops blocking the one thing that makes provider media visible at all.
     */

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
            /*
             * The two rungs between the campaign and the creative.
             *
             * The dashboard's drill-down is campaign → ad set → ad → creative, and every step of it
             * has to be a link the card can build. Without these the chain stops at the campaign and
             * the last two steps become filters the reader has to set by hand.
             */
            'ad_set_id' => $creative->external_ad_set_id === null ? null : (string) $creative->external_ad_set_id,
            /*
             * CREATIVE-PRESENTER-ADS-BACKEND-001 — the ads running this creative, and only these.
             *
             * A singular `ad_id` used to sit here, reading `external_creatives.external_ad_id` —
             * which `creativeFor()` rewrites on every upsert, so it named whichever ad was imported
             * last. On the live Snapchat account four ads share each creative, so a drill-down built
             * from it pointed at one of four while looking definite. The frontend has migrated, so
             * it is gone rather than kept as a field that looks like a relation and is not.
             *
             * Read through `ExternalCreative::ads()` — the `hasMany` on `external_ads.creative_id`,
             * which is the canonical relation. Ordered by `external_id` so the same creative renders
             * identically on every request; an unordered collection would make a card's first ad
             * depend on the database's row order.
             */
            'ads' => $creative->ads
                ->sortBy('external_id')
                ->map(static fn (ExternalAd $ad): array => [
                    'id' => (string) $ad->getKey(),
                    'external_id' => (string) $ad->external_id,
                    'name' => $ad->name,
                    'status' => $ad->status,
                    'external_ad_set_id' => $ad->external_ad_set_id === null ? null : (string) $ad->external_ad_set_id,
                    'external_campaign_id' => $ad->external_campaign_id === null ? null : (string) $ad->external_campaign_id,
                ])->values()->all(),
            'preview' => $preview,
            'aspect_ratio' => $creative->aspect_ratio,
            'duration_seconds' => $creative->duration_seconds,
            // On the CARD, not only the detail view: §15.3 asks for the dimensions and weight beside
            // a full-size preview, and the viewer opens from a card without fetching the detail.
            // Nullable throughout — a platform that does not report a file size has not sent zero.
            'width' => $creative->width,
            'height' => $creative->height,
            'file_size' => $creative->file_size === null ? null : (int) $creative->file_size,
            'grouped' => $creative->creative_group_id !== null,
            // The group itself, not only the fact of one: «this asset ran on three platforms» is a
            // per-GROUP question, and a boolean cannot be grouped by. Null for a lone creative.
            'group_id' => $creative->creative_group_id === null ? null : (string) $creative->creative_group_id,
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
            /*
             * No `ad` here either, and for the same reason as the card's `ad_id`.
             *
             * A provider id labelled «Ad» reads as THE ad's id. It was
             * `external_creatives.external_ad_id` — whichever ad was imported last — so on an account
             * where four ads share a creative it named one and looked authoritative. The ads are in
             * `ads` above, each with its own `external_id`, which is the same fact without the
             * false singular.
             */
            'external_ids' => [
                'creative' => $creative->external_creative_id,
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
     *     aspect: 'vertical'|'square'|'horizontal'|null,
     *     expires_at: string|null,
     *     note_ar: string|null,
     *     note_en: string|null,
     *     cards: list<array<string, mixed>>|null,
     *     cards_reported: bool,
     *     cards_withheld: int
     * }
     */
    public function preview(ExternalCreative $creative): array
    {
        $kind = $this->kind($creative);
        $aspect = $this->aspect($creative);

        $image = $this->safe($creative->asset_url) ?? $this->safe($creative->preview_url);
        $video = $this->safe($creative->video_url);
        $thumb = $this->safe($creative->thumbnail_url);

        $blocked = $this->wasWithheld($creative);

        $shape = match (true) {
            $blocked => [
                'state' => 'withheld',
                'kind' => $kind,
                'aspect' => $aspect,
                'image_url' => null, 'video_url' => null, 'thumbnail_url' => null,
                'expires_at' => null,
                'note_ar' => 'رابط المعاينة من المنصة يحمل بيانات اعتماد، فلا يُعرض.',
                'note_en' => 'The platform’s preview link carries a credential, so it is not shown.',
            ],
            $creative->assetExpired() => [
                'state' => 'expired',
                'kind' => $kind,
                'aspect' => $aspect,
                'image_url' => null, 'video_url' => null,
                'thumbnail_url' => $thumb,
                'expires_at' => $creative->asset_expires_at?->toIso8601String(),
                'note_ar' => 'انتهت صلاحية رابط المنصة — يحتاج مزامنة جديدة.',
                'note_en' => 'The platform link has expired — it needs a fresh sync.',
            ],
            /*
             * AD-MEDIA-RECOVERY-001 — «never fetched» is not «the platform refused».
             *
             * Every asset-less creative said «This platform does not expose the creative's asset»,
             * and for most of them that sentence was a false accusation. A row whose `source_type` is
             * `estimated` was DERIVED from ad-level performance — the product inferred that a
             * creative existed because spend and impressions were attributed to one — and no request
             * for its asset was ever made. Blaming Google for an asset nobody asked Google for tells
             * the reader the integration is failing when it is not, and hides the real reason, which
             * is that this row is an inference rather than a fetched ad.
             *
             * The owner met this on the content library: cards reading «Video · Hero Video · Demo ·
             * Google Ads» above «This platform does not expose the creative's asset».
             */
            $image === null && $video === null && $thumb === null && $creative->cards === null
                && $creative->source_type === 'estimated' => [
                    'state' => 'never_fetched',
                    'kind' => $kind,
                    'aspect' => $aspect,
                    'image_url' => null, 'video_url' => null, 'thumbnail_url' => null,
                    'expires_at' => null,
                    'note_ar' => 'هذا الصف مُستنتج من أداء الإعلان، ولم يُجلب الإعلان نفسه من المنصة — فلا يوجد أصل لعرضه.',
                    'note_en' => 'This row was derived from ad-level performance; the ad itself was never fetched from the platform, so there is no asset to show.',
                ],
            /*
             * CONTENT-PREVIEW-SHAPES-001 — «the platform exposed no asset» is FALSE for these.
             *
             * A collection ad is a hero over a grid of product tiles, and a catalog ad has no fixed
             * asset at all — the platform composes one per product at delivery. For a collection the
             * tiles live behind a call this product does not yet make: Snapchat's connector resolves
             * `top_snap_media_id` and nothing else, and `MetaConnector` is the only place in the tree
             * that has ever written `cards`.
             *
             * So an empty collection reached the reader as «This ad was fetched from the platform,
             * and the platform exposed no asset for it» — the same false accusation AD-MEDIA-
             * RECOVERY-001 removed for `estimated` rows, made again for a different reason. Snapchat
             * exposes the tiles; nobody asked for them.
             *
             * The sentence says whose gap it is. It is deliberately NOT a promise about when: a
             * status line that says «coming soon» ages into a lie, and this one stays true either
             * way. The `catalog` kind keeps its own separate wording in the UI — a catalog ad is not
             * missing anything, it simply has no fixed asset — so only `collection` lands here.
             */
            $kind === 'collection' && $image === null && $video === null && $thumb === null
                && $creative->cards === null => [
                    'state' => 'shape_not_fetched',
                    'kind' => $kind,
                    'aspect' => $aspect,
                    'image_url' => null, 'video_url' => null, 'thumbnail_url' => null,
                    'expires_at' => null,
                    'note_ar' => 'هذا إعلان تشكيلة: صورة رئيسية فوق شبكة منتجات. المنصة تتيح البطاقات، ولم يطلبها النظام بعد — فلا شيء يُعرض هنا، والنقص عندنا لا عند المنصة.',
                    'note_en' => 'This is a collection ad — a hero asset over a grid of product tiles. The platform does expose the tiles; this product does not fetch them yet, so there is nothing to show and the gap is ours, not the platform’s.',
                ],
            $image === null && $video === null && $thumb === null && $creative->cards === null => [
                'state' => 'unavailable',
                'kind' => $kind,
                'aspect' => $aspect,
                'image_url' => null, 'video_url' => null, 'thumbnail_url' => null,
                'expires_at' => null,
                'note_ar' => 'جُلب هذا الإعلان من المنصة، ولم تُتِح المنصة أصل المحتوى.',
                'note_en' => 'This ad was fetched from the platform, and the platform exposed no asset for it.',
            ],
            default => [
                'state' => 'available',
                'kind' => $kind,
                'aspect' => $aspect,
                'image_url' => $image,
                'video_url' => $video,
                'thumbnail_url' => $thumb,
                'expires_at' => $creative->asset_expires_at?->toIso8601String(),
                'note_ar' => null,
                'note_en' => null,
            ],
        };

        return $shape + $this->cards($creative, $shape['state']);
    }

    /**
     * The cards of a carousel — and an honest answer when there are none to give.
     *
     * ## Why this is not «nice to have»
     *
     * The columns a creative is synced into are singular: one `asset_url`, one `headline`, one
     * `destination_url`. A five-card carousel poured into them KEEPS THE FIRST and drops the rest, and
     * every surface then renders a fifth of what ran with nothing on screen saying so. That is a wrong
     * answer, not a missing feature — a reader comparing «the carousel» against a video is comparing
     * one of its cards.
     *
     * ## Three states, not two
     *
     *   - `cards_reported: false` — the provider sent no breakdown. The single asset above is all
     *     there is to show, and the UI says that rather than implying the carousel has one card.
     *   - `cards_reported: true` with a list — the real cards, in the order they ran.
     *   - `cards_reported: true` with `[]` — it sent a breakdown and it was empty. Rare, and still not
     *     the same sentence as the first.
     *
     * Every URL goes through the SAME `safe()` guard as the creative's own asset. A card link is a
     * platform link and carries the same signed credentials; withholding the parent's and passing the
     * children's straight through would have made this method the leak.
     *
     * @return array{cards: list<array<string, mixed>>|null, cards_reported: bool, cards_withheld: int}
     */
    private function cards(ExternalCreative $creative, string $state): array
    {
        $raw = $creative->cards;

        if (! is_array($raw)) {
            return ['cards' => null, 'cards_reported' => false, 'cards_withheld' => 0];
        }

        // A withheld or expired parent means every child link is withheld or expired too — they came
        // from the same response and carry the same credential.
        if ($state === 'withheld' || $state === 'expired') {
            return ['cards' => [], 'cards_reported' => true, 'cards_withheld' => count($raw)];
        }

        $cards = [];
        $withheld = 0;

        foreach (array_values($raw) as $index => $card) {
            if (! is_array($card)) {
                continue;
            }

            $given = static fn (string $key): ?string => is_string($card[$key] ?? null) ? $card[$key] : null;

            $image = $this->safe($given('image_url')) ?? $this->safe($given('asset_url'));
            $video = $this->safe($given('video_url'));
            $thumb = $this->safe($given('thumbnail_url'));

            $hadLink = $given('image_url') ?? $given('asset_url') ?? $given('video_url') ?? $given('thumbnail_url');

            if ($image === null && $video === null && $thumb === null && $hadLink !== null) {
                // It HAD a link and the guard refused it. Counted rather than dropped silently, so
                // «three of five cards are shown» is a sentence the page can actually say.
                $withheld++;

                continue;
            }

            $cards[] = [
                'index' => $index,
                'kind' => $video !== null ? 'video' : 'image',
                'image_url' => $image,
                'video_url' => $video,
                'thumbnail_url' => $thumb,
                'headline' => $this->text($card['headline'] ?? null),
                'body' => $this->text($card['body'] ?? null),
                'cta' => $this->text($card['cta'] ?? null),
                'destination_url' => $this->text($card['destination_url'] ?? null),
            ];
        }

        return ['cards' => $cards, 'cards_reported' => true, 'cards_withheld' => $withheld];
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * The SHAPE of the frame this creative fills — CONTENT-PREVIEW-SHAPES-001.
     *
     * A story or a reel is 9:16. Shown in the square frame every preview used, it is letterboxed into
     * a third of the space or cropped through its own subject, and the reader is looking at a
     * different ad from the one that ran. The columns to answer this have been synced all along —
     * `width`, `height`, `aspect_ratio` — and no surface could see them, because the preview payload
     * did not carry the answer.
     *
     * Measured before it is parsed: real pixel dimensions are the provider's own measurement, while
     * `aspect_ratio` is a label somebody wrote. Null when neither is present, which is «the platform
     * did not say» — a frame then keeps the neutral shape it has always had rather than guessing tall.
     */
    private function aspect(ExternalCreative $creative): ?string
    {
        $w = (int) ($creative->width ?? 0);
        $h = (int) ($creative->height ?? 0);

        if ($w > 0 && $h > 0) {
            return $this->nameRatio($w / $h);
        }

        $label = trim((string) ($creative->aspect_ratio ?? ''));

        if (preg_match('/^(\d+(?:\.\d+)?)\s*[:x\/]\s*(\d+(?:\.\d+)?)$/i', $label, $m) === 1 && (float) $m[2] > 0) {
            return $this->nameRatio((float) $m[1] / (float) $m[2]);
        }

        return null;
    }

    /**
     * Where the line between «square» and the two others falls.
     *
     * A 15% band, so 4:5 (0.8) reads as vertical — Meta's most common feed portrait, and tall enough
     * that a square frame crops it — while 1:1 and the small rounding errors either side of it do not.
     */
    private function nameRatio(float $ratio): string
    {
        return match (true) {
            $ratio < 0.87 => 'vertical',
            $ratio > 1.15 => 'horizontal',
            default => 'square',
        };
    }

    private function kind(ExternalCreative $creative): string
    {
        $format = strtolower((string) $creative->format);

        /*
         * CONTENT-PREVIEW-SHAPES-001 — the shapes a real ad actually takes.
         *
         * Two more, and both were reading as something they are not. A COLLECTION ad is a hero asset
         * over a grid of tiles: rendering the hero alone shows a reader one sixth of the ad and calls
         * it the ad. A CATALOG ad — Meta's dynamic product ads, Snapchat's and TikTok's equivalents —
         * has no single creative at all: the platform composes one per product at delivery, so there
         * is nothing to show and «no media» was the wrong sentence, because it implies something is
         * missing rather than that the shape has no fixed asset.
         *
         * Ordered before the generic `image`/`video` contains-checks: a format string like
         * «collection_video» names a collection whose hero is a film, and the collection is the more
         * specific truth.
         */
        return match (true) {
            str_contains($format, 'collection') => 'collection',
            str_contains($format, 'catalog') || str_contains($format, 'dynamic_product') || str_contains($format, 'dpa') => 'catalog',
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

        /*
         * A ROOT-RELATIVE path is ours, and it is the only portable way to store our own asset.
         *
         * AD-MEDIA-RECOVERY-001: the demo video was stored as an absolute URL built from `APP_URL` at
         * SEED time — «http://127.0.0.1:8000/demo/creative-sample.mp4». It plays only while a server
         * happens to be listening on that exact port, so every demo video died the moment the app ran
         * anywhere else, which is most of the time. A path resolves against whatever origin serves
         * the page and survives a port, a host and a deploy.
         *
         * `//host/path` is deliberately excluded: it looks relative and is protocol-relative, which
         * points at another origin entirely — exactly what the scheme check below exists to stop.
         */
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
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
