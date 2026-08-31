import { efficiencyFor, returnFor } from './pathEfficiency'
import type { PlatformPath } from './api'

/**
 * FUNNEL-ANALYTICAL-PATTERN-001 — the funnel's shape, applied to the platform block.
 *
 * ## What the block was
 *
 * Each path with its platforms, their spend, their share and — since the efficiency work — the cost
 * that names what that path was buying. That is the SIGNAL, and the reader is left to do the rest:
 * work out which platform is dearest, whether the difference is large, what it is measured on, and
 * what to do about it. Every time.
 *
 * ## The range is inside ONE path, and on ONE metric
 *
 * Comparing a platform buying awareness with one buying sales is the comparison this requirement
 * forbids, so the reading never crosses a path. Within a path, both ends are read on the same
 * efficiency — the one that path was paying for — because a range with two units is not a range.
 *
 * ## A path that cannot be compared has no reading
 *
 * `comparable` is false where fewer than two platforms actually spent, and «Meta is the cheapest
 * awareness buyer», said of the only platform that ran awareness, is a sentence with no evidence
 * behind it. The reason travels in the reading's place.
 */
export type PlatformReading = {
  path: string
  metric: 'cpm' | 'cpc' | 'cpv' | 'cpa'
  cheapest: { provider: string; value: number }
  dearest: { provider: string; value: number }
  /** How many times dearer the dear end is — the distance, stated once rather than left to arithmetic. */
  spread: number
  /** Only where the path was buying a return at all. */
  returns: { provider: string; value: number } | null
} | {
  path: string
  metric: null
  silentReason: 'not_comparable' | 'no_platform_reported_this_cost'
}

export function platformReadings(paths: readonly PlatformPath[]): PlatformReading[] {
  const out: PlatformReading[] = []

  for (const path of paths) {
    if (!path.comparable) {
      out.push({ path: path.path, metric: null, silentReason: 'not_comparable' })
      continue
    }

    /*
     * Only platforms whose efficiency could actually be computed. A platform that reported no
     * impressions has no cost per thousand, and reading it as zero would crown the platform nobody
     * measured as the cheapest — the most confident wrong answer this block could give.
     */
    const priced = path.platforms
      .map((row) => ({ provider: row.provider, efficiency: efficiencyFor(path.path, row) }))
      .filter((p): p is { provider: string; efficiency: { key: 'cpm' | 'cpc' | 'cpv' | 'cpa'; value: number; labelAr: string; labelEn: string } } =>
        p.efficiency.value !== null)

    if (priced.length < 2) {
      out.push({ path: path.path, metric: null, silentReason: 'no_platform_reported_this_cost' })
      continue
    }

    const sorted = [...priced].sort((a, b) => a.efficiency.value - b.efficiency.value)
    const cheapest = sorted[0]!
    const dearest = sorted[sorted.length - 1]!

    /* The best return on the path, and only where returning is what the path was for. */
    const returning = path.platforms
      .map((row) => ({ provider: row.provider, value: returnFor(path.path, row).value }))
      .filter((r): r is { provider: string; value: number } => r.value !== null)
      .sort((a, b) => b.value - a.value)[0] ?? null

    out.push({
      path: path.path,
      metric: cheapest.efficiency.key,
      cheapest: { provider: cheapest.provider, value: cheapest.efficiency.value },
      dearest: { provider: dearest.provider, value: dearest.efficiency.value },
      spread: cheapest.efficiency.value > 0
        ? Math.round((dearest.efficiency.value / cheapest.efficiency.value) * 100) / 100
        : 1,
      returns: returning,
    })
  }

  return out
}
