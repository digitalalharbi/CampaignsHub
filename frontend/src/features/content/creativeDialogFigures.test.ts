import { describe, expect, it } from 'vitest'
import { creativeDialogFigures } from './creativeDialogFigures'
import { rowCostPer, rowRoas } from '@/features/analytics/format'

/**
 * AD-PREVIEW-FIGURES-001 — a cost is money and a return is a multiple. Neither is a percentage.
 *
 * ## What production showed
 *
 * On the Analytics content table's dialog: «CPM 65.65%» and «CPC 125.38%». The figures are a cost
 * per thousand and a cost per click — amounts of money — printed by `rateOrDash`, which multiplies
 * by a hundred and appends a percent sign. A cost per click of 1.2538 USD read as «125.38%».
 *
 * ROAS went the same way: a return of 4.2× printed as «420.00%».
 *
 * ## Why this was invisible
 *
 * The comment above those lines gave a reason — that `rowMoney` reads the money contract's
 * spend/revenue envelope and «a cost-per is a derived ratio rather than an amount with its own
 * withheld provenance». The premise is true and the conclusion does not follow: `rowCostPer` and
 * `rowRoas` exist for exactly those two shapes, and the same file already calls them on every table
 * row. Only the DIALOG reached for the percentage reader.
 *
 * Which makes it the one thing the dialog's own docblock promises cannot happen — «the modal cannot
 * disagree with the line the reader clicked». The reader clicked a row saying «1.25 USD» and opened
 * a panel saying «125.38%» about the same click.
 *
 * ## Why this is a module and not an inline array
 *
 * The figure list is a product rule — which figures, in which order, read by which reader — and it
 * sat inline in a three-thousand-line page where nothing could reach it. Every case below asserts
 * against the SAME reader the table row uses, so the two cannot drift apart again without one of
 * these failing.
 */
const metrics = (over: Record<string, unknown> = {}) => ({
  spend: 3000,
  impressions: 120_000,
  clicks: 3400,
  conversions: 88,
  ctr: 0.0283,
  cpc: 1.2538,
  cpm: 0.6565,
  ...over,
})

const find = (figures: { label: string; value: string }[], label: string) =>
  figures.find((f) => f.label === label)?.value

describe('the figures a creative’s dialog carries', () => {
  it('prints a cost per click as money, never as a percentage', () => {
    const figures = creativeDialogFigures(metrics(), 'USD', false)

    expect(find(figures, 'CPC')).not.toMatch(/%/)
    expect(find(figures, 'CPC')).toBe(rowCostPer(metrics(), 'cpc', 3400, 'USD'))
  })

  it('prints a cost per thousand as money, never as a percentage', () => {
    const figures = creativeDialogFigures(metrics(), 'USD', false)

    expect(find(figures, 'CPM')).not.toMatch(/%/)
    expect(find(figures, 'CPM')).toBe(rowCostPer(metrics(), 'cpm', 120, 'USD'))
  })

  /** A return is a multiple: «4.20×». A return printed as «420.00%» is the same defect in a third shape. */
  it('prints a return on spend as a multiple', () => {
    const row = metrics({ revenue: 12_600, roas: 4.2 })
    const figures = creativeDialogFigures(row, 'USD', false)

    expect(find(figures, 'ROAS')).toBe(rowRoas(row))
    expect(find(figures, 'ROAS')).toContain('×')
  })

  /** CTR IS a rate, and stays one — the fix is not «stop using percentages». */
  it('still prints a click-through rate as a percentage', () => {
    expect(find(creativeDialogFigures(metrics(), 'USD', false), 'CTR')).toMatch(/%$/)
  })

  /**
   * Revenue and ROAS appear only where the provider sent them.
   *
   * A brand campaign has no revenue, and «الإيرادات —» on every awareness creative in an account
   * teaches a reader to skip the row.
   */
  it('omits revenue and return entirely when neither was reported', () => {
    const figures = creativeDialogFigures(metrics(), 'USD', false)

    expect(find(figures, 'Revenue')).toBeUndefined()
    expect(find(figures, 'ROAS')).toBeUndefined()
  })

  /** A figure the provider never sent is «—», never a zero with a unit on it. */
  it('states an unreported cost as absent rather than as free', () => {
    const figures = creativeDialogFigures(metrics({ cpc: null, cpm: null }), 'USD', false)

    expect(find(figures, 'CPC')).toBe('—')
    expect(find(figures, 'CPM')).toBe('—')
  })

  /**
   * AD-PREVIEW-FIGURES-002 — a cost cannot survive the refusal of the spend it is made of.
   *
   * ## The owner's screenshot
   *
   * «الإنفاق —» in the same panel as «CPM 65.65%» and «CPC 125.38%». A cost per click IS spend
   * divided by clicks: if the panel will not state the spend, it cannot state what the spend bought
   * per click and call the two consistent.
   *
   * It happened because the two came from different places. Spend went through the money contract,
   * which refuses a scope whose currencies cannot be added — a real refusal, correctly made. CPC and
   * CPM were read straight off the row as plain numbers, so the refusal never reached them.
   *
   * ## Two scopes, because they refuse for different reasons
   *
   * `mixed_currency` is «these amounts are in different currencies and no rate converts them».
   * `partial` is «some of this scope converted and some did not», where stating the converted subset
   * would understate the total. Both must take the derived figures down with them.
   */
  it('withholds a cost per click when the spend it divides was refused', () => {
    const mixed = metrics({
      spend: 0,
      spend_withheld_rows: 4,
      spend_original: 3000,
      money_original_currency: null,
      money_original_currencies: 2,
    })
    const figures = creativeDialogFigures(mixed, 'USD', false)

    expect(find(figures, 'Spend')).not.toMatch(/^\d/)
    expect(find(figures, 'CPC'), 'a cost survived the refusal of its own numerator').toBe('—')
    expect(find(figures, 'CPM')).toBe('—')
  })

  it('withholds them on a partly convertible scope too', () => {
    const partial = metrics({
      spend: 1200,
      spend_withheld_rows: 2,
      spend_original: 900,
      money_original_currency: 'AED',
      money_original_currencies: 1,
    })
    const figures = creativeDialogFigures(partial, 'USD', false)

    expect(find(figures, 'CPC')).toBe('—')
    expect(find(figures, 'CPM')).toBe('—')
  })

  /**
   * And the CTR is untouched, because it is not made of money.
   *
   * Clicks over impressions needs no exchange rate, and blanking it alongside the costs would be the
   * opposite error — withholding a figure that is perfectly well known.
   */
  it('keeps a click-through rate through a money refusal', () => {
    const mixed = metrics({
      spend: 0,
      spend_withheld_rows: 4,
      spend_original: 3000,
      money_original_currencies: 2,
    })

    expect(find(creativeDialogFigures(mixed, 'USD', false), 'CTR')).toMatch(/%$/)
  })

  /** Arabic labels the money figures in Arabic and leaves the acronyms alone — they are read as-is. */
  it('labels in the reader’s language', () => {
    const figures = creativeDialogFigures(metrics(), 'USD', true)

    expect(find(figures, 'الإنفاق')).toBeDefined()
    expect(find(figures, 'CPC')).toBeDefined()
  })
})
