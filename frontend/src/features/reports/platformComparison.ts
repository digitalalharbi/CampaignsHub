import { canonicalPlatform } from '@/lib/platforms'
import { providerLabel } from '@/features/campaigns/labels'

/**
 * LIVE-CROSS-PLATFORM-001 — one row per platform, and the arithmetic it is allowed to do.
 *
 * The live page drew spend over time and a share-of-spend donut, and neither answers «which platform
 * did better»: a share of spend says how much went somewhere, not what it bought. This builds the
 * rows that do.
 *
 * It lives apart from the component because the component fetches its own payload by token, so the
 * only way to test the rules below through it would be to mock a network call and assert on rendered
 * text — which tests the table, not the arithmetic. The rules are the part worth pinning.
 */
export interface PlatformRow {
  /* Indexable so the shared table can read a row by column key without a cast at every call site. */
  [key: string]: number | string | null
  platform: string
  spend: number | null
  results: number | null
  cost_per_result: number | null
  impressions: number | null
  clicks: number | null
  ctr: number | null
}

/** A figure or nothing — `undefined`, a string or a NaN is not a number a report may print. */
function num(row: Record<string, unknown>, key: string): number | null {
  const raw = row[key]

  return typeof raw === 'number' && Number.isFinite(raw) ? raw : null
}

export function platformComparisonRows(
  platforms: ReadonlyArray<Record<string, unknown> & { provider: string }>,
  locale: 'ar' | 'en',
): PlatformRow[] {
  return platforms.map((p) => {
    const spend = num(p, 'spend')
    const results = num(p, 'conversions')
    const impressions = num(p, 'impressions')
    const clicks = num(p, 'clicks')

    return {
      // The platform's NAME, not its key: «snapchat» is a database value, «سناب شات» is a platform.
      platform: providerLabel(canonicalPlatform(p.provider), locale),
      spend,
      results,
      /*
       * Derived ONLY where both sides are real.
       *
       * A cost per result over a WITHHELD spend reads as free, and over ZERO results is a division by
       * zero that prints as «Infinity» on a client's report. Both are figures this product does not
       * have, and a table that invents them is worse than one that leaves the cell empty.
       */
      cost_per_result: spend !== null && results !== null && results > 0 ? spend / results : null,
      impressions,
      clicks,
      ctr: clicks !== null && impressions !== null && impressions > 0 ? clicks / impressions : null,
    }
  })
}
