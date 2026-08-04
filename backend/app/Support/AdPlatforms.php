<?php

declare(strict_types=1);

namespace App\Support;

/**
 * PLATFORM-ORDER-001 — the six platforms, in one order, for the whole product.
 *
 * The order is a product decision, not a preference of whichever screen is being written:
 *
 *   1. سناب شات   2. تيك توك   3. ميتا   4. جوجل أدز   5. إكس   6. لينكدإن
 *
 * Before this class every surface picked its own. The integrations page led with Meta, the dashboard
 * led with Meta, the connection centre led with Meta, the report engine led with Snapchat, and the
 * campaigns charts led with whatever the API happened to return first. A customer moving between two
 * screens had to find the same platform in a different place each time — and because each list was a
 * literal beside the code that rendered it, "fix the order" meant finding six of them.
 *
 * ## Keys are messy, and that is not the caller's problem
 *
 * The same platform is spelled several ways across this codebase for reasons that are themselves
 * legitimate: connectors register `google_ads`, taxonomy stores `google`, the connection centre keys
 * channels `google_ads` because it also carries analytics and CRM channels. `rank()` canonicalises
 * before it compares, so a list may hold any of those spellings and still sort correctly.
 *
 * ## What it does NOT do
 *
 * It does not decide which platforms exist, or which are enabled, or what they are called in Arabic.
 * Those live where they belong — the connector registry, the taxonomy, the label maps. This answers
 * one question: given two platform keys, which comes first.
 */
final class AdPlatforms
{
    /**
     * The canonical order. Index is the rank.
     *
     * @var list<string>
     */
    public const ORDER = ['snapchat', 'tiktok', 'meta', 'google', 'x', 'linkedin'];

    /**
     * Every spelling this codebase uses, mapped to its canonical key.
     *
     * Deliberately generous. A key that is not recognised sorts last rather than throwing: a new
     * platform appearing in a payload should slot in at the end of a list, not break the page that
     * renders it.
     *
     * @var array<string, string>
     */
    private const ALIASES = [
        'snap' => 'snapchat',
        'snapchat_ads' => 'snapchat',
        'tiktok_ads' => 'tiktok',
        'meta_ads' => 'meta',
        'facebook' => 'meta',
        'facebook_ads' => 'meta',
        'instagram' => 'meta',
        'google_ads' => 'google',
        'googleads' => 'google',
        'twitter' => 'x',
        'x_ads' => 'x',
        'twitter_ads' => 'x',
        'linkedin_ads' => 'linkedin',
    ];

    /** The canonical key for any spelling of a platform. */
    public static function canonical(?string $key): string
    {
        $key = strtolower(trim((string) $key));

        return self::ALIASES[$key] ?? $key;
    }

    /**
     * Where this platform sits in the product's order.
     *
     * An unknown key ranks after every known one — and ties are broken by the caller's own ordering,
     * so an unrecognised platform keeps whatever relative position the data gave it rather than
     * jumping about between renders.
     */
    public static function rank(?string $key): int
    {
        $position = array_search(self::canonical($key), self::ORDER, true);

        return $position === false ? count(self::ORDER) : $position;
    }

    /**
     * Sort a list of platform keys.
     *
     * @param  iterable<string>  $keys
     * @return list<string>
     */
    public static function sort(iterable $keys): array
    {
        $list = is_array($keys) ? array_values($keys) : iterator_to_array($keys, false);

        // A STABLE sort, so unknown platforms — which all rank equal — keep the order they arrived in
        // instead of being shuffled by the comparison function on every call.
        usort($list, static fn (string $a, string $b) => self::rank($a) <=> self::rank($b));

        return $list;
    }

    /**
     * Sort rows by the platform found at `$key`.
     *
     * @template T of array<string, mixed>
     *
     * @param  iterable<T>  $rows
     * @return list<T>
     */
    public static function sortRows(iterable $rows, string $key = 'platform'): array
    {
        $list = is_array($rows) ? array_values($rows) : iterator_to_array($rows, false);

        usort($list, static fn (array $a, array $b) => self::rank($a[$key] ?? null) <=> self::rank($b[$key] ?? null));

        return $list;
    }
}
