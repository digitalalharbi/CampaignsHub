/**
 * PLATFORM-ORDER-001 — the six platforms, in one order, for the whole interface.
 *
 *   1. سناب شات   2. تيك توك   3. ميتا   4. جوجل أدز   5. إكس   6. لينكدإن
 *
 * The mirror of `App\Support\AdPlatforms` on the server. Both exist because both render lists: the
 * API sorts what it returns, and the client sorts what it composes locally — demo data, filter chips,
 * chart series, form options. One of the two alone would leave the other free to disagree.
 *
 * Before this, the dashboard led with Meta, the connection centre led with Meta, the integrations
 * page led with Meta and the report engine led with Snapchat. Each was a literal beside the code that
 * rendered it, so a customer moving between two screens found the same platform in a different place
 * — and changing the order meant finding six lists.
 *
 * Keys are canonicalised before comparison, because the same platform is legitimately spelled several
 * ways here: connectors register `google_ads`, taxonomy stores `google`, the connection centre keys
 * channels `google_ads` because it also carries analytics and CRM channels.
 */

export const PLATFORM_ORDER = ['snapchat', 'tiktok', 'meta', 'google', 'x', 'linkedin'] as const

export type AdPlatform = (typeof PLATFORM_ORDER)[number]

/** Every spelling this codebase uses, mapped to its canonical key. */
const ALIASES: Record<string, AdPlatform> = {
  snap: 'snapchat',
  snapchat_ads: 'snapchat',
  tiktok_ads: 'tiktok',
  meta_ads: 'meta',
  facebook: 'meta',
  facebook_ads: 'meta',
  instagram: 'meta',
  google_ads: 'google',
  googleads: 'google',
  twitter: 'x',
  x_ads: 'x',
  twitter_ads: 'x',
  linkedin_ads: 'linkedin',
}

export function canonicalPlatform(key: string | null | undefined): string {
  const k = (key ?? '').trim().toLowerCase()
  return ALIASES[k] ?? k
}

/**
 * Where this platform sits in the product's order.
 *
 * An unknown key ranks after every known one rather than throwing: a platform that appears in a
 * payload before the interface knows about it should slot in at the end of a list, not break the
 * page rendering it.
 */
export function platformRank(key: string | null | undefined): number {
  const index = (PLATFORM_ORDER as readonly string[]).indexOf(canonicalPlatform(key))
  return index === -1 ? PLATFORM_ORDER.length : index
}

/** Sort platform keys into the product's order. Stable, so unknown platforms keep their arrival order. */
export function sortPlatforms<T extends string>(keys: readonly T[]): T[] {
  return [...keys].sort((a, b) => platformRank(a) - platformRank(b))
}

/** Sort rows by the platform found at `key`. */
export function sortByPlatform<T>(rows: readonly T[], key: (row: T) => string | null | undefined): T[] {
  return [...rows].sort((a, b) => platformRank(key(a)) - platformRank(key(b)))
}
