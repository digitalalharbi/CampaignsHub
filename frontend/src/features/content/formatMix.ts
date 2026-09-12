import type { FormatRow } from './api'

/**
 * CONTENT-SUMMARY-COMPACT-001 — the share of spend each content format took.
 *
 * ## Why a module and not a bar drawn inline
 *
 * «A share over an incomplete total overstates itself» is the rule the server already applies to
 * `share_of_spend_not_on_the_leading_format`, and a bar chart is a share by another name. A format
 * whose spend the money contract withheld cannot contribute to a denominator, and dividing by a
 * total that is missing one of its parts inflates every other slice — silently, and by exactly the
 * amount nobody can see.
 *
 * So the arithmetic lives here, with the refusal in it, rather than in a component that would have
 * to remember.
 */
export type FormatShare = {
  format: string
  spend: number
  creatives: number
  /** Of the comparable total, 0–1. */
  share: number
}

export type FormatMix = {
  shares: FormatShare[]
  /** Formats that ran and whose spend could not be added — named, never folded into «other». */
  withheld: string[]
  total: number
}

/**
 * The mix, or an honest refusal to state one.
 *
 * A format with a withheld spend is REPORTED rather than dropped: «video ran and we cannot price it»
 * is a different statement from «video did not run», and only one of them is true. The shares are
 * computed over the comparable subset and the withheld formats are named beside them, so a reader
 * can see that the bar does not account for everything.
 */
export function formatMix(rows: FormatRow[] | undefined): FormatMix {
  const present = rows ?? []
  const comparable = present.filter((r) => typeof r.spend === 'number' && (r.spend as number) > 0)
  const withheld = present.filter((r) => r.spend === null).map((r) => r.format)
  const total = comparable.reduce((sum, r) => sum + (r.spend as number), 0)

  if (total <= 0) {
    return { shares: [], withheld, total: 0 }
  }

  return {
    shares: comparable
      .map((r) => ({
        format: r.format,
        spend: r.spend as number,
        creatives: r.creatives,
        share: (r.spend as number) / total,
      }))
      .sort((a, b) => b.share - a.share),
    withheld,
    total,
  }
}
