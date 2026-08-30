/**
 * OBJECTIVE-ANALYTICS-DEPTH-001 — where a family's money actually went.
 *
 * A family card says what the family spent and produced. «Where is the budget concentrated» is a
 * different question and the one an operator opens this tab with: eight sales campaigns with 90% of
 * the money in one of them is a fact about a decision, and it is invisible in a total.
 *
 * ## The share is of the FAMILY, never of the account
 *
 * The same rule the platform paths follow, one level down. «40% of sales» is a decision somebody
 * made; «40% of everything» is the mix, and reading the second as concentration would make a family
 * that happens to be small look concentrated and a large one look spread.
 *
 * ## One campaign is not a distribution
 *
 * A single bar at 100% is not information; it is the shape of an answer with no question. The
 * caller is told so rather than being handed a chart that says nothing.
 */
export type DistributionSlice = {
  name: string
  spend: number
  /** Of this family's spend. */
  share: number
}

export type FamilyDistribution = {
  slices: DistributionSlice[]
  /** Everything below the named ones, when there is more than one of them. */
  rest: DistributionSlice | null
  total: number
  /** False when the family has fewer than two SPENDING campaigns. */
  meaningful: boolean
}

export function distributionFor(
  campaigns: readonly { name?: string | null; spend?: number | null }[],
  top = 3,
): FamilyDistribution {
  const spending = campaigns
    .map((c) => ({ name: (c.name ?? '—').toString(), spend: Number(c.spend ?? 0) }))
    .filter((c) => c.spend > 0)
    .sort((a, b) => b.spend - a.spend)

  const total = spending.reduce((n, c) => n + c.spend, 0)

  if (total <= 0 || spending.length < 2) {
    return { slices: [], rest: null, total, meaningful: false }
  }

  const named = spending.slice(0, top).map((c) => ({ ...c, share: c.spend / total }))
  const remainder = spending.slice(top)
  const restSpend = remainder.reduce((n, c) => n + c.spend, 0)

  return {
    slices: named,
    /*
     * «Other» is only drawn when it stands for MORE THAN ONE campaign. A single campaign hidden
     * behind the word «other» is a name the reader was entitled to and did not get.
     */
    rest: remainder.length > 1 ? { name: 'rest', spend: restSpend, share: restSpend / total } : null,
    total,
    meaningful: true,
  }
}
