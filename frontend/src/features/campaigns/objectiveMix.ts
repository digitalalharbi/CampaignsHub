import { rankableMoney, type MoneyFields } from '@/lib/money/contract'
import { canonicalOfRaw, CANONICAL_OBJECTIVE_KEYS, type CanonicalObjectiveKey } from './canonicalObjectives'

/**
 * CAMPAIGNS-OVERVIEW-FIRST-001 — where the portfolio's money is going, by what it is FOR.
 *
 * «Segment campaigns visually by objective … show cost by stage where the data supports it.» The
 * overview could say what the portfolio spent and which platform it went to; it could not say how
 * much of it was buying sales and how much was buying awareness, which is the composition question
 * an operator is actually asked in a review.
 *
 * ## Why this is not the funnel
 *
 * Analytics already draws the conversion funnel, and «Analytics must go materially deeper, not the
 * same blocks reordered» is a standing rule. A funnel decomposes ONE journey; this decomposes the
 * PORTFOLIO across the objectives it was bought for, which is a different question and a different
 * grain — and it uses the canonical five, so Content, Analytics and Campaigns name an objective the
 * same way.
 *
 * ## The money contract, not a sum
 *
 * Spend is totalled through `rankableMoney`, the same reader the platform donut and the campaign
 * ranking on this page already use. A mixed-currency or partially withheld axis REFUSES rather than
 * adding riyals to dollars, and the refusal is returned so the caller can say so. Writing a `reduce`
 * here would have been the third money truth on one page.
 *
 * ## An objective with no campaigns is absent, not zero
 *
 * A portfolio that has never run an app-install campaign has no app-install row. A zero would invite
 * «why is app promotion failing» about money nobody spent.
 */
export interface ObjectiveMixRow {
  key: CanonicalObjectiveKey
  campaigns: number
  spend: number | null
  results: number
}

export interface ObjectiveMix {
  rows: ObjectiveMixRow[]
  currency: string | null
  /** Campaigns whose spend could not be added to the others — named, never silently dropped. */
  dropped: number
  /** Campaigns carrying an objective the canonical taxonomy does not recognise. */
  unclassified: number
}

/**
 * A campaign row as this reader needs it — the money contract's own fields, plus the two facts it
 * groups by. `MoneyTotals` is a union including `undefined`, so this INTERSECTS the concrete half
 * rather than extending the union.
 */
type MixInput = MoneyFields & {
  objective?: string | null
  conversions?: number | null
}

export function objectiveMix(rows: MixInput[], reportingCurrency: string | null): ObjectiveMix | null {
  const byKey = new Map<CanonicalObjectiveKey, MixInput[]>()
  let unclassified = 0

  for (const row of rows) {
    const canonical = row.objective === null || row.objective === undefined ? null : canonicalOfRaw(row.objective)

    if (canonical === null) {
      unclassified += 1
      continue
    }

    byKey.set(canonical, [...(byKey.get(canonical) ?? []), row])
  }

  /*
   * The money is read ONCE, across the whole portfolio, rather than per objective.
   *
   * Per-objective calls would each decide independently whether their slice is addable, so a
   * portfolio that is mixed overall could still draw two «complete» bars — a refusal that applies to
   * the axis, silently not applied to the axis.
   */
  const money = rankableMoney(rows, 'spend', reportingCurrency)

  if (money === null) {
    return null
  }

  const spendByRow = new Map<MixInput, number | null>()
  rows.forEach((row, i) => spendByRow.set(row, money.values[i] ?? null))

  const out: ObjectiveMixRow[] = []

  for (const key of CANONICAL_OBJECTIVE_KEYS) {
    const group = byKey.get(key)
    if (group === undefined || group.length === 0) continue

    const spends = group.map((r) => spendByRow.get(r) ?? null)
    const known = spends.filter((v): v is number => v !== null)

    out.push({
      key,
      campaigns: group.length,
      /* All refused is «unknown», not zero — a total nobody could compute is not a total of nothing. */
      spend: known.length === 0 ? null : known.reduce((a, b) => a + b, 0),
      results: group.reduce((sum, r) => sum + (r.conversions ?? 0), 0),
    })
  }

  return { rows: out, currency: money.currency, dropped: money.dropped, unclassified }
}
