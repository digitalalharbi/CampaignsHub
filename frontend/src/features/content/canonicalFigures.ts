import { readMoney } from '@/lib/money/contract'
import type { MoneyTotals } from '@/lib/money/contract'

/**
 * OWNER CONTENT P0 — the figures ONE creative can answer, decided once for every surface.
 *
 * ## The reading this closes
 *
 * On production, the same creative in the same period: the CARD showed Spend beside Orders, ROAS and
 * Revenue, and the POPUP one click away showed impressions, clicks, CTR, CPC and CPM as well. Both
 * were reading real figures — neither invented one — and a reader comparing two surfaces learned that
 * the product disagrees with itself about what it knows.
 *
 * It happened in two places at once. The server capped `headline_metrics` at the number of cells the
 * card had room for, and the card then sliced the survivors to three. The panel, meanwhile, read the
 * row directly. So a layout decision, taken twice, silently decided what four surfaces could say.
 *
 * ## The rule
 *
 * A figure belongs to a creative when the creative ANSWERS it, and that question has one answer here:
 *
 *   - the objective's own metrics first, in the order the server chose — the first cell is the verdict
 *     and the objective is the only thing that knows which figure that is;
 *   - then the figures true of every campaign whatever it was bought for;
 *   - then revenue and return, which belong wherever the provider reported them.
 *
 * Nothing is invented and nothing is padded: a key survives only when this row can state it. Money is
 * asked of the money contract rather than of the column, because FX-001 withholds an unconvertible
 * amount by design and preserves the original beside it — «1,980.25 USD» is an answer, and treating it
 * as absent is how a real figure disappears from a card.
 *
 * How MANY of these a surface draws at once is still the surface's business. Which ones exist is not.
 */

/** The figures true of every campaign, whatever it was bought to do — `ObjectiveFamily::Unknown`, plus CPC. */
export const UNIVERSAL_FIGURES = ['impressions', 'clicks', 'ctr', 'cpc', 'cpm'] as const

/** Money is answerable in three of the contract's five states — a withheld original is a figure, not a gap. */
export function moneyIsStatable(metrics: MoneyTotals, key: 'spend' | 'revenue', currency: string | null, ar: boolean): boolean {
  const { kind } = readMoney(metrics, key, currency, ar)

  return kind === 'converted' || kind === 'withheld' || kind === 'zero'
}

/**
 * Every metric key this creative can state for this window, in the order a reader wants them.
 *
 * @param headline the server's objective-aware list for this creative — already availability-filtered
 * @param metrics  the creative's own figures, as the money contract's envelope
 */
/**
 * CREATIVE-GRAIN-TRUTH-001 — the result metrics, which an unattributed campaign must not re-append.
 *
 * Kept beside the appending loop rather than imported from the server's list, because this file is
 * the one that can put a withheld figure back and it should carry the reason why it must not.
 */
const RESULT_FIGURES = new Set(['revenue', 'roas', 'conversions', 'purchases', 'aov', 'cpa', 'leads', 'installs'])

export function canonicalFigureKeys(
  headline: readonly string[],
  metrics: MoneyTotals,
  currency: string | null,
  ar: boolean,
  /**
   * True when the platform reported this campaign's results and attributed none of them to any
   * creative beneath it. The server has already dropped those metrics from `headline`; the bag
   * still carries the platform's zeros, because the bag is what it sent.
   */
  resultsNotAttributable = false,
): string[] {
  const bag = (metrics ?? {}) as Record<string, unknown>
  const answers = (key: string): boolean =>
    key === 'spend' || key === 'revenue'
      ? moneyIsStatable(metrics, key, currency, ar)
      : typeof bag[key] === 'number'

  /*
   * The server's list is taken AS GIVEN, not re-filtered here.
   *
   * `CreativeMetrics::supportable()` has already asked whether this row answers each of the family's
   * metrics, and it keeps the distinction a second filter here would destroy: a metric the platform
   * does not report for this creative reads «not provided», and a ratio whose denominator is missing
   * reads «no data». Those are different sentences and the surface draws them — re-deciding
   * availability in the browser would replace both with silence.
   *
   * What is appended is only ever additive: a universal figure, or revenue and return, that this row
   * demonstrably holds and the list did not already carry.
   */
  const out = [...new Set(headline)]

  for (const key of [...UNIVERSAL_FIGURES, 'revenue', 'roas']) {
    if (out.includes(key) || !answers(key)) continue

    /*
      The one door a withheld result could walk back through.

      `answers()` asks whether the BAG holds a number, and for an unattributed campaign it does —
      the platform sent `revenue: 0`. The server dropped it from `headline` precisely because that
      zero is «not at this grain» rather than a result, and appending it here would undo that
      decision in the browser, which cannot see the campaign's own figures to make it.

      Delivery figures are untouched: impressions and clicks are reported per creative by every
      platform that reports creatives at all, and hiding them would make the card emptier than the
      truth requires.
    */
    if (resultsNotAttributable && RESULT_FIGURES.has(key)) continue

    out.push(key)
  }

  return out
}
