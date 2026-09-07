import { expect, test } from '@playwright/test'
import { AUTH, seededProject, selectProject } from './helpers'

/**
 * CROSS-PLATFORM-ATTRIBUTION-DEPTH-001 — a rate is recomputed from the totals, never averaged.
 *
 * ## Why this is asked of the SCREEN
 *
 * `MetricsAggregator::withDerived()` recomputes every ratio from the summed numerator and
 * denominator, and `CrossProviderTotalsAreTruthfulTest` holds that where it is computed. What
 * neither can say is whether the figure a reader is shown came from there. A surface that summed or
 * averaged the per-platform ratios on its way to the card would satisfy both and still print a
 * number that is not the account's CTR.
 *
 * So this reads what is on the card and checks it against the account's own totals, taken from the
 * same page. Two campaigns at 1% and 5% do not make an account at 3%: the account's CTR is its
 * clicks over its impressions, and on any real estate those two answers differ.
 *
 * ## Why the exact figures rather than the displayed ones
 *
 * The cards abbreviate — «6.6M» cannot be divided. The exact value travels as the `title` precisely
 * so a figure stays auditable, which is what this does with it.
 */
const STORE_PROJECT = 'متجر تجريبي — Demo'

/**
 * The card's underlying figure.
 *
 * A card reads `label | change% | value`, so the FIRST number in it is the movement against the
 * previous period, not the measurement. The first draft of this took that one and reported the
 * revenue card as saying «12» — the product was right and the reader of it was wrong.
 *
 * The value is the last line. Where the card carries an exact `title` — «804,803 SAR» behind
 * «805K SAR» — that is preferred, because a compacted figure cannot be multiplied and the product
 * already keeps the auditable form one hover away for exactly this reason.
 */
async function figure(page: import('@playwright/test').Page, key: string): Promise<number | null> {
  const card = page.getByTestId(`metric-${key}`)

  if (!(await card.count())) return null

  return card.first().evaluate((el) => {
    const numeric = (text: string): number | null => {
      const match = text.replace(/[٬,]/g, '').match(/-?\d+(\.\d+)?/)

      return match ? Number(match[0]) : null
    }

    /* An exact title, where the card has one: it is the same figure without the abbreviation. */
    for (const t of el.querySelectorAll('[title]')) {
      const title = t.getAttribute('title') ?? ''

      if (/\d/.test(title)) return numeric(title)
    }

    const lines = (el as HTMLElement).innerText.split('\n').map((l) => l.trim()).filter((l) => l !== '')

    return lines.length > 0 ? numeric(lines[lines.length - 1]) : null
  })
}

test.describe('a rate on a card is the account’s own', () => {
  test.use({ storageState: AUTH.owner })

  /**
   * The four cards a sales objective leads with form a closed identity.
   *
   * `roas = revenue ÷ spend` and `cpa = spend ÷ purchases`, so `roas × cpa × purchases = revenue`.
   * Spend cancels, and the identity holds ONLY if every one of the four was computed from the same
   * aggregate numerator and denominator.
   *
   * That is what makes this worth asserting on the screen. A surface that averaged the per-platform
   * ROAS, or summed the per-campaign CPAs, would still print four plausible numbers — and they would
   * stop multiplying out to the revenue sitting beside them. Two campaigns at 3× and 5× do not make
   * an account at 4×, and this is the arithmetic that notices.
   *
   * The exact figures are read from the `title` the cards carry for precisely this reason: «40.2K»
   * cannot be multiplied, and the product already keeps the auditable form one hover away.
   */
  test('the objective KPIs multiply out to the revenue beside them', async ({ page, request }) => {
    test.setTimeout(120_000)

    await selectProject(page, await seededProject(request, STORE_PROJECT))
    await page.goto('/agency/analytics')
    await expect(page.locator('main')).toBeVisible({ timeout: 30000 })

    await expect(page.getByTestId('metric-revenue').first()).toBeVisible({ timeout: 30000 })
    await page.waitForTimeout(1500)

    const revenue = await figure(page, 'revenue')
    const roas = await figure(page, 'roas')
    const cpa = await figure(page, 'cpa')
    const purchases = await figure(page, 'purchases')

    /* The estate has to be real enough for the question to have an answer. */
    for (const [name, value] of [['revenue', revenue], ['roas', roas], ['cpa', cpa], ['purchases', purchases]] as const) {
      expect(value, `${name} is not on the card, so nothing here is being checked`).not.toBeNull()
      expect(value ?? 0, `${name} is zero, which makes the identity vacuous`).toBeGreaterThan(0)
    }

    const impliedRevenue = (roas ?? 0) * (cpa ?? 0) * (purchases ?? 0)

    /*
     * One per cent. The cards round — ROAS to two decimals, CPA to two — so a few tenths of drift is
     * arithmetic rather than a defect. An AVERAGED ratio misses by whole multiples on any real
     * estate, so this is tight enough to catch the thing it is here for and loose enough not to
     * accuse the product of rounding.
     */
    const drift = Math.abs(impliedRevenue - (revenue ?? 0)) / (revenue ?? 1)

    expect(
      drift,
      `roas ${roas} × cpa ${cpa} × purchases ${purchases} = ${impliedRevenue.toFixed(2)}, `
        + `but the revenue card says ${revenue}. A ratio that was averaged rather than recomputed `
        + 'would look exactly like this.',
    ).toBeLessThan(0.01)
  })
})
