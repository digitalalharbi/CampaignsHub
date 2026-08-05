<?php

declare(strict_types=1);

namespace App\Domains\Commerce\ValueObjects;

/**
 * COMMERCE-002 — where an order came from, read only from what the store actually recorded.
 *
 * ## The click id is the honest half, and the UTM is the convenient one
 *
 * A UTM is typed by whoever built the link. It is useful, it is what a media buyer names their
 * campaign with, and it is also editable, copyable and frequently wrong — two agencies using
 * `utm_campaign=ramadan` are two different campaigns with one name. A CLICK ID is minted by the
 * platform at the moment of the click and identifies exactly one ad click, which is why the resolver
 * prefers it and why both are kept rather than one being collapsed into the other.
 *
 *   gclid → Google · fbclid → Meta · ttclid → TikTok · sclid/ScCid → Snapchat · twclid → X
 *   li_fat_id → LinkedIn · msclkid → Microsoft, kept because it appears and must not be mistaken
 *   for one of ours
 *
 * ## Nothing here is invented
 *
 * Neither Salla nor Zid guarantees an attribution object on an order. What they do carry, when the
 * merchant's storefront passed it through, is the landing URL the session started on — so the query
 * string is parsed, and an order with nothing usable becomes an attribution with every field null.
 * That order then shows as «مصدر غير معروف» in the funnel, which is true. Guessing a campaign from a
 * referrer domain would make a paid conversion out of somebody who typed the address in.
 */
final readonly class Attribution
{
    /**
     * Query parameters that carry a platform's own click identifier, mapped to the provider they
     * belong to. Order matters only for readability; a URL carrying two is recorded as the first
     * match and the rest survive in the order's raw payload.
     *
     * @var array<string,string>
     */
    private const CLICK_IDS = [
        'gclid' => 'google',
        'gbraid' => 'google',
        'wbraid' => 'google',
        'fbclid' => 'meta',
        'ttclid' => 'tiktok',
        'sclid' => 'snapchat',
        'ScCid' => 'snapchat',
        'twclid' => 'x',
        'li_fat_id' => 'linkedin',
        'msclkid' => 'microsoft',
    ];

    public function __construct(
        public ?string $source = null,
        public ?string $medium = null,
        public ?string $campaign = null,
        public ?string $content = null,
        public ?string $term = null,
        public ?string $clickId = null,
        public ?string $clickIdProvider = null,
        public ?string $landingUrl = null,
        public ?string $referrer = null,
    ) {}

    /** True when the store told us nothing at all — which is a fact worth being able to count. */
    public function isEmpty(): bool
    {
        return $this->source === null
            && $this->medium === null
            && $this->campaign === null
            && $this->clickId === null;
    }

    /**
     * Read attribution out of whatever the provider handed over.
     *
     * `$explicit` is any key/value the provider states directly (Salla's order `source`, a custom
     * checkout field); `$landingUrl` and `$referrer` are parsed for the rest. Explicit values win,
     * because a store that says `utm_campaign` outright is more reliable than a URL that may have been
     * rewritten by a redirect on the way in.
     *
     * @param  array<string,mixed>  $explicit
     */
    public static function read(array $explicit = [], ?string $landingUrl = null, ?string $referrer = null): self
    {
        $fromUrl = self::queryOf($landingUrl);

        $pick = static function (string $key) use ($explicit, $fromUrl): ?string {
            foreach ([$explicit[$key] ?? null, $fromUrl[$key] ?? null] as $value) {
                if (is_string($value) && trim($value) !== '') {
                    return mb_substr(trim($value), 0, 190);
                }
            }

            return null;
        };

        [$clickId, $clickProvider] = self::clickIdIn($explicit, $fromUrl);

        return new self(
            source: $pick('utm_source'),
            medium: $pick('utm_medium'),
            campaign: $pick('utm_campaign'),
            content: $pick('utm_content'),
            term: $pick('utm_term'),
            clickId: $clickId,
            clickIdProvider: $clickProvider,
            landingUrl: self::trimmed($landingUrl),
            referrer: self::trimmed($referrer),
        );
    }

    /** @return array<string,mixed> the columns an order or cart stores */
    public function toColumns(): array
    {
        return [
            'utm_source' => $this->source,
            'utm_medium' => $this->medium,
            'utm_campaign' => $this->campaign,
            'utm_content' => $this->content,
            'utm_term' => $this->term,
            'click_id' => $this->clickId,
            'click_id_provider' => $this->clickIdProvider,
            'landing_url' => $this->landingUrl,
            'referrer_url' => $this->referrer,
        ];
    }

    /**
     * @param  array<string,mixed>  $explicit
     * @param  array<string,string>  $fromUrl
     * @return array{0:?string,1:?string}
     */
    private static function clickIdIn(array $explicit, array $fromUrl): array
    {
        foreach (self::CLICK_IDS as $key => $provider) {
            foreach ([$explicit[$key] ?? null, $fromUrl[$key] ?? null, $fromUrl[strtolower($key)] ?? null] as $value) {
                if (is_string($value) && trim($value) !== '') {
                    return [mb_substr(trim($value), 0, 190), $provider];
                }
            }
        }

        return [null, null];
    }

    /** @return array<string,string> */
    private static function queryOf(?string $url): array
    {
        if (! is_string($url) || trim($url) === '') {
            return [];
        }

        $query = parse_url($url, PHP_URL_QUERY);

        if (! is_string($query) || $query === '') {
            return [];
        }

        parse_str($query, $parsed);

        /** @var array<string,string> $flat */
        $flat = array_filter($parsed, 'is_string');

        return $flat;
    }

    private static function trimmed(?string $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        // The column holds a URL, and a storefront can produce a very long one; truncating is better
        // than refusing to record the order it belongs to.
        return $value === '' ? null : mb_substr($value, 0, 2000);
    }
}
