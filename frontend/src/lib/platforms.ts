/**
 * PLATFORM-ORDER-001 — the paid-media platforms, in one order, for the whole interface.
 *
 *   1. سناب شات   2. تيك توك   3. ميتا   4. جوجل أدز   5. إكس   6. لينكدإن   7. إعلانات ChatGPT
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

/*
 * The count is not part of the contract.
 *
 * This file and its backend twin both used to describe «the six platforms», and that number became
 * an architectural assumption by repetition rather than by anybody choosing it. The list is the only
 * thing that decides how many there are; `CanonicalPlatformOrderTest` on the server proves this copy
 * and `AdPlatforms::ORDER` are the same list in the same order, so neither side can drift.
 */
export const PLATFORM_ORDER = ['snapchat', 'tiktok', 'meta', 'google', 'x', 'linkedin', 'openai_ads'] as const

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
  /* The provider is OpenAI and the product is ChatGPT Ads, so both names arrive in the wild. */
  openai: 'openai_ads',
  chatgpt: 'openai_ads',
  chatgpt_ads: 'openai_ads',
  openai_advertising: 'openai_ads',
}

/**
 * What each platform is CALLED, in both languages, in one place.
 *
 * Six files kept their own copy of this map, and each one was a separate decision about whether a
 * platform exists: a list that had not been edited simply rendered the key — «openai_ads» where a
 * reader expects «إعلانات ChatGPT» — and nothing failed, because printing a database value is
 * indistinguishable from printing a label until somebody reads it.
 *
 * Arabic is not a transliteration of the English. «سناب شات» is what the platform is called by the
 * people buying on it; `ChatGPT Ads` is a product name and stays Latin inside the Arabic string,
 * which is how it is written and said here.
 */
export const PLATFORM_LABELS: Record<AdPlatform, { ar: string; en: string }> = {
  snapchat: { ar: 'سناب شات', en: 'Snapchat' },
  tiktok: { ar: 'تيك توك', en: 'TikTok' },
  meta: { ar: 'ميتا', en: 'Meta' },
  google: { ar: 'جوجل', en: 'Google' },
  x: { ar: 'إكس', en: 'X' },
  linkedin: { ar: 'لينكدإن', en: 'LinkedIn' },
  openai_ads: { ar: 'إعلانات ChatGPT', en: 'ChatGPT Ads' },
}

/**
 * The platform's name for a reader, from whatever spelling arrived.
 *
 * An unknown key comes back as itself rather than as «—» or «Unknown»: a platform this build has
 * not heard of is a fact about this build, and showing the raw key is how somebody finds out which
 * one it is. It is the loud failure, deliberately, and `canonicalPlatform` has already resolved
 * every spelling the product actually uses.
 */
export function platformLabel(key: string | null | undefined, ar: boolean): string {
  const canonical = canonicalPlatform(key)
  const label = PLATFORM_LABELS[canonical as AdPlatform]

  return label === undefined ? canonical : (ar ? label.ar : label.en)
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
